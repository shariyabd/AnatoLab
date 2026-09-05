<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LearningEventType;
use App\Models\LearningEvent;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningEvent>
 */
final class LearningEventFactory extends Factory
{
    protected $model = LearningEvent::class;

    /**
     * `created_at` is set explicitly because the model has `$timestamps = false`
     * — the table is append-only and has no `updated_at`, so Eloquent fills in
     * neither (App\Models\LearningEvent).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => LearningEventType::OrganViewed,
            'context_type' => null,
            'context_id' => null,
            'payload' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }

    public function ofType(LearningEventType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_type' => $type,
        ]);
    }

    public function about(string $contextType, int $contextId): static
    {
        return $this->state(fn (array $attributes): array => [
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]);
    }

    public function occurredAt(DateTimeInterface $at): static
    {
        return $this->state(fn (array $attributes): array => [
            'occurred_at' => $at,
            'created_at' => $at,
        ]);
    }
}
