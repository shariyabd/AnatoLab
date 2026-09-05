<?php

declare(strict_types=1);

use App\Models\Attempt;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;

/*
| Short answers are graded against the authored rubric in `questions.metadata`
| and persisted as ordinary attempts (docs/architecture.md §9). See
| App\Services\Assessment\ShortAnswerGrader for where an AI verdict slots in.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->question = Question::factory()
        ->published()
        ->for($this->organ)
        ->shortAnswer(['left ventricle'])
        ->create();
});

it('accepts an answer that matches the rubric, punctuation and case aside', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'answerText' => 'The Left Ventricle!',
        'timeSpentMs' => 12_000,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', true);

    expect(Attempt::query()->sole()->answer_text)->toBe('The Left Ventricle!');
});

it('rejects an answer the rubric does not cover', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'answerText' => 'the right atrium',
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);
});

it('marks an empty answer wrong rather than accepting it', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'answerText' => '   ',
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);

    expect(Attempt::query()->sole()->answer_text)->toBeNull();
});

it('marks a question with no rubric wrong rather than free-marking it', function (): void {
    $ungradeable = Question::factory()
        ->published()
        ->for($this->organ)
        ->shortAnswer([])
        ->create();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $ungradeable->getKey(),
        'answerText' => 'anything at all',
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false);
});

it('requires every phrase the rubric marks required', function (): void {
    $question = Question::factory()->published()->for($this->organ)->create([
        'type' => App\Enums\QuestionType::ShortAnswer,
        'metadata' => ['rubric' => [
            'accepted' => ['ventricle'],
            'required' => ['left'],
        ]],
    ]);

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $question->getKey(),
        'answerText' => 'right ventricle',
    ])->assertOk()->assertJsonPath('data.isCorrect', false);

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $question->getKey(),
        'answerText' => 'the left ventricle',
    ])->assertOk()->assertJsonPath('data.isCorrect', true);
});

it('rejects an answer longer than the field allows', function (): void {
    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->question->getKey(),
        'answerText' => str_repeat('a', 1_001),
    ])->assertUnprocessable()->assertJsonValidationErrors('answerText');
});
