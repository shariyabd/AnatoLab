<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Services\Progress\SystemMastery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of PRD §15's per-system table.
 *
 * Mirrors `SystemMasteryDto` in resources/js/types/progress.ts. Keys are
 * camelCase like every other JSON this application sends.
 *
 * Ids are strings for the reason every id in this codebase is
 * (docs/architecture.md §5.4 rule 4): a 64-bit key does not survive a
 * JavaScript number, and a client that never does arithmetic on an id never
 * needs it to be one.
 *
 * @property-read SystemMastery $resource
 */
final class SystemMasteryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->system->getKey(),
            'slug' => $this->resource->system->slug,
            'name' => $this->resource->system->name,
            'score' => $this->resource->score,
            'attempts' => $this->resource->attempts,
            'correctAttempts' => $this->resource->correctAttempts,
            'coveredStructures' => $this->resource->coveredStructures,
            'totalStructures' => $this->resource->totalStructures,
            'lastActivityAt' => $this->resource->lastActivityAt?->toIso8601String(),
        ];
    }
}
