<?php

declare(strict_types=1);

namespace App\Services\Simulation;

/**
 * Prose about a step that has already been computed, and where it came from.
 *
 * `source` reaches the client, and that is deliberate: a student is told
 * whether they are reading something an author wrote or something a model
 * generated. `conversationId` is how the run threads its generated
 * explanations into one tutor conversation instead of one per step — see the
 * `simulation_sessions.conversation_id` note in the migration.
 */
final readonly class SimulationExplanation
{
    public function __construct(
        public string $text,
        public string $source,
        public ?int $conversationId = null,
    ) {}
}
