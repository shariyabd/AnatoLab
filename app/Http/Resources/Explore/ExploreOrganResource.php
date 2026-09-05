<?php

declare(strict_types=1);

namespace App\Http\Resources\Explore;

use App\Http\Resources\Anatomy\OrganResource;
use App\Http\Resources\Anatomy\OrganSummaryResource;
use App\Models\Organ;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the Explore organ library.
 *
 * `OrganSummaryResource` plus the one thing a picker needs that a picker does
 * not display: `modelUrl`. Hovering a card calls the viewer's `prefetchOrgan`,
 * and a prefetch cannot warm a URL the client does not have. Fetching the full
 * `OrganResource` per card to obtain it would ship every structure of every
 * organ to draw three thumbnails, which is the exact cost
 * `OrganSummaryResource` exists to avoid.
 *
 * Composed rather than copied: Handover 03 owns both anatomy Resources and
 * this lane does not edit them (`docs/handovers/05-explore-experience.md`,
 * ownership boundaries). The summary's keys stay defined in exactly one place,
 * and `resolveModelUrl` is reused rather than reimplemented so a move from the
 * local disk to a bucket remains a config change.
 *
 * @mixin Organ
 */
final class ExploreOrganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new OrganSummaryResource($this->resource))->toArray($request),
            'modelUrl' => OrganResource::resolveModelUrl($this->model_path),
        ];
    }
}
