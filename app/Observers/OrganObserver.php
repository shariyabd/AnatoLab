<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Organ;
use App\Services\Anatomy\AnatomyService;

/**
 * Keeps the anatomy cache honest.
 *
 * The alternative — remembering to clear the cache at every write site — fails
 * the first time an admin edits an organ through a path nobody thought about
 * (docs/architecture.md §11).
 */
final class OrganObserver
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    public function saved(Organ $organ): void
    {
        $this->anatomy->forgetOrgan($organ);
    }

    public function deleted(Organ $organ): void
    {
        $this->anatomy->forgetOrgan($organ);
    }
}
