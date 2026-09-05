<?php

declare(strict_types=1);

namespace App\Http\Resources\Simulations;

use App\Http\Resources\Anatomy\OrganResource;
use App\Models\Simulation;
use App\Services\Simulation\SimulationAction;
use App\Services\Simulation\SimulationConfiguration;
use App\Services\Simulation\SimulationVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A runnable simulation: the organ to look at, what can be done to it, and
 * what to read out.
 *
 * `organ` is a plain `OrganDto` — the same object Explore and the quiz hand the
 * viewer — so the page mounts `ViewerStage` with no transform step.
 *
 * **What is not here is the point.** No `effects`, no `thresholds`, no
 * `explanations`. The browser is told what it may *do* (an id and a label per
 * action) and never what doing it produces. That is not answer-key stripping by
 * analogy — it is the same rule (invariant 4, docs/architecture.md §5.4 rule 2):
 * a client holding the effect table could compute the next state itself, and a
 * simulation whose numbers can be produced in two places is a simulation that
 * can disagree with itself. Every number in a run comes back from
 * `POST /simulations/{simulation}/event`.
 *
 * The static half travels with the page and the moving half does not, which is
 * why `variables` (labels and bounds, fixed) is here while `variables` values
 * live on SimulationStepResource.
 */
final class SimulationResource extends JsonResource
{
    /**
     * @var Simulation
     */
    public $resource;

    public function __construct(Simulation $resource, private readonly SimulationConfiguration $configuration)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'slug' => $this->resource->slug,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'premise' => $this->resource->premise,

            'organ' => new OrganResource($this->resource->organ),

            'actions' => array_values(array_map(
                static fn (SimulationAction $action): array => [
                    'id' => $action->id,
                    'label' => $action->label,
                    'description' => $action->description,
                ],
                $this->configuration->actions,
            )),

            'readouts' => array_values(array_map(
                static fn (SimulationVariable $variable): array => [
                    'key' => $variable->key,
                    'label' => $variable->label,
                    'min' => $variable->min,
                    'max' => $variable->max,
                    'precision' => $variable->precision,
                    'unit' => $variable->unit,
                ],
                $this->configuration->variables,
            )),

            'notice' => SimulationStepResource::NOTICE,
        ];
    }
}
