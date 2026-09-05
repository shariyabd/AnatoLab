<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use InvalidArgumentException;

/**
 * A parsed `simulations.configuration` (docs/architecture.md §12).
 *
 * ```json
 * {
 *   "initial_state": { "valve_closure": 1.0, "output": 1.0, "oxygenation": 0.98 },
 *   "variables":     { "output": { "label": "Cardiac output", "min": 0, "max": 1 } },
 *   "actions":       [ { "id": …, "label": …, "effects": {…}, "visual": {…} } ],
 *   "thresholds":    [ { "when": "output < 0.8", "outcome": …, "explain_key": … } ],
 *   "explanations":  { "sim.heart.reduced_flow": "…" }
 * }
 * ```
 *
 * Three of those five keys are in the architecture document verbatim.
 * `variables` and `explanations` are additions this lane needs and neither
 * widens what a simulation can *do*:
 *
 * - **`variables`** declares the bounds that "clamp the state" refers to, plus
 *   the label and precision a readout needs. It is optional; the defaults are
 *   the normalised 0–1 fractions every example in the PRD is written in.
 * - **`explanations`** is the "retrieved from curated content when available"
 *   half of §12's explanation rule. Keeping the curated prose beside the
 *   threshold that names it means an author writes one document, and means a
 *   simulation is explainable with no AI provider configured at all.
 *
 * Parsing is strict and throws. A configuration is authored content, not user
 * input: a malformed one is a bug someone must see, and a simulation that
 * silently drops half its thresholds is a simulation that teaches the wrong
 * thing. The one part that is deliberately lenient is the visual block, which
 * drops what it cannot honour — see VisualDirectives for why.
 */
final readonly class SimulationConfiguration
{
    /**
     * @param  array<string, float>  $initialState
     * @param  array<string, SimulationVariable>  $variables
     * @param  array<string, SimulationAction>  $actions  keyed by action id, in declared order
     * @param  list<SimulationThreshold>  $thresholds  in declared order, mildest first
     * @param  array<string, string>  $explanations  explain key → curated prose
     */
    private function __construct(
        public array $initialState,
        public array $variables,
        public array $actions,
        public array $thresholds,
        public array $explanations,
        public string $baselineLabel,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $initialState = self::initialState($raw['initial_state'] ?? null);
        $variables = self::variables($initialState, $raw['variables'] ?? null);
        $actions = self::actions($raw['actions'] ?? null);
        $thresholds = self::thresholds($raw['thresholds'] ?? null);

        // Every effect and every threshold must name a variable the run
        // actually holds. Neither can create one: a state whose shape changed
        // partway through a run could not be replayed against a stored log, and
        // a typo that simply never fires is a simulation that quietly teaches
        // nothing (see SimulationThreshold).
        self::assertVariablesExist($variables, $actions, $thresholds);

        return new self(
            // Normalised at parse time, so a configuration whose initial value
            // sits outside its own declared bounds starts inside them — and so
            // the baseline a run is compared against is already rounded to the
            // precision every later step will be rounded to.
            initialState: self::normalise($initialState, $variables),
            variables: $variables,
            actions: $actions,
            thresholds: $thresholds,
            explanations: self::explanations($raw['explanations'] ?? null),
            baselineLabel: self::baselineLabel($raw['baseline_label'] ?? null),
        );
    }

    public function action(string $id): ?SimulationAction
    {
        return $this->actions[$id] ?? null;
    }

    /**
     * Clamp every variable to its declared range and round to its precision.
     *
     * The single place a state becomes canonical. Both the engine and this
     * class's own constructor go through it, so "what a valid state looks
     * like" is defined once.
     *
     * @param  array<string, float>  $state
     * @param  array<string, SimulationVariable>|null  $variables
     * @return array<string, float>
     */
    public static function normalise(array $state, ?array $variables): array
    {
        $normalised = [];

        foreach ($state as $key => $value) {
            $variable = $variables[$key] ?? null;

            $normalised[$key] = $variable instanceof SimulationVariable
                ? $variable->normalise($value)
                : round($value, 3);
        }

        return $normalised;
    }

    /**
     * Every structure slug this configuration names, across every action.
     *
     * Exists so a test can prove a shipped configuration only ever points at
     * structures its organ actually publishes. An unresolvable slug is dropped
     * silently at render time by design (VisualDirectives::resolveStructures),
     * and this is what stops that being how it is discovered.
     *
     * @return list<string>
     */
    public function structureSlugs(): array
    {
        $slugs = [];

        foreach ($this->actions as $action) {
            foreach ($action->visual->structureReferences() as $slug) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  mixed  $raw
     * @return array<string, float>
     */
    private static function initialState($raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('`initial_state` must be a non-empty object of numbers.');
        }

        $state = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/i', $key) !== 1) {
                throw new InvalidArgumentException(
                    'Every `initial_state` key must be an identifier a threshold can name.'
                );
            }

            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("`initial_state.{$key}` must be a number.");
            }

            $state[strtolower($key)] = (float) $value;
        }

        return $state;
    }

    /**
     * @param  array<string, float>  $initialState
     * @param  mixed  $raw
     * @return array<string, SimulationVariable>
     */
    private static function variables(array $initialState, $raw): array
    {
        $declared = is_array($raw) ? $raw : [];
        $variables = [];

        // Driven by initial_state rather than by the declarations: a variable
        // the run never holds cannot be read out, and one the run does hold
        // must have bounds whether or not somebody declared them.
        foreach (array_keys($initialState) as $key) {
            $variables[$key] = SimulationVariable::fromConfig($key, $declared[$key] ?? null);
        }

        return $variables;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, SimulationAction>
     */
    private static function actions($raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('`actions` must be a non-empty list.');
        }

        $actions = [];

        foreach ($raw as $entry) {
            $action = SimulationAction::fromConfig($entry);

            if (isset($actions[$action->id])) {
                throw new InvalidArgumentException("Action id `{$action->id}` is declared twice.");
            }

            $actions[$action->id] = $action;
        }

        return $actions;
    }

    /**
     * @param  mixed  $raw
     * @return list<SimulationThreshold>
     */
    private static function thresholds($raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException('`thresholds` must be a list.');
        }

        return array_values(array_map(
            static fn ($entry): SimulationThreshold => SimulationThreshold::fromConfig($entry),
            $raw,
        ));
    }

    /**
     * @param  mixed  $raw
     * @return array<string, string>
     */
    private static function explanations($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $explanations = [];

        foreach ($raw as $key => $prose) {
            if (is_string($key) && is_string($prose) && trim($prose) !== '') {
                $explanations[$key] = trim($prose);
            }
        }

        return $explanations;
    }

    /**
     * @param  array<string, SimulationVariable>  $variables
     * @param  array<string, SimulationAction>  $actions
     * @param  list<SimulationThreshold>  $thresholds
     *
     * @throws InvalidArgumentException
     */
    private static function assertVariablesExist(array $variables, array $actions, array $thresholds): void
    {
        foreach ($actions as $action) {
            foreach (array_keys($action->effects) as $key) {
                if (! isset($variables[$key])) {
                    throw new InvalidArgumentException(
                        "Action `{$action->id}` affects `{$key}`, which is not in `initial_state`."
                    );
                }
            }
        }

        foreach ($thresholds as $threshold) {
            if (! isset($variables[$threshold->variable])) {
                throw new InvalidArgumentException(
                    "Threshold `{$threshold->outcome}` tests `{$threshold->variable}`, "
                    .'which is not in `initial_state`.'
                );
            }
        }
    }

    /** What the state is called before any threshold has fired. */
    public const BASELINE_KEY = 'baseline';

    /**
     * @param  mixed  $raw
     */
    private static function baselineLabel($raw): string
    {
        return is_string($raw) && trim($raw) !== '' ? trim($raw) : 'Working normally';
    }
}
