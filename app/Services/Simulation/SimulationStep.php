<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Contracts\SimulationState;

/**
 * The whole result of one step of a run, before an API Resource shapes it.
 *
 * `state` is the frozen App\Contracts\SimulationState — the shared contract
 * every lane was given for this, and the reason this DTO does not restate the
 * variables, the state key, or the terminal flag itself.
 *
 * Nothing in here is decided by a model. The variables were computed by
 * arithmetic, the fired thresholds by comparison, the directives by lookup.
 * `explanation` is the one field an LLM can reach, it is added last, and
 * removing it would change nothing about what the student sees on the model
 * (PRD §14: the AI explains the result, it does not control the simulation).
 */
final readonly class SimulationStep
{
    public const SOURCE_CURATED = 'curated';

    public const SOURCE_TUTOR = 'tutor';

    /** Used when a provider failure left only the deterministic facts to state. */
    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param  int  $sequence  0 for the untouched initial state, then 1, 2, 3…
     * @param  string|null  $actionId  null for the initial state only
     * @param  list<SimulationThreshold>  $fired  in declared order, mildest first
     */
    public function __construct(
        public int $sequence,
        public ?string $actionId,
        public ?string $actionLabel,
        public SimulationState $state,
        public VisualDirectives $directives,
        public array $fired,
        public ?string $explanation = null,
        public ?string $explanationSource = null,
    ) {}

    public function withExplanation(string $explanation, string $source): self
    {
        return new self(
            sequence: $this->sequence,
            actionId: $this->actionId,
            actionLabel: $this->actionLabel,
            state: $this->state,
            directives: $this->directives,
            fired: $this->fired,
            explanation: $explanation,
            explanationSource: $source,
        );
    }

    /**
     * The curated-content keys this step's outcomes point at, in order.
     *
     * @return list<string>
     */
    public function explainKeys(): array
    {
        $keys = [];

        foreach ($this->fired as $threshold) {
            if ($threshold->explainKey !== null) {
                $keys[] = $threshold->explainKey;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function outcomes(): array
    {
        return array_map(
            static fn (SimulationThreshold $threshold): string => $threshold->outcome,
            $this->fired,
        );
    }

    /**
     * One entry in `simulation_sessions.events`.
     *
     * Carries no timestamp, deliberately. The log is the run, the run has to
     * replay identically, and a wall clock is the one value in it that cannot.
     * Recency lives on the row's `updated_at`, where it belongs.
     *
     * @return array<string, mixed>
     */
    public function toLogEntry(): array
    {
        return [
            'sequence' => $this->sequence,
            'action_id' => $this->actionId,
            'action_label' => $this->actionLabel,
            'state' => $this->state->variables,
            'state_key' => $this->state->stateKey,
            'outcomes' => $this->outcomes(),
        ];
    }
}
