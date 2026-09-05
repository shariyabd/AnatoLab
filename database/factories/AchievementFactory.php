<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AchievementCriterion;
use App\Models\Achievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Achievement>
 */
final class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    /**
     * Defaults to a criterion nothing can meet — one completed lesson is a low
     * bar, but a factory badge should not attach itself to every student in
     * every test that happens to complete something. Tests state the criterion
     * they are exercising.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'icon' => 'award',
            'criteria' => [
                'type' => AchievementCriterion::LessonsCompleted->value,
                'count' => 9_999,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function withCriteria(array $criteria): static
    {
        return $this->state(fn (array $attributes): array => [
            'criteria' => $criteria,
        ]);
    }
}
