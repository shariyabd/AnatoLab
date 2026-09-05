<?php

declare(strict_types=1);

use App\Models\Attempt;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;

/*
| MCQ attempts take the same route and the same pipeline as spatial ones, which
| is what lets one mastery score consume both (docs/architecture.md §9).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->question = Question::factory()->published()->for($this->organ)->create([
        'explanation' => 'Blood returns from the lungs into the left atrium.',
    ]);
    $this->wrong = QuestionOption::factory()->for($this->question)->create([
        'label' => 'The right atrium',
        'value' => 'ra',
    ]);
    $this->right = QuestionOption::factory()->correct()->for($this->question)->create([
        'label' => 'The left atrium',
        'value' => 'la',
    ]);
});

it('records a correct choice', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedOptionId' => $this->right->getKey(),
        'timeSpentMs' => 3_300,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', true)
        ->assertJsonPath('data.correctOptionId', (string) $this->right->getKey())
        // No structure to flash on an MCQ.
        ->assertJsonPath('data.correctStructureId', null)
        ->assertJsonPath('data.explanation', 'Blood returns from the lungs into the left atrium.');

    $attempt = Attempt::query()->sole();

    expect($attempt->is_correct)->toBeTrue()
        ->and($attempt->selected_option_id)->toBe($this->right->getKey())
        ->and($attempt->time_spent_ms)->toBe(3_300);
});

it('records a wrong choice and names the option that was right', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedOptionId' => $this->wrong->getKey(),
        'hintUsed' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false)
        ->assertJsonPath('data.correctOptionId', (string) $this->right->getKey());

    $attempt = Attempt::query()->sole();

    expect($attempt->is_correct)->toBeFalse()
        ->and($attempt->selected_option_id)->toBe($this->wrong->getKey())
        ->and($attempt->hint_used)->toBeTrue();
});

it('discards an option belonging to a different question', function (): void {
    // It exists, so `exists:` validation would have let it through. It is
    // still not an answer to this question.
    $elsewhere = QuestionOption::factory()->correct()->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'selectedOptionId' => $elsewhere->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);

    expect(Attempt::query()->sole()->selected_option_id)->toBeNull();
});

it('treats an unanswered question as wrong rather than as an error', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);

    expect(Attempt::query()->sole()->is_correct)->toBeFalse();
});
