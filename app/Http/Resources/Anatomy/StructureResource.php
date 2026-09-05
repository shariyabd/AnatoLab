<?php

declare(strict_types=1);

namespace App\Http\Resources\Anatomy;

use App\Models\AnatomicalStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors StructureDto in resources/js/anatomy/types.ts — exactly.
 *
 * That file is FROZEN (docs/feature-plan.md §7.8). These eleven keys, in this
 * spelling, are the contract; adding a twelfth here emits a field the viewer's
 * types say cannot exist, and renaming one is a runtime break TypeScript
 * cannot catch because the payload is untyped at the network boundary.
 * Tests\Feature\Anatomy\ContractParityTest reads types.ts and fails on drift.
 *
 * `id` is stringified because StructureId is `string` on the other side and
 * the viewer treats it as opaque — it never parses or compares it numerically
 * (docs/architecture.md §5.4 rule 4).
 *
 * @mixin AnatomicalStructure
 */
final class StructureResource extends JsonResource
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
            'taTerm' => $this->ta_term,
            'scientificName' => $this->scientific_name,
            'description' => $this->description,
            'function' => $this->function,
            'difficulty' => $this->difficulty,
            'anchorPosition' => self::toVec3($this->anchor_position),
            'modelObjectName' => $this->model_object_name,
            'markerColor' => $this->marker_color,
        ];
    }

    /**
     * Coerce the stored JSON into the three floats Vec3 promises.
     *
     * MySQL returns JSON numbers as PHP ints when they are whole, so an
     * anchor authored as [0, -1, 0.5] would otherwise reach the viewer as
     * `[0, -1, 0.5]` with two ints — harmless in Three.js, but it makes the
     * payload's own tests ambiguous about what round-tripped.
     *
     * @param  array<int, mixed>  $anchorPosition
     * @return list<float>
     */
    private static function toVec3(array $anchorPosition): array
    {
        return array_map(
            static fn (mixed $component): float => (float) $component,
            array_values($anchorPosition),
        );
    }
}
