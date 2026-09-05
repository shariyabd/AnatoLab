<?php

declare(strict_types=1);

namespace App\Http\Resources\Simulations;

use App\Models\Simulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the simulation picker.
 *
 * Deliberately not a SimulationResource: a card draws a title and the organ it
 * belongs to, and parsing every configuration to render a list would make one
 * malformed simulation break the page for all of them. Nothing here reads
 * `configuration` at all.
 *
 * @mixin Simulation
 */
final class SimulationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'premise' => $this->premise,
            'organ' => [
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
                'accentColor' => $this->organ->accent_color,
            ],
        ];
    }
}
