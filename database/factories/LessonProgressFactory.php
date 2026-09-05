<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LessonProgressStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LessonProgress>
 */
final class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'lesson_id' => Lesson::factory(),
            'status' => LessonProgressStatus::InProgress,
            'progress_percent' => fake()->numberBetween(1, 99),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LessonProgressStatus::Completed,
            'progress_percent' => 100,
            'completed_at' => Carbon::now(),
        ]);
    }
}
