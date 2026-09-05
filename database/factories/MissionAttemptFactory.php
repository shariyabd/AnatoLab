<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissionAttempt>
 */
final class MissionAttemptFactory extends Factory
{
    protected $model = MissionAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mission_id' => Mission::factory(),
            'score' => 0,
            'completed' => false,
            'duration_ms' => fake()->numberBetween(10_000, 240_000),
            'result' => ['type' => 'trace_pathway', 'steps' => []],
        ];
    }

    public function completed(int $score = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => $score,
            'completed' => true,
        ]);
    }
}
