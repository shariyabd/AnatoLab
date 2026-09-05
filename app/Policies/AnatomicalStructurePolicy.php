<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnatomicalStructure;
use App\Models\User;

/**
 * Who may administer a structure, including authoring its anchor.
 *
 * Middleware **and** policy, for the reason OrganPolicy spells out
 * (docs/architecture.md §14, docs/engineering.md §10).
 */
final class AnatomicalStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AnatomicalStructure $structure): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AnatomicalStructure $structure): bool
    {
        return $user->isAdmin();
    }

    /**
     * Writing `anchor_position` from the viewer's `author:point` event.
     *
     * Distinct from update() because it is the one ability that moves a marker
     * on a model every student then sees, and because the coordinate is only
     * meaningful in FIT_SIZE = 3.8 pivot space — a mis-authored anchor is not a
     * typo a student can route around, it points at the wrong anatomy
     * (docs/handovers/13-admin-content.md, invariant 5).
     */
    public function author(User $user, AnatomicalStructure $structure): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, AnatomicalStructure $structure): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AnatomicalStructure $structure): bool
    {
        return $user->isAdmin();
    }
}
