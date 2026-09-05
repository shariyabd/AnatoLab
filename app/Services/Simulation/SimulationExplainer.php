<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Enums\TutorTask;
use App\Exceptions\AIProviderException;
use App\Models\Simulation;
use App\Models\User;
use App\Services\AI\AITutorService;
use App\Services\AI\TutorRequestData;

/**
 * Curated prose where an author wrote some, the tutor where nobody did.
 *
 * The direction of the arrow is the whole design (PRD §14, docs/architecture.md
 * §12). The state transition has already happened by the time this class is
 * called; it is handed a finished SimulationStep and cannot influence one. An
 * LLM outage changes what the student *reads*, never what the model *does* —
 * which is why the provider failure below is caught here and is not caught in
 * AITutorService, where the answer is the product rather than a caption on one.
 *
 * Curated first, always. Curated content is written for a 13–18 year old by
 * somebody accountable for it, costs nothing, and works with no provider
 * configured at all, so a fully authored simulation never touches the network.
 * The tutor is the fallback for a state nobody wrote for — not the default.
 *
 * **This class calls the tutor and does not change it.** AITutorService is
 * Handover 08's, Handover 11 has already landed at its retrieval seam, and the
 * public `respond()` is the entry point both were built around
 * (docs/handovers/parallel-execution-plan.md D2). The one thing this lane adds
 * is the framing in the question: the prompt says in as many words that this is
 * an educational model and not a person, because "never diagnostic" (PRD §24)
 * is a property this application has to assert rather than hope for.
 */
final class SimulationExplainer
{
    public function __construct(private readonly AITutorService $tutor) {}

    /**
     * @param  int|null  $conversationId  the run's existing tutor thread, if it has one
     */
    public function explain(
        User $user,
        Simulation $simulation,
        SimulationConfiguration $configuration,
        SimulationStep $step,
        ?int $conversationId = null,
    ): SimulationExplanation {
        $curated = $this->curated($configuration, $step);

        if ($curated !== null) {
            return new SimulationExplanation($curated, SimulationStep::SOURCE_CURATED, $conversationId);
        }

        // Sequence 0 is the untouched starting state. There is no transition to
        // explain, so there is nothing to spend a provider call on — and a page
        // load must never reach an LLM.
        if ($step->sequence === 0) {
            return new SimulationExplanation(
                $this->deterministicSummary($configuration, $step),
                SimulationStep::SOURCE_FALLBACK,
                $conversationId,
            );
        }

        return $this->generated($user, $simulation, $configuration, $step, $conversationId);
    }

    /**
     * The authored prose for this step's outcomes, joined in declared order.
     *
     * Keys are tried in the order an author would expect them to win: the
     * explicit `explain_key` on each fired threshold first, then the outcome
     * names themselves, then the state key — which for an untouched run is
     * `baseline`, so `"explanations": { "baseline": … }` is how a simulation
     * introduces itself.
     */
    private function curated(SimulationConfiguration $configuration, SimulationStep $step): ?string
    {
        $found = [];

        foreach ($this->candidateKeys($step) as $key) {
            $prose = $configuration->explanations[$key] ?? null;

            if ($prose !== null && ! in_array($prose, $found, true)) {
                $found[] = $prose;
            }
        }

        return $found === [] ? null : implode("\n\n", $found);
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(SimulationStep $step): array
    {
        return array_values(array_unique([
            ...$step->explainKeys(),
            ...$step->outcomes(),
            $step->state->stateKey,
        ]));
    }

    /**
     * Ask the tutor to caption a result it did not produce.
     *
     * The question is built from the step's own numbers, so the model is given
     * the facts rather than asked for them. Everything that could vary — the
     * values, the outcome labels, the action that caused them — is already
     * decided; only the wording is not.
     */
    private function generated(
        User $user,
        Simulation $simulation,
        SimulationConfiguration $configuration,
        SimulationStep $step,
        ?int $conversationId,
    ): SimulationExplanation {
        try {
            $reply = $this->tutor->respond($user, new TutorRequestData(
                task: TutorTask::Explain,
                question: $this->question($simulation, $configuration, $step),
                organSlug: $simulation->organ->slug,
                structureId: $this->focusedStructureId($step),
                conversationId: $conversationId,
            ));

            return new SimulationExplanation(
                $reply->answer,
                SimulationStep::SOURCE_TUTOR,
                $reply->conversationId,
            );
        } catch (AIProviderException) {
            // The deterministic half of the step is unaffected and is the half
            // that teaches. Reporting the provider's failure to a student would
            // be reporting something they cannot act on (PRD §40); the numbers
            // and the outcome labels say the same thing in fewer words.
            return new SimulationExplanation(
                $this->deterministicSummary($configuration, $step),
                SimulationStep::SOURCE_FALLBACK,
                $conversationId,
            );
        }
    }

    private function question(
        Simulation $simulation,
        SimulationConfiguration $configuration,
        SimulationStep $step,
    ): string {
        $organ = $simulation->organ->name;
        $change = $step->actionLabel ?? 'a change';
        $readings = $this->readings($configuration, $step);
        $outcomes = $this->outcomeLabels($step);

        $observed = $outcomes === ''
            ? 'The model reports no threshold crossed.'
            : "The model reports: {$outcomes}.";

        return "In an educational simulation of the {$organ}, this change was applied: {$change}. "
            ."The simulated readings are now {$readings}. {$observed} "
            .'Explain in a short paragraph what that change means for how this organ works, '
            .'and why the readings moved the way they did. '
            .'This is a simplified teaching model of body mechanics, not a person: '
            .'do not diagnose, do not describe symptoms in a reader, and do not suggest treatment.';
    }

    /**
     * What a student is told when there is no author and no model: the run's
     * own numbers, which are the reliable part in any case.
     */
    private function deterministicSummary(
        SimulationConfiguration $configuration,
        SimulationStep $step,
    ): string {
        $readings = $this->readings($configuration, $step);
        $outcomes = $this->outcomeLabels($step);

        $summary = "{$step->state->label}. The simulated readings are {$readings}.";

        return $outcomes === ''
            ? $summary
            : "{$summary} At this point the model shows: {$outcomes}.";
    }

    /**
     * "cardiac output 0.75, systemic oxygenation 0.98" — labels, not keys, and
     * in the configuration's declared order so two runs read identically.
     */
    private function readings(SimulationConfiguration $configuration, SimulationStep $step): string
    {
        $parts = [];

        foreach ($configuration->variables as $key => $variable) {
            $value = $step->state->variables[$key] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $reading = strtolower($variable->label).' '.self::trim((float) $value, $variable->precision);

            $parts[] = $variable->unit === null ? $reading : "{$reading} {$variable->unit}";
        }

        return $parts === [] ? 'unchanged' : implode(', ', $parts);
    }

    /**
     * `0.750` reads as 0.75 and `1.000` as 1, but `10` must not become `1`.
     *
     * Trailing zeros are only ever stripped from a string that has a decimal
     * point in it, which is the whole subtlety here.
     */
    private static function trim(float $value, int $precision): string
    {
        $formatted = number_format($value, $precision, '.', '');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    private function outcomeLabels(SimulationStep $step): string
    {
        $labels = array_map(
            static fn (SimulationThreshold $threshold): string => lcfirst($threshold->label),
            $step->fired,
        );

        return implode('; ', $labels);
    }

    /**
     * The structure the step has the camera or the highlight on, as an int.
     *
     * Gives the tutor the same anchor a student would have selected by hand.
     * Ids leave the API as strings (every Resource in this project emits them
     * that way); TutorRequestData wants the integer key.
     */
    private function focusedStructureId(SimulationStep $step): ?int
    {
        foreach ($step->state->affectedStructureIds as $id) {
            if (ctype_digit($id)) {
                return (int) $id;
            }
        }

        return null;
    }
}
