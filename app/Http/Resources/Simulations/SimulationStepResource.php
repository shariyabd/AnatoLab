<?php

declare(strict_types=1);

namespace App\Http\Resources\Simulations;

use App\Services\Simulation\SimulationStep;
use App\Services\Simulation\SimulationThreshold;
use App\Services\Simulation\VisualDirectives;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One computed step, on its way to the viewer and the readout panel.
 *
 * `visualDirectives` is the closed five-key vocabulary and provably nothing
 * else: VisualDirectives is subtractive, so a key that is not `highlight`,
 * `tint`, `pulseRate`, `focus` or `crossSection` cannot survive parsing, and a
 * value the viewer could not honour cannot either. The client hands this
 * object straight to `AnatomyViewer.applySimulationState()` with no transform
 * (resources/js/anatomy/simulation.ts).
 *
 * Keys are camelCase, as everywhere else in this application's JSON —
 * `AttemptResultResource` and `TutorReplyResource` made the same call against
 * the same snake_case sketches in the architecture document. The *configuration*
 * stays snake_case, because that is authored content matching §12 verbatim; the
 * conversion happens here and nowhere else.
 *
 * `notice` ships on every step, not once at the top of the page. PRD §14 and
 * §24 are explicit that these are educational models and never diagnostic
 * tools, and a disclaimer that scrolls away is a disclaimer for the first
 * screenful only.
 *
 * @property-read SimulationStep $resource
 */
final class SimulationStepResource extends JsonResource
{
    /**
     * The framing every simulation result carries.
     *
     * A constant rather than authored per simulation: it is a claim about what
     * this software is, and an author must not be able to soften it.
     */
    public const NOTICE = 'This is a simplified educational model of how the body works. '
        .'It is not a medical diagnosis and it does not describe anyone\'s health.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->resource->state;

        return [
            'sequence' => $this->resource->sequence,
            'actionId' => $this->resource->actionId,
            'actionLabel' => $this->resource->actionLabel,

            // The frozen App\Contracts\SimulationState, flattened. `stateKey`
            // is the machine name a test asserts on; `label` is the sentence a
            // student reads.
            'stateKey' => $state->stateKey,
            'label' => $state->label,
            'isTerminal' => $state->isTerminal,
            'variables' => $state->variables,
            'affectedStructureIds' => $state->affectedStructureIds,

            'outcomes' => array_map(
                static fn (SimulationThreshold $threshold): array => [
                    'key' => $threshold->outcome,
                    'label' => $threshold->label,
                    'condition' => $threshold->expression,
                    'terminal' => $threshold->terminal,
                ],
                $this->resource->fired,
            ),

            'visualDirectives' => $this->resource->directives->toArray(),

            'explanation' => $this->resource->explanation,
            // Curated, tutor, or fallback. The student is told which, because
            // "an author wrote this" and "a model wrote this" are different
            // claims and the difference is theirs to weigh (PRD §24).
            'explanationSource' => $this->resource->explanationSource,
            'notice' => self::NOTICE,
        ];
    }

    /**
     * Exposed so a test can assert the payload's directive keys against the
     * vocabulary itself rather than against a copy of it.
     *
     * @return list<string>
     */
    public static function permittedDirectiveKeys(): array
    {
        return VisualDirectives::KEYS;
    }
}
