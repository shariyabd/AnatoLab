<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A single deterministic step of a "what happens if" simulation.
 *
 * Produced by SimulationService, sent to the viewer via
 * AnatomyViewer.applySimulationState(), and explained by the AI tutor.
 *
 * Deterministic by design (docs/architecture.md §12): the simulation is a JSON
 * config and a match expression, not a state-machine library. The AI explains
 * the transition; it never decides it. A student must be able to run the same
 * simulation twice and see the same thing.
 */
final readonly class SimulationState
{
    /**
     * @param  string  $simulationSlug  which simulation this belongs to
     * @param  string  $stateKey  current node in the simulation's state graph
     * @param  string  $label  short display name for the current state
     * @param  array<string, int|float|string|bool>  $variables  measurable values
     *                                                           driving the UI readouts, e.g. heart_rate, oxygen
     * @param  list<string>  $affectedStructureIds  structures the viewer should
     *                                              emphasise; opaque ids, passed through unchanged
     * @param  bool  $isTerminal  true when no further transition is possible
     */
    public function __construct(
        public string $simulationSlug,
        public string $stateKey,
        public string $label,
        public array $variables = [],
        public array $affectedStructureIds = [],
        public bool $isTerminal = false,
    ) {}
}
