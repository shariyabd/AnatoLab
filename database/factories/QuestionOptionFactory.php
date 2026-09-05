<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuestionOption>
 */
final class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'label' => Str::ucfirst(fake()->words(3, true)),
            'value' => Str::lower(fake()->unique()->bothify('opt-###')),
            'is_correct' => false,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_correct' => true,
        ]);
    }
}
