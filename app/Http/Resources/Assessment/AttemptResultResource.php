<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Services\Assessment\AttemptResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The verdict on an attempt the student has already committed to.
 *
 * This Resource carries correctness, and that is not a hole in invariant 4 —
 * it is the other half of it. The rule is that a *question* never ships its
 * answer; docs/architecture.md §9 is explicit that the attempt response
 * returns `is_correct`, the correct structure and the explanation, because the
 * viewer flashes the right structure green on a miss and the audited UX this
 * lane reproduces depends on it (docs/project-context.md §2.4). The answer is
 * revealed only after an attempt has been graded and written.
 *
 * Keys are camelCase like every other JSON this application sends —
 * `TutorReplyResource` made the same call against the same snake_case sketch
 * in the architecture doc. It also keeps the strings `is_correct` and
 * `correct_structure_id` out of `resources/js/` entirely, where `/boundary-audit`
 * greps for them and where their presence would, in any other context, be a
 * real leak.
 *
 * @property-read AttemptResult $resource
 */
final class AttemptResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attemptId' => (string) $this->resource->attemptId,
            'isCorrect' => $this->resource->isCorrect,
            'correctStructureId' => $this->resource->correctStructureId === null
                ? null
                : (string) $this->resource->correctStructureId,
            'correctOptionId' => $this->resource->correctOptionId === null
                ? null
                : (string) $this->resource->correctOptionId,
            'explanation' => $this->resource->explanation,
            // Null until Handover 10 lands; see AttemptResult.
            'masteryDelta' => $this->resource->masteryDelta,
        ];
    }
}
