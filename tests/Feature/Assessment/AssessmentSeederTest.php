<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionOption;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\AssessmentSeeder;

/*
| The demo question bank. The demo is a first-class artefact, so a seeder that
| broke is a broken demo (docs/engineering.md §6).
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
    $this->seed(AssessmentSeeder::class);
});

it('seeds answerable questions for all three organs', function (): void {
    foreach (['heart', 'lungs', 'brain'] as $slug) {
        $count = Question::query()
            ->published()
            ->whereRelation('organ', 'slug', $slug)
            ->count();

        expect($count)->toBeGreaterThan(0, "No published questions seeded for [{$slug}].");
    }
});

it('resolves every spatial answer to a real published structure on the same organ', function (): void {
    $spatial = Question::query()
        ->where('type', QuestionType::Spatial)
        ->with(['correctStructure', 'organ'])
        ->get();

    expect($spatial)->not->toBeEmpty();

    foreach ($spatial as $question) {
        expect($question->correctStructure)->not->toBeNull()
            ->and($question->correctStructure?->is_published)->toBeTrue()
            ->and($question->correctStructure?->organ_id)->toBe($question->organ_id);
    }
});

it('gives every MCQ exactly one correct option', function (): void {
    $mcqs = Question::query()->where('type', QuestionType::Mcq)->with('options')->get();

    expect($mcqs)->not->toBeEmpty();

    foreach ($mcqs as $question) {
        expect($question->options->where('is_correct', true))->toHaveCount(
            1,
            "Question [{$question->question}] does not have exactly one correct option.",
        );
    }
});

it('does not always put the correct option in the same position', function (): void {
    // A quiz whose answer is always first teaches position, not anatomy.
    $positions = Question::query()
        ->where('type', QuestionType::Mcq)
        ->with('options')
        ->get()
        ->map(fn (Question $question): int|false => $question->options
            ->values()
            ->search(fn (QuestionOption $option): bool => $option->is_correct))
        ->unique();

    expect($positions->count())->toBeGreaterThan(1);
});

it('leaves at least one question awaiting review so the filter has real data', function (): void {
    expect(Question::query()->where('status', QuestionStatus::Review)->count())
        ->toBeGreaterThan(0);
});

it('is idempotent', function (): void {
    $questions = Question::query()->count();
    $options = QuestionOption::query()->count();

    $this->seed(AssessmentSeeder::class);

    expect(Question::query()->count())->toBe($questions)
        ->and(QuestionOption::query()->count())->toBe($options);
});
