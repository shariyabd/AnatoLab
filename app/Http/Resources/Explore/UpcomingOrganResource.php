<?php

declare(strict_types=1);

namespace App\Http\Resources\Explore;

use App\Models\Organ;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inert row in the coverage roadmap — handover 16, handover 15 Phase 7.
 *
 * A taxonomy row is a promise, not content. It exists so a student can see that
 * the stomach is coming rather than wonder whether the library is the whole
 * body, and so a draft slug renders an honest "not yet" instead of a 404.
 *
 * Deliberately **not** `OrganSummaryResource`. Three keys are missing and each
 * omission is load-bearing:
 *
 * - **No `modelUrl` and no `model_path`.** A draft organ's path is the
 *   `models/pending/<slug>.glb` placeholder, and once a real model lands it may
 *   still be behind the licence gate. Emitting a URL for an asset that may not
 *   be redistributed is precisely what that gate exists to prevent, and a
 *   picker that cannot click the row has no use for one anyway.
 * - **No `thumbnailUrl`.** There is no render of a model that does not exist.
 * - **No `structureCount`.** It is zero on every taxonomy row by construction,
 *   and a "0 structures" caption reads as a defect rather than as a roadmap.
 *
 * @mixin Organ
 */
final class UpcomingOrganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'scientificName' => $this->scientific_name,
            'description' => $this->description,
            'accentColor' => $this->accent_color,
            'bodySystem' => $this->whenLoaded('bodySystem', fn (): array => [
                'slug' => $this->bodySystem->slug,
                'name' => $this->bodySystem->name,
            ]),
        ];
    }
}
