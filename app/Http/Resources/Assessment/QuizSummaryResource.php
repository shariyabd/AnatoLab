<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Http\Resources\Anatomy\OrganSummaryResource;
use App\Services\Assessment\QuizSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the quiz picker.
 *
 * Composed from Handover 03's `OrganSummaryResource` rather than restating its
 * keys, exactly as `ExploreOrganResource` does: the organ card shape stays
 * defined in one place, and this lane does not edit the anatomy Resources it
 * borrows (docs/engineering.md §7).
 *
 * No `modelUrl`. The Explore picker carries one so hovering a card can warm
 * the GLB; a quiz card opens a page that loads exactly one organ, so
 * prefetching every organ in the list would fetch megabytes to save one.
 *
 * @property-read QuizSummary $resource
 */
final class QuizSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new OrganSummaryResource($this->resource->organ))->toArray($request),
            'questionCount' => $this->resource->questionCount,
        ];
    }
}
