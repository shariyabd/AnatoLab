<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Services\Progress\ActivityEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the recent-activity list.
 *
 * Carries the finished label and not the raw event: the `learning_events`
 * payload records the outcome of graded answers, and none of it needs to reach
 * a page to render "Answered a question · 4 minutes ago"
 * (App\Services\Progress\ProgressService).
 *
 * @property-read ActivityEntry $resource
 */
final class ActivityEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type->value,
            'label' => $this->resource->label,
            'occurredAt' => $this->resource->occurredAt->toIso8601String(),
        ];
    }
}
