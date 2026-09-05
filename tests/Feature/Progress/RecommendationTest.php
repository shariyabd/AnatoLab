<?php

declare(strict_types=1);

use App\Enums\MasteryFactor;
use App\Enums\RecommendedActivity;
use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningMastery;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Services\Progress\RecommendationService;
use Carbon\CarbonImmutable;

/*
| "What should I do next?" (docs/architecture.md §10, PRD §15).
|
| Lowest-mastery published topic with unattempted content, tie-broken toward
| the organ the student is looking at, with a reason templated from the mastery
| breakdown. The last clause is a hard guardrail: the LLM writes no part of a
| recommendation reason, and nothing in this lane calls an AI service.
*/

function moment(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-01 12:00:00');
}

/** A published organ with one published question nobody has answered. */
function quizzableOrgan(string $slug, ?BodySystem $system = null): Organ
{
    $organ = Organ::factory()
        ->published()
        ->for($system ?? BodySystem::factory())
        ->create(['slug' => $slug, 'name' => ucfirst($slug)]);

    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();
    Question::factory()->published()->spatial($structure)->create();

    return $organ;
}

function scoreOrgan(User $student, Organ $organ, float $score, array $counts = []): LearningMastery
{
    return LearningMastery::factory()
        ->for($student)
        ->forTopic(TopicType::Organ, (int) $organ->getKey())
        ->scoring($score)
        ->create([
            'attempts' => 10,
            'correct_attempts' => 9,
            'hinted_attempts' => 0,
            'covered_structures' => 1,
            'total_structures' => 1,
            'last_activity_at' => moment(),
            ...$counts,
        ]);
}

beforeEach(function (): void {
    $this->service = app(RecommendationService::class);
    $this->student = User::factory()->create();
});

it('recommends nothing when nothing is published', function (): void {
    expect($this->service->recommend($this->student, moment()))->toBeNull();
});

it('picks the weakest topic with content left', function (): void {
    $strong = quizzableOrgan('heart');
    $weak = quizzableOrgan('lungs');

    scoreOrgan($this->student, $strong, 88.0);
    scoreOrgan($this->student, $weak, 31.0);

    $recommendation = $this->service->recommend($this->student, moment());

    expect($recommendation?->organ->slug)->toBe('lungs')
        ->and($recommendation?->score)->toBe(31.0);
});

it('treats a topic with no mastery row as the weakest of all', function (): void {
    $touched = quizzableOrgan('heart');
    quizzableOrgan('lungs');

    scoreOrgan($this->student, $touched, 12.0);

    // A system never opened is the weakest thing there is: it scores zero,
    // which is below any topic with even one wrong answer behind it.
    expect($this->service->recommend($this->student, moment())?->organ->slug)->toBe('lungs');
});

it('skips a topic whose content is exhausted', function (): void {
    $finished = quizzableOrgan('heart');
    $open = quizzableOrgan('lungs');

    scoreOrgan($this->student, $finished, 5.0);
    scoreOrgan($this->student, $open, 90.0);

    // Every question on the heart has been answered. Recommending it would be
    // a dead end that looks like advice, so the stronger organ wins.
    foreach (Question::query()->where('organ_id', $finished->getKey())->get() as $question) {
        Attempt::factory()->for($this->student)->for($question)->create();
    }

    expect($this->service->recommend($this->student, moment())?->organ->slug)->toBe('lungs');
});

it('breaks a tie toward the organ the student is looking at', function (): void {
    quizzableOrgan('aorta');
    quizzableOrgan('zygoma');

    // Neither has been attempted, so both score zero. Without the hint the
    // stable ordering by name would pick the aorta.
    expect($this->service->recommend($this->student, moment())?->organ->slug)->toBe('aorta')
        ->and($this->service->recommend($this->student, moment(), 'zygoma')?->organ->slug)
        ->toBe('zygoma');
});

it('ignores a current-organ hint that names nothing published', function (): void {
    quizzableOrgan('aorta');

    expect($this->service->recommend($this->student, moment(), 'not-an-organ')?->organ->slug)
        ->toBe('aorta');
});

it('is stable across repeated calls', function (): void {
    quizzableOrgan('aorta');
    quizzableOrgan('lungs');
    quizzableOrgan('heart');

    $slugs = array_map(
        fn (): ?string => $this->service->recommend($this->student, moment())?->organ->slug,
        range(1, 5),
    );

    // A recommendation that changes on refresh is a recommendation nobody
    // trusts.
    expect(array_unique($slugs))->toHaveCount(1);
});

it('prefers an unfinished lesson to a quiz', function (): void {
    $organ = quizzableOrgan('heart');
    $lesson = Lesson::factory()->published()->for($organ)->create(['slug' => 'heart-basics']);

    $recommendation = $this->service->recommend($this->student, moment());

    // PRD §8 puts the explanation before the assessment; a student at low
    // mastery needs teaching, not more questions.
    expect($recommendation?->activity)->toBe(RecommendedActivity::Lesson)
        ->and($recommendation?->slug)->toBe($lesson->slug)
        ->and($recommendation?->href())->toBe('/lessons/heart-basics');
});

it('falls back to the quiz once the lessons are done', function (): void {
    $organ = quizzableOrgan('heart');
    $lesson = Lesson::factory()->published()->for($organ)->create();

    LessonProgress::query()->create([
        'user_id' => $this->student->getKey(),
        'lesson_id' => $lesson->getKey(),
        'status' => 'completed',
        'progress_percent' => 100,
        'completed_at' => moment(),
    ]);

    $recommendation = $this->service->recommend($this->student, moment());

    expect($recommendation?->activity)->toBe(RecommendedActivity::Quiz)
        ->and($recommendation?->href())->toBe('/quizzes/heart');
});

it('offers an unfinished lesson rather than a new one', function (): void {
    $organ = quizzableOrgan('heart');
    $lesson = Lesson::factory()->published()->for($organ)->create(['slug' => 'half-read']);

    LessonProgress::query()->create([
        'user_id' => $this->student->getKey(),
        'lesson_id' => $lesson->getKey(),
        'status' => 'in_progress',
        'progress_percent' => 40,
    ]);

    // Picking up where they stopped beats starting something new.
    expect($this->service->recommend($this->student, moment())?->slug)->toBe('half-read');
});

/*
| The reason. Templated from the breakdown, never generated (§10).
*/

it('explains a topic with no evidence yet', function (): void {
    quizzableOrgan('heart');

    $recommendation = $this->service->recommend($this->student, moment());

    expect($recommendation?->reason)->toContain('not answered anything on the Heart')
        // No factor is weakest when every factor sits at its prior; naming one
        // would be a guess dressed up as a diagnosis.
        ->and($recommendation?->weakestFactor)->toBeNull();
});

it('names the weakest factor in the reason', function (array $counts, MasteryFactor $expected): void {
    $organ = quizzableOrgan('heart');
    scoreOrgan($this->student, $organ, 40.0, $counts);

    $recommendation = $this->service->recommend($this->student, moment());

    expect($recommendation?->weakestFactor)->toBe($expected)
        ->and($recommendation?->reason)->toBe($expected->reason());
})->with([
    'wrong more often than right' => [
        ['attempts' => 20, 'correct_attempts' => 4, 'covered_structures' => 1, 'total_structures' => 1],
        MasteryFactor::Accuracy,
    ],
    'leaning on hints' => [
        [
            'attempts' => 20, 'correct_attempts' => 20, 'hinted_attempts' => 20,
            'covered_structures' => 1, 'total_structures' => 1,
        ],
        MasteryFactor::HintReliance,
    ],
    'barely explored' => [
        [
            'attempts' => 20, 'correct_attempts' => 20,
            'covered_structures' => 1, 'total_structures' => 20,
        ],
        MasteryFactor::Coverage,
    ],
    'gone stale' => [
        [
            'attempts' => 20, 'correct_attempts' => 20, 'covered_structures' => 1,
            'total_structures' => 1, 'last_activity_at' => '2026-01-01 12:00:00',
        ],
        MasteryFactor::Recency,
    ],
]);

it('reaches the dashboard as prose with a link', function (): void {
    $organ = quizzableOrgan('heart');
    Lesson::factory()->published()->for($organ)->create(['slug' => 'heart-basics', 'title' => 'The heart']);

    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/recommendations')
        ->assertOk()
        ->assertJsonPath('data.organSlug', 'heart')
        ->assertJsonPath('data.activity', 'lesson')
        ->assertJsonPath('data.title', 'The heart')
        ->assertJsonPath('data.href', '/lessons/heart-basics')
        ->assertJsonPath('data.weakestFactor', null);
});

it('answers with data null rather than a 404 when there is nothing to do', function (): void {
    // "Nothing left" is a real answer to a question asked correctly. A 404
    // would make the dashboard render an error where it should render an empty
    // card.
    $this->actingAs($this->student)
        ->getJson('/api/v1/progress/recommendations')
        ->assertOk()
        ->assertExactJson(['data' => null]);
});
