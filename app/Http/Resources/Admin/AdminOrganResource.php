<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Organ;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organ in the admin listing.
 *
 * `status` and the draft/published counts are the point — the student-facing
 * OrganSummaryResource never emits them because a student only ever sees
 * published rows and has nothing to compare against.
 *
 * @mixin Organ
 */
final class AdminOrganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'bodySystemId' => (string) $this->body_system_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'scientificName' => $this->scientific_name,
            'description' => $this->description,
            'modelPath' => $this->model_path,
            'modelFormat' => $this->model_format->value,
            'thumbnailPath' => $this->thumbnail_path,
            'accentColor' => $this->accent_color,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'bodySystem' => $this->whenLoaded('bodySystem', fn (): array => [
                'slug' => $this->bodySystem->slug,
                'name' => $this->bodySystem->name,
            ]),
            'structureCount' => $this->whenCounted('structures'),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
