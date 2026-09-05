<?php

declare(strict_types=1);

namespace App\Http\Resources\Missions;

use App\Services\Assessment\MissionResult;
use App\Services\Assessment\MissionStepResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The verdict on a run the student has already committed to.
 *
 * This Resource carries correctness, and that is not a hole in invariant 4 —
 * it is the other half of it, exactly as `AttemptResultResource` is on the quiz
 * side. The rule is that a *mission* never ships its target sequence;
 * docs/architecture.md §9 is explicit that the graded response returns per-step
 * feedback so the UI can walk the student back through the pathway, and
 * walking someone back through a pathway means naming where it actually went.
 *
 * It names one target per run, on the first missed step, and no more.
 * `App\Services\Assessment\MissionStepResult` sets out why: a mission grades a
 * whole sequence in one request, so revealing every target would sell the
 * sequence for a single throwaway run. Revealing one restores the economics of
 * the quiz — one recorded attempt buys at most one answer.
 *
 * Keys are camelCase like every other JSON this application sends. That also
 * keeps the strings `correct_structure_id` and `correct_sequence` out of
 * `resources/js/` entirely, where /boundary-audit greps for them.
 *
 * @property-read MissionResult $resource
 */
final class MissionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'missionAttemptId' => (string) $this->resource->missionAttemptId,
            'score' => $this->resource->score,
            'maxScore' => $this->resource->maxScore,
            'completed' => $this->resource->completed,
            'correctCount' => $this->resource->correctCount(),
            // Zero-based, matching the `index` the mission payload gave each
            // step, and null when nothing was missed.
            'firstMissedStep' => $this->resource->firstMissedStep(),
            'feedback' => $this->resource->feedback,
            'perStep' => array_map(
                static fn (MissionStepResult $step): array => [
                    'index' => $step->index,
                    'outcome' => $step->outcome->value,
                    'outcomeLabel' => $step->outcome->label(),
                    'points' => $step->points,
                    'prompt' => $step->prompt,
                    'explanation' => $step->explanation,
                    'selectedStructureId' => $step->selectedStructureId === null
                        ? null
                        : (string) $step->selectedStructureId,
                    // Null on every step but the first miss. See the class
                    // docblock — this limit is the design, not an omission.
                    'revealedStructureId' => $step->revealedStructureId === null
                        ? null
                        : (string) $step->revealedStructureId,
                ],
                $this->resource->steps,
            ),
        ];
    }
}
