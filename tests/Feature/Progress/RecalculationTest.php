<?php

declare(strict_types=1);

use App\Jobs\AwardAchievements;
use App\Jobs\RecalculateMastery;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Services\Progress\MasteryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;

/*
| Acceptance criteria 1 and 5 of docs/handovers/10-progress-mastery.md:
| answering a question visibly changes mastery, and no mastery computation
| happens synchronously in a web request (docs/architecture.md §10).
|
| The second is the one worth guarding. The test suite runs on the `sync`
| queue, so a job that ran in the request and a job that ran on the queue look
| identical from the outside — Queue::fake() is what tells them apart.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->structure = AnatomicalStructure::factory()->published()->for($this->organ)->create();
    $this->question = Question::factory()->published()->spatial($this->structure)->create();
});

/** The payload RecordAttemptRequest accepts. */
function spatialAnswer(Question $question, AnatomicalStructure $structure): array
{
    return [
        'questionId' => $question->getKey(),
        'selectedStructureId' => $structure->getKey(),
        'timeSpentMs' => 2_400,
    ];
}

it('queues the recalculation rather than running it in the request', function (): void {
    Queue::fake();

    $this->actingAs($this->student)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($this->question, $this->structure))
        ->assertOk();

    Queue::assertPushed(RecalculateMastery::class);

    // The request wrote the attempt and nothing else. If any of the read path,
    // the Resource, or the service had computed a score, a row would be here.
    expect(LearningMastery::query()->count())->toBe(0)
        ->and(Attempt::query()->count())->toBe(1);
});

it('is a queued job on the default queue', function (): void {
    $job = new RecalculateMastery(userId: 1, attemptId: 1);

    // docs/architecture.md §11 allocates RecalculateMastery to `default`, so a
    // slow document ingest on `ingest` can never delay a student's score.
    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->queue)->toBe('default');
});

it('moves mastery off zero on the first answer', function (): void {
    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(0);

    $this->actingAs($this->student)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($this->question, $this->structure))
        ->assertOk();

    // The suite's sync queue runs the job inline, which is what makes this an
    // end-to-end assertion rather than a service call.
    $organ = LearningMastery::query()
        ->forUser($this->student)
        ->where('topic_id', $this->organ->getKey())
        ->where('topic_type', 'organ')
        ->sole();

    expect($organ->score())->toBeGreaterThan(0.0);
});

it('moves mastery further on a second correct answer', function (): void {
    $second = AnatomicalStructure::factory()->published()->for($this->organ)->create();
    $secondQuestion = Question::factory()->published()->spatial($second)->create();

    $this->actingAs($this->student)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($this->question, $this->structure))
        ->assertOk();

    $after = LearningMastery::query()
        ->forUser($this->student)
        ->where('topic_type', 'organ')
        ->sole()
        ->score();

    $this->actingAs($this->student)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($secondQuestion, $second))
        ->assertOk();

    $later = LearningMastery::query()
        ->forUser($this->student)
        ->where('topic_type', 'organ')
        ->sole()
        ->score();

    // Both accuracy and coverage improved, so the number has to move up.
    expect($later)->toBeGreaterThan($after);
});

it('drops a wrong answer to a lower score than a right one', function (): void {
    $wrongAnswerer = User::factory()->create();
    $other = AnatomicalStructure::factory()->published()->for($this->organ)->create();

    $this->actingAs($this->student)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($this->question, $this->structure))
        ->assertOk();

    $this->actingAs($wrongAnswerer)
        ->postJson('/api/v1/quizzes/heart/attempt', spatialAnswer($this->question, $other))
        ->assertOk();

    $right = LearningMastery::query()->forUser($this->student)->where('topic_type', 'organ')->sole();
    $wrong = LearningMastery::query()->forUser($wrongAnswerer)->where('topic_type', 'organ')->sole();

    expect($right->score())->toBeGreaterThan($wrong->score());
});

it('chains the badge evaluation after the recalculation', function (): void {
    Queue::fake();

    // Dispatched from inside the job, so the badges are evaluated against the
    // mastery it has just written rather than the score from before the answer.
    (new RecalculateMastery(userId: (int) $this->student->getKey(), attemptId: 1))
        ->handle(app(MasteryService::class));

    Queue::assertPushed(AwardAchievements::class);
});

it('does nothing for a user who no longer exists', function (): void {
    Queue::fake();

    (new RecalculateMastery(userId: 999_999, attemptId: 1))->handle(app(MasteryService::class));

    expect(LearningMastery::query()->count())->toBe(0);
    Queue::assertNotPushed(AwardAchievements::class);
});

it('recomputes even when the attempt that triggered it is gone', function (): void {
    // The job carries the attempt id for traceability and reads it for nothing:
    // MasteryService rebuilds every topic from what is in the table now, so a
    // deleted attempt is simply no longer counted.
    $attempt = Attempt::factory()->for($this->student)->for($this->question)->create([
        'selected_structure_id' => $this->structure->getKey(),
    ]);

    $attemptId = (int) $attempt->getKey();
    $attempt->delete();

    (new RecalculateMastery(userId: (int) $this->student->getKey(), attemptId: $attemptId))
        ->handle(app(MasteryService::class));

    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(0);
});

it('never computes mastery on a dashboard read', function (): void {
    Attempt::factory()->for($this->student)->for($this->question)->create([
        'selected_structure_id' => $this->structure->getKey(),
    ]);

    // Rows have deliberately not been built: the attempt was inserted directly,
    // so no job ran. Reading must not build them.
    $this->actingAs($this->student)->get('/dashboard')->assertOk();
    $this->actingAs($this->student)->getJson('/api/v1/progress')->assertOk();
    $this->actingAs($this->student)->getJson('/api/v1/progress/systems')->assertOk();
    $this->actingAs($this->student)->getJson('/api/v1/progress/recommendations')->assertOk();

    expect(LearningMastery::query()->count())->toBe(0);
});
