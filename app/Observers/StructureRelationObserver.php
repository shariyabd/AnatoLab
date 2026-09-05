<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnatomicalStructure;
use App\Models\StructureRelation;
use App\Services\Anatomy\AnatomyService;

/**
 * Related structures are part of the structure detail payload, so an edge
 * changing invalidates both endpoints of that edge.
 */
final class StructureRelationObserver
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    public function saved(StructureRelation $relation): void
    {
        $this->forgetBothEnds($relation);
    }

    public function deleted(StructureRelation $relation): void
    {
        $this->forgetBothEnds($relation);
    }

    private function forgetBothEnds(StructureRelation $relation): void
    {
        foreach ([$relation->structure_id, $relation->related_structure_id] as $structureId) {
            $structure = AnatomicalStructure::query()->find($structureId);

            if ($structure instanceof AnatomicalStructure) {
                $this->anatomy->forgetStructure($structure);
            }
        }
    }
}
