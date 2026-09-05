<?php

declare(strict_types=1);

namespace App\Http\Resources\Missions;

use App\Http\Resources\Anatomy\OrganResource;
use App\Services\Assessment\MissionDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mission: an organ to work on, and the steps to work through it.
 *
 * `organ` is rendered by Handover 03's `OrganResource`, unchanged, so the
 * mission page hands `useAnatomyViewer` exactly the `OrganDto` the frozen
 * contract describes (resources/js/anatomy/types.ts). Restating the organ shape
 * here would be a second copy of the project's most-consumed contract, drifting
 * silently the first time either side changed (docs/feature-plan.md §7.8).
 *
 * `steps` go through `MissionStepResource`, which is where the target sequence
 * is stripped. Nothing else in this file touches the configuration, so there is
 * one place to read when asking whether a mission can leak its answers.
 *
 * `scoring` is not an answer key — it is the points on offer, which the UI
 * shows so a student knows what taking a hint costs. Knowing a step is worth
 * ten points says nothing about which structure earns them.
 *
 * @property-read MissionDefinition $resource
 */
final class MissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mission = $this->resource->mission;
        $configuration = $this->resource->configuration;

        return [
            'slug' => $mission->slug,
            'title' => $mission->title,
            'description' => $mission->description,
            'type' => $mission->type->value,
            'typeLabel' => $mission->type->label(),
            // Whether order counts. The client uses it for wording only — it
            // never validates anything, and there is nothing here it could
            // validate against (docs/handovers/09-missions.md, constraints).
            'ordered' => $mission->type->isOrdered(),
            'difficulty' => $mission->difficulty,
            'stepCount' => $configuration->stepCount(),
            'maxScore' => $configuration->maxScore(),
            'scoring' => [
                'correct' => $configuration->correctPoints,
                'afterHint' => $configuration->afterHintPoints,
                'wrong' => $configuration->wrongPoints,
            ],
            'organ' => new OrganResource($mission->organ),
            'steps' => MissionStepResource::forSteps($configuration->steps, $request),
        ];
    }
}
