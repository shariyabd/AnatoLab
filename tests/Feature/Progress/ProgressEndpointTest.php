<?php

declare(strict_types=1);

use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;

/*
| The three read endpoints (docs/architecture.md §7).
|
| The property worth guarding hardest is scoping: none of these routes takes a
| user parameter anywhere, so the only progress they can return is the
| authenticated student's. The tests below prove the data actually follows the
| session and not some other row that happens to be in the table.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->other = User::factory()->create();

    $this->system = BodySystem::factory()->create(['slug' => 'cardiovascular', 'name' => 'Cardiovascular']);
    $this->organ = Organ::factory()->published()->for($this->system)->create(['slug' => 'heart', 'name' => 'Heart']);
    $this->structure = AnatomicalStructure::factory()->published()->for($this->organ)->create();
});

/** A graded answer, filed the way AssessmentService files one. */
function graded(User $student, AnatomicalStructure $structure, bool $correct): void
{
    $question = Question::factory()->published()->spatial($structure)->create();

    $attempt = Attempt::factory()->for($student)->for($question)->create([
        'selected_structure_id' => $structure->getKey(),
    ]);

    $attempt->is_correct = $correct;
    $attempt->save();
}

it('requires authentication', function (string $path): void {
    $this->getJson($path)->assertUnauthorized();
})->with([
    '/api/v1/progress',
    '/api/v1/progress/systems',
    '/api/v1/progress/recommendations',
]);

it('returns a summary shaped like PRD §18', function (): void {
    graded($this->student, $this->structure, correct: true);

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'overallScore',
                'systems' => [['id', 'slug', 'name', 'score', 'attempts', 'coveredStructures']],
                'strongest',
                'needsPractice',
                'lessonsCompleted',
                'quiz' => ['attempts', 'correctAttempts', 'accuracyPercent'],
                'gamification' => ['xp', 'level', 'streakDays', 'achievements'],
                'recentActivity',
                'recommendation',
            ],
        ]);
});

it('lists every published system, including the untouched ones', function (): void {
    // PRD §15's table is the whole body. A system missing from it is
    // indistinguishable from one that does not exist.
    $nervous = BodySystem::factory()->create(['slug' => 'nervous', 'name' => 'Nervous']);
    Organ::factory()->published()->for($nervous)->create();

    graded($this->student, $this->structure, correct: true);

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/systems')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.slug', 'nervous')
        // 0, not 0.0: JSON has one number type and a whole score decodes as an
        // int, which assertJsonPath compares strictly.
        ->assertJsonPath('data.1.score', 0)
        ->assertJsonPath('data.1.attempts', 0);
});

it('leaves out a system with no published content', function (): void {
    BodySystem::factory()->create(['slug' => 'endocrine']);
    // Its only organ is a draft, so a percentage would describe nothing.
    Organ::factory()->for(BodySystem::query()->where('slug', 'endocrine')->sole())->create();

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/systems')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'cardiovascular');
});

it('returns the authenticated student progress and never another one', function (): void {
    LearningMastery::factory()
        ->for($this->other)
        ->forTopic(TopicType::System, (int) $this->system->getKey())
        ->scoring(99.0)
        ->create(['attempts' => 40, 'correct_attempts' => 40]);

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/systems')
        ->assertOk()
        ->assertJsonPath('data.0.score', 0)
        ->assertJsonPath('data.0.attempts', 0);
});

it('reads the stored score rather than recomputing one', function (): void {
    // A row that no formula would produce from these counts. If the endpoint
    // recomputed, this number could not survive the round trip — which is the
    // point: the queued job owns the arithmetic (docs/architecture.md §10).
    LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::System, (int) $this->system->getKey())
        ->scoring(63.5)
        ->create(['attempts' => 3, 'correct_attempts' => 1]);

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/systems')
        ->assertOk()
        ->assertJsonPath('data.0.score', 63.5);
});

it('averages overall mastery over the systems actually worked in', function (): void {
    $nervous = BodySystem::factory()->create(['slug' => 'nervous']);
    Organ::factory()->published()->for($nervous)->create();

    LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::System, (int) $this->system->getKey())
        ->scoring(80.0)
        ->create(['attempts' => 10, 'correct_attempts' => 8]);

    // The untouched nervous system is listed at 0% but does not drag the
    // headline number down — publishing new content is not something the
    // student did.
    $this->actingAs($this->student)
        ->getJson('/api/v1/progress')
        ->assertOk()
        ->assertJsonPath('data.overallScore', 80)
        ->assertJsonPath('data.strongest.slug', 'cardiovascular')
        ->assertJsonPath('data.needsPractice.slug', 'cardiovascular');
});

it('carries no answer key', function (): void {
    graded($this->student, $this->structure, correct: false);

    $response = $this->actingAs($this->student)->getJson('/api/v1/progress')->assertOk();

    // The activity feed describes a graded answer, so this is the payload most
    // likely to leak one by accident (invariant 4).
    expect($response->json())->toCarryNoAnswerKey();
});

it('describes activity without quoting the question', function (): void {
    $question = Question::factory()->published()->create([
        'organ_id' => $this->organ->getKey(),
        'question' => 'Which chamber pumps blood into the aorta?',
    ]);

    Attempt::factory()->for($this->student)->for($question)->create();

    $response = $this->actingAs($this->student)->getJson('/api/v1/progress')->assertOk();

    expect($response->json('data.recentActivity.0.label'))->toBe('Answered a question')
        ->and($response->getContent())->not->toContain('pumps blood into the aorta');
});

it('resolves an activity context to a name', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', [
            'events' => [[
                'type' => 'organ_viewed',
                'occurredAt' => now()->toIso8601String(),
                'contextType' => 'organ',
                'contextId' => $this->organ->getKey(),
            ]],
        ])
        ->assertAccepted();

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress')
        ->assertOk()
        ->assertJsonPath('data.recentActivity.0.label', 'Opened an organ · Heart');
});

it('accepts a current-organ hint and ignores one it cannot resolve', function (): void {
    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/recommendations?organ=heart')
        ->assertOk();

    // A stale tab naming an organ that has been unpublished must not 422 the
    // whole dashboard: the hint is a tie-break, not a required parameter.
    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/recommendations?organ=does-not-exist')
        ->assertOk();
});

it('rejects a malformed current-organ hint', function (): void {
    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/recommendations?organ=Heart%20OR%201=1')
        ->assertJsonValidationErrors('organ');
});
