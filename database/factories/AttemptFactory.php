<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attempt>
 */
final class AttemptFactory extends Factory
{
    protected $model = Attempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'question_id' => Question::factory(),
            // Handover 09 populates this; nothing in this lane writes it.
            'mission_attempt_id' => null,
            'selected_structure_id' => null,
            'selected_option_id' => null,
            'answer_text' => null,
            'is_correct' => false,
            'time_spent_ms' => fake()->numberBetween(800, 30_000),
            'hint_used' => false,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_correct' => true,
        ]);
    }

    public function hinted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'hint_used' => true,
        ]);
    }
}
