<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
final class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * Defaults to a draft MCQ with no options.
     *
     * Draft rather than published so a test that forgets `->published()` gets
     * an empty quiz rather than a passing assertion about a question that
     * should never have been served.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Null by default: `lessons` belongs to Handover 06 and there is no
            // foreign key on this column (see the migration).
            'lesson_id' => null,
            'organ_id' => Organ::factory(),
            'type' => QuestionType::Mcq,
            'question' => rtrim(fake()->sentence(), '.').'?',
            'difficulty' => fake()->numberBetween(1, 5),
            'explanation' => fake()->sentence(),
            'correct_structure_id' => null,
            'metadata' => [],
            'status' => QuestionStatus::Draft,
            'generated_by_ai' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuestionStatus::Published,
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuestionStatus::Review,
        ]);
    }

    /**
     * A spatial question whose answer is this structure.
     *
     * Takes the structure rather than creating one so the question and its
     * answer always sit on the same organ — a spatial question pointing at
     * another organ's structure is unanswerable, and a factory that produces
     * one lets a test pass against data the application cannot serve.
     */
    public function spatial(AnatomicalStructure $structure): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => QuestionType::Spatial,
            'organ_id' => $structure->organ_id,
            'correct_structure_id' => $structure->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $accepted
     */
    public function shortAnswer(array $accepted): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => QuestionType::ShortAnswer,
            'metadata' => ['rubric' => ['accepted' => $accepted]],
        ]);
    }

    public function withHint(string $hint): static
    {
        return $this->state(function (array $attributes) use ($hint): array {
            /** @var array<string, mixed> $metadata */
            $metadata = $attributes['metadata'] ?? [];

            return ['metadata' => [...$metadata, 'hint' => $hint]];
        });
    }

    public function generatedByAi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'generated_by_ai' => true,
        ]);
    }
}
