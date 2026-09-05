<?php

declare(strict_types=1);

use App\Enums\AchievementCriterion;
use App\Enums\LearningEventType;
use App\Enums\TopicType;
use App\Jobs\AwardAchievements;
use App\Models\Achievement;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningEvent;
use App\Models\LearningMastery;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\Progress\GamificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| XP, levels, badges and streaks (PRD §16).
|
| The property this file exists to prove is that nothing is incremented. XP is
| recomputed from the ledger on every run, so a retried job cannot inflate it,
| and badges are protected by UNIQUE(user_id, achievement_id) rather than by a
| check that a race could lose.
|
| There is no leaderboard test because there is no leaderboard: PRD §16 rules
| one out for the MVP, and no method here takes two students.
*/

function fixedNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-01 12:00:00');
}

function correctAttempt(User $student, bool $hint = false): Attempt
{
    $attempt = Attempt::factory()->for($student)->for(Question::factory()->published())->create([
        'hint_used' => $hint,
    ]);

    $attempt->is_correct = true;
    $attempt->save();

    return $attempt;
}

function completeALesson(User $student): LessonProgress
{
    return LessonProgress::query()->create([
        'user_id' => $student->getKey(),
        'lesson_id' => Lesson::factory()->published()->create()->getKey(),
        'status' => 'completed',
        'progress_percent' => 100,
        'completed_at' => fixedNow(),
    ]);
}

beforeEach(function (): void {
    $this->service = app(GamificationService::class);
    $this->student = User::factory()->create();
});

it('awards XP for correct answers and completed lessons', function (): void {
    correctAttempt($this->student);
    correctAttempt($this->student);
    correctAttempt($this->student, hint: true);
    completeALesson($this->student);

    // 2 × 10 unaided + 1 × 5 hinted + 1 × 50 lesson = 75
    expect($this->service->calculateXp($this->student->fresh()))->toBe(75);
});

it('awards nothing for a wrong answer', function (): void {
    Attempt::factory()->for($this->student)->for(Question::factory()->published())->create();

    expect($this->service->calculateXp($this->student))->toBe(0);
});

it('recomputes XP rather than incrementing it', function (): void {
    correctAttempt($this->student);

    $this->service->sync($this->student, fixedNow());
    $first = $this->student->fresh()->xp;

    // The same job, three more times. An `xp += 10` anywhere would show here,
    // and would be invisible in production until a student noticed.
    $this->service->sync($this->student, fixedNow());
    $this->service->sync($this->student, fixedNow());
    $this->service->sync($this->student, fixedNow());

    expect($this->student->fresh()->xp)->toBe($first);
});

it('derives the level from the XP curve', function (int $xp, int $level): void {
    expect($this->service->levelFor($xp))->toBe($level);
})->with([
    'no xp at all' => [0, 1],
    'not quite level two' => [99, 1],
    'level two begins at 100' => [100, 2],
    'level three begins at 400' => [400, 3],
    'level five begins at 1600' => [1_600, 5],
    'each level costs more than the last' => [8_100, 10],
]);

it('reports how far into the current level a student is', function (): void {
    $this->student->xp = 150;
    $this->student->save();

    $summary = $this->service->summarise($this->student, fixedNow());

    // Level 2 spans 100–400, so 150 XP is 50 into a 300-point level.
    expect($summary->level)->toBe(2)
        ->and($summary->xpIntoLevel)->toBe(50)
        ->and($summary->xpForLevel)->toBe(300);
});

/*
| Badges. One test per criterion type, because each is a different query.
*/

it('awards a badge for exploring a named organ', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);

    Achievement::factory()->create([
        'slug' => 'heart-explorer',
        'criteria' => ['type' => AchievementCriterion::OrganExplored->value, 'slug' => 'heart'],
    ]);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty();

    LearningEvent::factory()
        ->for($this->student)
        ->ofType(LearningEventType::OrganViewed)
        ->about('organ', (int) $organ->getKey())
        ->create();

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toHaveCount(1);
});

it('counts distinct structures identified, not attempts', function (): void {
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::StructuresIdentified->value, 'count' => 2],
    ]);

    // One structure answered correctly a hundred times is one structure. The
    // same principle as the unweighted rollup (docs/architecture.md §10).
    LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::Structure, 1)
        ->create(['attempts' => 100, 'correct_attempts' => 100]);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty();

    LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::Structure, 2)
        ->create(['attempts' => 1, 'correct_attempts' => 1]);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toHaveCount(1);
});

it('awards a badge for completed lessons', function (): void {
    $achievement = Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::LessonsCompleted->value, 'count' => 1],
    ]);

    expect(UserAchievement::query()->forUser($this->student)->count())->toBe(0);

    // Completing the lesson is enough on its own: LessonProgressObserver
    // dispatches AwardAchievements, so the badge does not wait for the
    // student's next answer.
    completeALesson($this->student);

    expect(UserAchievement::query()->forUser($this->student)->sole()->achievement_id)
        ->toBe($achievement->getKey());
});

it('awards a badge for reaching a mastery threshold', function (): void {
    $system = BodySystem::factory()->create(['slug' => 'respiratory']);

    Achievement::factory()->create([
        'criteria' => [
            'type' => AchievementCriterion::MasteryAtLeast->value,
            'topic_type' => 'system',
            'slug' => 'respiratory',
            'score' => 70,
        ],
    ]);

    $row = LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::System, (int) $system->getKey())
        ->scoring(69.99)
        ->create();

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty();

    $row->update(['mastery_score' => 70.0]);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toHaveCount(1);
});

it('awards a badge for a streak', function (): void {
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::StreakDays->value, 'days' => 3],
    ]);

    foreach ([0, 1, 2] as $daysAgo) {
        LearningEvent::factory()
            ->for($this->student)
            ->occurredAt(fixedNow()->subDays($daysAgo))
            ->create();
    }

    expect($this->service->streakDays($this->student, fixedNow()))->toBe(3)
        ->and($this->service->sync($this->student, fixedNow())->newlyEarned)->toHaveCount(1);
});

it('counts a streak that ends yesterday', function (): void {
    // Breaking a streak at midnight would punish a timezone rather than a
    // lapse.
    foreach ([1, 2] as $daysAgo) {
        LearningEvent::factory()
            ->for($this->student)
            ->occurredAt(fixedNow()->subDays($daysAgo))
            ->create();
    }

    expect($this->service->streakDays($this->student, fixedNow()))->toBe(2);
});

it('breaks a streak on a missed day', function (): void {
    foreach ([0, 1, 3, 4] as $daysAgo) {
        LearningEvent::factory()
            ->for($this->student)
            ->occurredAt(fixedNow()->subDays($daysAgo))
            ->create();
    }

    expect($this->service->streakDays($this->student, fixedNow()))->toBe(2);
});

it('has no streak after a lapse', function (): void {
    LearningEvent::factory()
        ->for($this->student)
        ->occurredAt(fixedNow()->subDays(5))
        ->create();

    expect($this->service->streakDays($this->student, fixedNow()))->toBe(0);
});

it('never awards the same badge twice', function (): void {
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::LessonsCompleted->value, 'count' => 1],
    ]);

    completeALesson($this->student);

    $this->service->sync($this->student, fixedNow());
    $this->service->sync($this->student, fixedNow());
    $this->service->sync($this->student, fixedNow());

    expect(UserAchievement::query()->forUser($this->student)->count())->toBe(1);
});

it('ignores a criterion it does not understand', function (): void {
    // Handover 13 will let an admin edit this JSON. A typo must cost a badge
    // nobody can win, not a 500 on every student's dashboard.
    Achievement::factory()->create(['criteria' => ['type' => 'whatever_i_typed']]);
    Achievement::factory()->create(['criteria' => []]);
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::StreakDays->value],
    ]);

    completeALesson($this->student);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty();
});

it('ignores a criterion naming content that does not exist', function (): void {
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::OrganExplored->value, 'slug' => 'gizzard'],
    ]);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty();
});

it('counts a badge toward XP', function (): void {
    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::LessonsCompleted->value, 'count' => 1],
    ]);

    completeALesson($this->student);

    // Awarded before the recompute, so the badge's own XP lands in the same
    // run rather than a level behind: 50 for the lesson + 25 for the badge.
    $summary = $this->service->sync($this->student, fixedNow());

    expect($summary->xp)->toBe(75);
});

it('scopes badges and XP to one student', function (): void {
    $other = User::factory()->create();

    Achievement::factory()->create([
        'criteria' => ['type' => AchievementCriterion::LessonsCompleted->value, 'count' => 1],
    ]);

    completeALesson($other);

    expect($this->service->sync($this->student, fixedNow())->newlyEarned)->toBeEmpty()
        ->and($this->student->fresh()->xp)->toBe(0);
});

it('runs from a queued job on the default queue', function (): void {
    $job = new AwardAchievements(userId: (int) $this->student->getKey());

    expect($job->queue)->toBe('default');

    correctAttempt($this->student);

    $job->handle($this->service);

    expect($this->student->fresh()->xp)->toBe(10);
});

it('reaches the dashboard payload', function (): void {
    Carbon::setTestNow(fixedNow());

    $organ = Organ::factory()->published()->create();
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();
    $question = Question::factory()->published()->spatial($structure)->create();

    Achievement::factory()->create([
        'slug' => 'first-answer',
        'name' => 'First Answer',
        'criteria' => ['type' => AchievementCriterion::StructuresIdentified->value, 'count' => 1],
    ]);

    // Through the real endpoint, so the whole chain runs: attempt →
    // RecalculateMastery → AwardAchievements → dashboard.
    $this->actingAs($this->student)
        ->postJson("/api/v1/quizzes/{$organ->slug}/attempt", [
            'questionId' => $question->getKey(),
            'selectedStructureId' => $structure->getKey(),
        ])
        ->assertOk();

    // `fresh()`: actingAs binds the in-memory instance, and the queued job
    // wrote XP to the row rather than to this object. A real request resolves
    // the user from the session guard, which reads the row.
    $this->actingAs($this->student->fresh())
        ->getJson('/api/v1/progress')
        ->assertOk()
        ->assertJsonPath('data.gamification.xp', 35)
        ->assertJsonPath('data.gamification.level', 1)
        ->assertJsonPath('data.gamification.streakDays', 1)
        ->assertJsonPath('data.gamification.achievements.0.slug', 'first-answer');

    Carbon::setTestNow();
});
