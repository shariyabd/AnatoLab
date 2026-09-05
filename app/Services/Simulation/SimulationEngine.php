<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Contracts\SimulationState;
use InvalidArgumentException;

/**
 * The deterministic state engine (docs/architecture.md §12, PRD §14).
 *
 * Pure. It reads no database, no request, no user, no clock, and no random
 * source, and it writes nothing. Given the same configuration, the same
 * ordered action ids and the same slug→id map it produces byte-identical
 * output, on any machine, in any order of calls. **That is the feature**, not
 * an implementation detail: "replaying the same actions reproduces the same
 * state exactly" is the acceptance criterion this class exists to satisfy, and
 * a service that mutated a stored state in place could only ever satisfy it by
 * discipline.
 *
 * So the API is a replay, not a transition. SimulationService never asks for
 * "the next state given this one" — it appends an action id to the run's log
 * and replays the whole log. The stored `state` column is a cache of that, and
 * nothing reads it back into the engine.
 *
 * The loop is four steps, in this order, and the order is load-bearing:
 *
 *   1. add the action's effects to the current variables
 *   2. clamp each variable to its declared range and round to its precision
 *   3. evaluate every threshold against the clamped state
 *   4. merge the action's visual directives over the ones still in force
 *
 * Clamping before evaluating is why a threshold fires on what the student can
 * see rather than on an intermediate value no readout ever showed. Rounding
 * inside step 2 is why a run survives the JSON round-trip through
 * `simulation_sessions.state` and comes back equal rather than nearly equal.
 *
 * There is no state-machine library here and there is not going to be
 * (docs/engineering.md §12). A threshold is a regex-parsed comparison
 * evaluated by a `match`; that is the whole mechanism.
 */
final class SimulationEngine
{
    /**
     * Run an ordered action log from the beginning.
     *
     * Returns every step, starting with sequence 0 — the untouched initial
     * state, which is a step a student can be shown and an explanation can be
     * written for, not a special case to be handled separately.
     *
     * @param  list<string>  $actionIds
     * @param  array<string, string>  $structureIds  authored slug → opaque structure id
     * @return non-empty-list<SimulationStep>
     *
     * @throws InvalidArgumentException when the log names an action the configuration does not declare
     */
    public function replay(
        string $simulationSlug,
        SimulationConfiguration $configuration,
        array $actionIds,
        array $structureIds = [],
    ): array {
        $variables = $configuration->initialState;
        $directives = VisualDirectives::none();

        $steps = [
            $this->step(
                simulationSlug: $simulationSlug,
                configuration: $configuration,
                sequence: 0,
                action: null,
                variables: $variables,
                directives: $directives,
            ),
        ];

        foreach ($actionIds as $index => $actionId) {
            $action = $configuration->action($actionId);

            if (! $action instanceof SimulationAction) {
                throw new InvalidArgumentException(
                    "This simulation declares no action `{$actionId}`."
                );
            }

            foreach ($action->effects as $key => $delta) {
                $variables[$key] = ($variables[$key] ?? 0.0) + $delta;
            }

            $variables = SimulationConfiguration::normalise($variables, $configuration->variables);

            // Resolved per action rather than once at the end: a slug this
            // organ does not publish drops out of *this* action's block and
            // leaves whatever an earlier action set still standing, which is
            // the behaviour VisualDirectives documents.
            $directives = $directives->merge(
                $action->visual->resolveStructures($structureIds)
            );

            $steps[] = $this->step(
                simulationSlug: $simulationSlug,
                configuration: $configuration,
                sequence: $index + 1,
                action: $action,
                variables: $variables,
                directives: $directives,
            );
        }

        return $steps;
    }

    /**
     * Assemble one step: evaluate the thresholds, name the state, package it.
     *
     * @param  array<string, float>  $variables
     */
    private function step(
        string $simulationSlug,
        SimulationConfiguration $configuration,
        int $sequence,
        ?SimulationAction $action,
        array $variables,
        VisualDirectives $directives,
    ): SimulationStep {
        $fired = [];

        // The *last* threshold to fire names the state. Configurations declare
        // thresholds mildest first (docs/architecture.md §12's example, and the
        // seeder follows it), so the last one that fired is the most severe
        // thing currently true — which is what a student needs the run called.
        $last = null;
        $terminal = false;

        foreach ($configuration->thresholds as $threshold) {
            if (! $threshold->firesFor($variables)) {
                continue;
            }

            $fired[] = $threshold;
            $last = $threshold;
            $terminal = $terminal || $threshold->terminal;
        }

        return new SimulationStep(
            sequence: $sequence,
            actionId: $action?->id,
            actionLabel: $action?->label,
            state: new SimulationState(
                simulationSlug: $simulationSlug,
                stateKey: $last instanceof SimulationThreshold
                    ? $last->outcome
                    : SimulationConfiguration::BASELINE_KEY,
                label: $last instanceof SimulationThreshold ? $last->label : $configuration->baselineLabel,
                variables: $variables,
                // Only structures the viewer is currently pointed at, and only
                // ones that resolved. Opaque ids, passed through unchanged
                // (docs/architecture.md §5.4 rule 4).
                affectedStructureIds: $directives->structureReferences(),
                isTerminal: $terminal,
            ),
            directives: $directives,
            fired: $fired,
        );
    }
}
