<?php

declare(strict_types=1);

namespace App\Http\Resources\Missions;

use App\Http\Resources\Anatomy\OrganSummaryResource;
use App\Services\Assessment\MissionDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the mission picker.
 *
 * Composed from Handover 03's `OrganSummaryResource` rather than restating its
 * keys, exactly as `QuizSummaryResource` and `ExploreOrganResource` do: the
 * organ card shape stays defined in one place, and this lane does not edit the
 * anatomy Resources it borrows (docs/engineering.md §7).
 *
 * The organ is nested rather than spread, unlike the quiz card. A mission has a
 * title and a description of its own — several missions can sit on one organ —
 * so flattening them into the organ's would put two `name`s and two
 * `description`s in one object.
 *
 * No steps. A card advertises what the mission is, and the prompts are the
 * mission; shipping them here would fetch every prompt of every mission to draw
 * a list. `MissionResource` is where a mission is opened.
 *
 * @property-read MissionDefinition $resource
 */
final class MissionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mission = $this->resource->mission;

        return [
            'slug' => $mission->slug,
            'title' => $mission->title,
            'description' => $mission->description,
            'type' => $mission->type->value,
            'typeLabel' => $mission->type->label(),
            'difficulty' => $mission->difficulty,
            'stepCount' => $this->resource->stepCount(),
            'maxScore' => $this->resource->configuration->maxScore(),
            'organ' => new OrganSummaryResource($mission->organ),
        ];
    }
}
