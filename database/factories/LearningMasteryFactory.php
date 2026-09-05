<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TopicType;
use App\Models\LearningMastery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningMastery>
 */
final class LearningMasteryFactory extends Factory
{
    protected $model = LearningMastery::class;

    /**
     * `topic_id` defaults to 1 rather than to a related factory: the column is
     * polymorphic across three tables and has no foreign key, so there is no
     * single model a factory could relate it to
     * (database/migrations/..._create_learning_mastery_table.php). Tests that
     * care set it from a real organ or system.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'topic_type' => TopicType::Organ,
            'topic_id' => 1,
            'mastery_score' => fake()->randomFloat(2, 0, 100),
            'attempts' => 0,
            'correct_attempts' => 0,
            'hinted_attempts' => 0,
            'covered_structures' => 0,
            'total_structures' => 0,
            'last_activity_at' => now(),
        ];
    }

    public function forTopic(TopicType $type, int $topicId): static
    {
        return $this->state(fn (array $attributes): array => [
            'topic_type' => $type,
            'topic_id' => $topicId,
        ]);
    }

    public function scoring(float $score): static
    {
        return $this->state(fn (array $attributes): array => [
            'mastery_score' => $score,
        ]);
    }
}
