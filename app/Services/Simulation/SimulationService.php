<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs simulations for a student, and owns their sessions.
 *
 * Takes the User as an argument and reads nothing from the request, the
 * session, or the authenticated-user helper (invariant 1). Every session read
 * and write below is scoped by that passed-in user, so a `simulation`
 * belonging to somebody else is not reachable by naming it — ownership is
 * never trusted from a request parameter (docs/engineering.md §10).
 *
 * **The engine decides; this class persists.** Nothing here computes a
 * variable, evaluates a threshold, or picks a directive. It appends an action
 * id to the run's log, hands the whole log to SimulationEngine, and stores what
 * comes back. That is what makes `POST /simulations/{simulation}/event`
 * reproducible: the response is a function of (configuration, ordered action
 * ids) and of nothing else — not of the row's previous `state`, not of the
 * clock, not of the provider.
 *
 * The explanation is added last, by SimulationExplainer, and could be removed
 * without changing a single number (PRD §14).
 */
final class SimulationService
{
    public function __construct(
        private readonly SimulationEngine $engine,
        private readonly SimulationExplainer $explainer,
    ) {}

    /**
     * Published simulations for the picker, newest content last.
     *
     * `organ` is eager loaded because every card names it; `publishedStructures`
     * is not, because a card draws no model.
     *
     * @return Collection<int, Simulation>
     */
    public function listPublished(): Collection
    {
        /** @var Collection<int, Simulation> $simulations */
        $simulations = Simulation::query()
            ->published()
            ->with('organ')
            ->orderBy('title')
            ->get();

        return $simulations;
    }

    /**
     * One runnable simulation, with everything the viewer needs, or null.
     *
     * Null for an unknown slug and for a draft alike, so the controller 404s on
     * both without distinguishing them — the same reason AnatomyService does
     * it: a draft's existence is not something a URL guess should confirm.
     */
    public function findPublished(string $slug): ?Simulation
    {
        return Simulation::query()
            ->published()
            ->where('slug', $slug)
            ->with('organ.publishedStructures')
            ->first();
    }

    /**
     * Parse `simulations.configuration`.
     *
     * Throws on a malformed configuration rather than degrading. A simulation
     * is authored content: a broken one is a bug its author must see, and one
     * that quietly dropped half its thresholds would teach the wrong thing for
     * as long as nobody noticed (SimulationConfiguration).
     */
    public function configurationFor(Simulation $simulation): SimulationConfiguration
    {
        return SimulationConfiguration::fromArray($simulation->configuration);
    }

    /**
     * Where this student's run currently stands, explanation included.
     *
     * Never reaches a provider. The prose for each step was written into the
     * event log when that step was taken, so a page load replays arithmetic and
     * reads back text — it does not regenerate anything
     * (docs/engineering.md §10: no personalised AI response is cached, and this
     * is not a cache of one; it is the transcript of a turn that happened).
     */
    public function currentStep(User $user, Simulation $simulation): SimulationStep
    {
        $configuration = $this->configurationFor($simulation);
        $session = $this->findSession($user, $simulation);
        $log = $session instanceof SimulationSession ? $session->events : [];

        $steps = $this->engine->replay(
            $simulation->slug,
            $configuration,
            $this->knownActions($configuration, $session),
            $this->structureIds($simulation),
        );

        $final = $steps[count($steps) - 1];
        $recorded = $this->recordedExplanation($log, $final);

        if ($recorded instanceof SimulationExplanation) {
            return $final->withExplanation($recorded->text, $recorded->source);
        }

        // Nothing recorded means either an untouched run or a session written
        // before this step had prose. Curated content answers both without a
        // provider call; SimulationExplainer never generates for sequence 0.
        $explanation = $this->explainer->explain($user, $simulation, $configuration, $final);

        return $final->withExplanation($explanation->text, $explanation->source);
    }

    /**
     * Apply one action and record it.
     *
     * Returns null when the simulation declares no such action, so the
     * controller can 404 without the service knowing what HTTP is.
     */
    public function applyAction(User $user, Simulation $simulation, string $actionId): ?SimulationStep
    {
        $configuration = $this->configurationFor($simulation);

        if (! $configuration->action($actionId) instanceof SimulationAction) {
            return null;
        }

        $session = $this->sessionFor($user, $simulation);

        $sequence = [...$this->knownActions($configuration, $session), $actionId];

        $steps = $this->engine->replay(
            $simulation->slug,
            $configuration,
            $sequence,
            $this->structureIds($simulation),
        );

        $final = $steps[count($steps) - 1];

        $explanation = $this->explainer->explain(
            $user,
            $simulation,
            $configuration,
            $final,
            $session->conversation_id,
        );

        $explained = $final->withExplanation($explanation->text, $explanation->source);

        $this->persist($session, $steps, $explained, $explanation->conversationId);

        return $explained;
    }

    /**
     * Empty the run and return the untouched starting state.
     *
     * The tutor thread survives, deliberately: a student who resets to try a
     * different order has not stopped asking about the same simulation, and
     * starting a second conversation would split one line of enquiry in two.
     */
    public function reset(User $user, Simulation $simulation): SimulationStep
    {
        $configuration = $this->configurationFor($simulation);
        $session = $this->sessionFor($user, $simulation);

        $steps = $this->engine->replay(
            $simulation->slug,
            $configuration,
            [],
            $this->structureIds($simulation),
        );

        $initial = $steps[0];
        $explanation = $this->explainer->explain($user, $simulation, $configuration, $initial);
        $explained = $initial->withExplanation($explanation->text, $explanation->source);

        $session->fill([
            'state' => $initial->state->variables,
            'events' => [],
            'result' => null,
        ])->save();

        return $explained;
    }

    /**
     * This student's event log, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function eventLog(User $user, Simulation $simulation): array
    {
        $session = $this->findSession($user, $simulation);

        return $session instanceof SimulationSession ? $session->events : [];
    }

    /*
    |---------------------------------------------------------------------------
    | Sessions
    |---------------------------------------------------------------------------
    */

    private function findSession(User $user, Simulation $simulation): ?SimulationSession
    {
        return SimulationSession::query()
            ->where('user_id', $user->getKey())
            ->where('simulation_id', $simulation->getKey())
            ->first();
    }

    /**
     * This student's run, created empty if they have not started one.
     *
     * `user_id` and `simulation_id` are assigned through the relations rather
     * than filled, because neither is fillable on the model — the guard that
     * stops a request array ever choosing whose run this is.
     */
    private function sessionFor(User $user, Simulation $simulation): SimulationSession
    {
        $existing = $this->findSession($user, $simulation);

        if ($existing instanceof SimulationSession) {
            return $existing;
        }

        $session = new SimulationSession([
            'state' => $this->configurationFor($simulation)->initialState,
            'events' => [],
            'result' => null,
        ]);

        $session->user()->associate($user);
        $session->simulation()->associate($simulation);
        $session->save();

        return $session;
    }

    /**
     * The stored action ids this configuration still declares.
     *
     * A configuration edited after a run started can drop an action the run
     * used. Pruning it keeps the run replayable — the alternative is a student
     * whose saved session throws on every page load until somebody deletes the
     * row by hand. The run's remaining actions still replay in their original
     * order, so what is left is still deterministic.
     *
     * @return list<string>
     */
    private function knownActions(SimulationConfiguration $configuration, ?SimulationSession $session): array
    {
        if (! $session instanceof SimulationSession) {
            return [];
        }

        return array_values(array_filter(
            $session->actionSequence(),
            static fn (string $id): bool => $configuration->action($id) instanceof SimulationAction,
        ));
    }

    /**
     * Rewrite the whole log from the replay, keeping the prose already written.
     *
     * The arithmetic is recomputed on every step and overwrites what was
     * stored, which is what guarantees `state` can never drift from `events`.
     * The explanations are not recomputed: an earlier step's text was written
     * once, may have come from a provider, and regenerating it on every
     * subsequent click would spend a call to produce different words about an
     * identical, already-explained transition.
     *
     * @param  non-empty-list<SimulationStep>  $steps
     */
    private function persist(
        SimulationSession $session,
        array $steps,
        SimulationStep $final,
        ?int $conversationId,
    ): void {
        $previous = $session->events;
        $events = [];

        // Sequence 0 is the initial state and is not an event: nothing was
        // done to reach it, and the page renders it from the configuration.
        foreach (array_slice($steps, 1) as $index => $step) {
            $entry = $step->toLogEntry();
            $carried = $previous[$index] ?? null;

            $entry['explanation'] = null;
            $entry['explanation_source'] = null;

            if (is_array($carried) && ($carried['action_id'] ?? null) === $step->actionId) {
                $entry['explanation'] = is_string($carried['explanation'] ?? null)
                    ? $carried['explanation']
                    : null;
                $entry['explanation_source'] = is_string($carried['explanation_source'] ?? null)
                    ? $carried['explanation_source']
                    : null;
            }

            if ($step->sequence === $final->sequence) {
                $entry['explanation'] = $final->explanation;
                $entry['explanation_source'] = $final->explanationSource;
            }

            $events[] = $entry;
        }

        $session->fill([
            'state' => $final->state->variables,
            'events' => $events,
            'result' => [
                'state_key' => $final->state->stateKey,
                'label' => $final->state->label,
                'outcomes' => $final->outcomes(),
                'is_terminal' => $final->state->isTerminal,
            ],
            'conversation_id' => $conversationId,
        ])->save();
    }

    /**
     * The prose already written for the step a replay just landed on.
     *
     * @param  list<array<string, mixed>>  $log
     */
    private function recordedExplanation(array $log, SimulationStep $step): ?SimulationExplanation
    {
        if ($step->sequence === 0) {
            return null;
        }

        $entry = $log[$step->sequence - 1] ?? null;

        if (! is_array($entry) || ($entry['action_id'] ?? null) !== $step->actionId) {
            return null;
        }

        $text = $entry['explanation'] ?? null;
        $source = $entry['explanation_source'] ?? null;

        if (! is_string($text) || $text === '') {
            return null;
        }

        return new SimulationExplanation(
            $text,
            is_string($source) ? $source : SimulationStep::SOURCE_CURATED,
        );
    }

    /*
    |---------------------------------------------------------------------------
    | Structure resolution
    |---------------------------------------------------------------------------
    */

    /**
     * Authored slug → opaque structure id, for this simulation's organ.
     *
     * A configuration names structures by slug because ids are per-database and
     * a seeded simulation has to survive `migrate:fresh`. Only published
     * structures are in the map: the organ payload ships published structures
     * only, so an unpublished one has no marker on the model for a directive to
     * point at (VisualDirectives drops what it cannot resolve).
     *
     * @return array<string, string>
     */
    private function structureIds(Simulation $simulation): array
    {
        $simulation->loadMissing('organ.publishedStructures');

        $map = [];

        foreach ($simulation->organ->publishedStructures as $structure) {
            $map[$structure->slug] = (string) $structure->getKey();
        }

        return $map;
    }
}
