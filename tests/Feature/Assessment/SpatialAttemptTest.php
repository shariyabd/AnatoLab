<?php

declare(strict_types=1);

use App\Jobs\RecalculateMastery;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
| POST /api/v1/quizzes/{quiz}/attempt for the flagship 3D spatial question
| (PRD §12, docs/architecture.md §9).
|
| The property under test throughout: the verdict is derived server-side from
| the question's own answer key, and nothing the client sends can influence it.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->answer = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left Ventricle',
    ]);
    $this->decoy = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'right-atrium',
    ]);
    $this->question = Question::factory()->published()->spatial($this->answer)->create([
        'explanation' => 'It drives the systemic circulation.',
    ]);
});

it('records a correct pick and returns the explanation', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->answer->getKey(),
        'timeSpentMs' => 4_200,
        'hintUsed' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', true)
        ->assertJsonPath('data.correctStructureId', (string) $this->answer->getKey())
        ->assertJsonPath('data.correctOptionId', null)
        ->assertJsonPath('data.explanation', 'It drives the systemic circulation.')
        ->assertJsonPath('data.masteryDelta', null);

    $attempt = Attempt::query()->sole();

    expect($attempt->is_correct)->toBeTrue()
        ->and($attempt->user_id)->toBe($this->student->getKey())
        ->and($attempt->question_id)->toBe($this->question->getKey())
        ->and($attempt->selected_structure_id)->toBe($this->answer->getKey())
        ->and($attempt->time_spent_ms)->toBe(4_200)
        ->and($attempt->hint_used)->toBeFalse()
        // Handover 09's column, untouched by this lane.
        ->and($attempt->mission_attempt_id)->toBeNull();
});

it('records a miss and still names the structure that was right', function (): void {
    // The reveal is what lets the viewer flash the correct marker green — the
    // audited behaviour a miss depends on (docs/project-context.md §2.4).
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->decoy->getKey(),
        'timeSpentMs' => 9_100,
        'hintUsed' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false)
        ->assertJsonPath('data.correctStructureId', (string) $this->answer->getKey());

    $attempt = Attempt::query()->sole();

    expect($attempt->is_correct)->toBeFalse()
        ->and($attempt->selected_structure_id)->toBe($this->decoy->getKey())
        ->and($attempt->time_spent_ms)->toBe(9_100)
        ->and($attempt->hint_used)->toBeTrue();
});

it('files the attempt against the authenticated student, not a request field', function (): void {
    $someoneElse = User::factory()->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->answer->getKey(),
        // Present, and ignored: ownership comes from the session.
        'user_id' => $someoneElse->getKey(),
        'userId' => $someoneElse->getKey(),
    ])->assertOk();

    expect(Attempt::query()->sole()->user_id)->toBe($this->student->getKey());
});

it('cannot be told it was right', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->decoy->getKey(),
        'is_correct' => true,
        'isCorrect' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);

    expect(Attempt::query()->sole()->is_correct)->toBeFalse();
});

it('discards a structure that belongs to another organ', function (): void {
    $elsewhere = AnatomicalStructure::factory()->published()->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $elsewhere->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);

    // Recorded as an attempt, but not as a pick: it was never an answer to
    // this question.
    expect(Attempt::query()->sole()->selected_structure_id)->toBeNull();
});

it('discards an unpublished structure, which has no marker to click', function (): void {
    $hidden = AnatomicalStructure::factory()->for($this->organ)->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $hidden->getKey(),
    ])->assertOk();

    expect(Attempt::query()->sole()->selected_structure_id)->toBeNull();
});

it('queues the mastery recalculation instead of doing it in the request', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->answer->getKey(),
    ])->assertOk();

    $attempt = Attempt::query()->sole();

    Queue::assertPushed(
        RecalculateMastery::class,
        fn (RecalculateMastery $job): bool => $job->userId === $attempt->user_id
            && $job->attemptId === (int) $attempt->getKey(),
    );
});

it('404s for a question that is not in this quiz', function (): void {
    $elsewhere = Question::factory()->published()->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $elsewhere->getKey(),
    ])->assertNotFound();

    expect(Attempt::query()->count())->toBe(0);
});

it('404s for a question awaiting review', function (): void {
    $unreviewed = Question::factory()->inReview()->spatial($this->answer)->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $unreviewed->getKey(),
    ])->assertNotFound();
});

it('rejects a payload with no question', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'selectedStructureId' => $this->answer->getKey(),
    ])->assertUnprocessable()->assertJsonValidationErrors('questionId');
});

it('rejects an implausible time', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedStructureId' => $this->answer->getKey(),
        'timeSpentMs' => 99_999_999,
    ])->assertUnprocessable()->assertJsonValidationErrors('timeSpentMs');
});

it('requires a login', function (): void {
    auth()->logout();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
    ])->assertUnauthorized();
});
