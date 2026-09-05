<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnatomicalStructure;
use App\Services\Anatomy\AnatomyService;

/**
 * A structure is part of its organ's cached payload, so editing one has to
 * invalidate both entries (docs/architecture.md §11).
 */
final class AnatomicalStructureObserver
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    public function saved(AnatomicalStructure $structure): void
    {
        $this->anatomy->forgetStructure($structure);
    }

    public function deleted(AnatomicalStructure $structure): void
    {
        $this->anatomy->forgetStructure($structure);
    }
}
