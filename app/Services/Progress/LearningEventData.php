<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\LearningEventType;
use Carbon\CarbonImmutable;

/**
 * One event on its way into the append-only log (PRD §29).
 *
 * The single shape every writer produces — the browser's batch, the attempt
 * observer, the lesson-progress observer — so `RecordLearningEvents` has one
 * kind of thing to insert and the validation of a client batch produces
 * exactly what a server-side emitter produces.
 *
 * `occurredAt` is carried rather than defaulted at write time because a
 * batched client event is written a minute or more after it happened
 * (docs/architecture.md §13).
 */
final readonly class LearningEventData
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public LearningEventType $type,
        public CarbonImmutable $occurredAt,
        public ?string $contextType = null,
        public ?int $contextId = null,
        public ?array $payload = null,
    ) {}

    /**
     * The row shape, for a bulk insert.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $userId, CarbonImmutable $recordedAt): array
    {
        return [
            'user_id' => $userId,
            'event_type' => $this->type->value,
            'context_type' => $this->contextType,
            'context_id' => $this->contextId,
            'payload' => $this->payload === null ? null : json_encode($this->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $this->occurredAt,
            'created_at' => $recordedAt,
        ];
    }
}
