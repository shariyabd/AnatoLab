<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Services\Progress\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What to do next, and the sentence explaining why (PRD §15, §18).
 *
 * `reason` is a server-templated string, never a generated one
 * (docs/architecture.md §10). It arrives finished so the card renders prose
 * rather than assembling it from a factor name and a percentage — a client
 * that composed the sentence would be a second place the wording lives.
 *
 * `href` is emitted rather than a route name plus parameters: the page is
 * Inertia and follows links, and building URLs client-side is how a
 * recommendation ends up pointing at a route that was renamed.
 *
 * @property-read Recommendation $resource
 */
final class RecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organSlug' => $this->resource->organ->slug,
            'organName' => $this->resource->organ->name,
            'activity' => $this->resource->activity->value,
            'activityLabel' => $this->resource->activity->label(),
            'title' => $this->resource->title,
            'href' => $this->resource->href(),
            'reason' => $this->resource->reason,
            'weakestFactor' => $this->resource->weakestFactor?->value,
            'score' => $this->resource->score,
        ];
    }
}
