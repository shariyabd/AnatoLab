<?php

declare(strict_types=1);

namespace App\Http\Resources\Anatomy;

use App\Enums\StructureRelationType;
use App\Models\AnatomicalStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full metadata panel for one structure.
 *
 * A superset of StructureDto, not a replacement for it: everything the viewer
 * needs is spelled identically, so a Vue panel can hand this object to the
 * composable without a transform, and the extra keys (`location`, `organ`,
 * `relatedStructures`) are for the panel alone.
 *
 * @mixin AnatomicalStructure
 */
final class StructureDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new StructureResource($this->resource))->toArray($request),
            'location' => $this->location,
            // Cast so an empty bag encodes as `{}` rather than `[]`. PHP's
            // empty array is ambiguous in JSON and a client that indexes into
            // it gets an array on one row and an object on the next.
            'metadata' => (object) ($this->metadata ?? []),
            'organ' => [
                'id' => (string) $this->organ->id,
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
                'accentColor' => $this->organ->accent_color,
            ],
            'relatedStructures' => $this->whenLoaded(
                'publishedRelatedStructures',
                fn (): array => $this->publishedRelatedStructures
                    ->map(static fn (AnatomicalStructure $related): array => [
                        'id' => (string) $related->id,
                        'slug' => $related->slug,
                        'name' => $related->name,
                        'taTerm' => $related->ta_term,
                        'relationType' => self::relationTypeOf($related),
                        'relationLabel' => self::relationLabelOf($related),
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * The relation verb rides on the pivot, and it is a plain string there —
     * the pivot is not the StructureRelation model, so no cast applies.
     */
    private static function relationTypeOf(AnatomicalStructure $related): ?string
    {
        $pivot = $related->getRelationValue('pivot');

        return $pivot === null ? null : (string) $pivot->getAttribute('relation_type');
    }

    private static function relationLabelOf(AnatomicalStructure $related): ?string
    {
        $type = self::relationTypeOf($related);

        return $type === null
            ? null
            : StructureRelationType::tryFrom($type)?->label();
    }
}
