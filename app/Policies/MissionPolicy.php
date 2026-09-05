<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mission;
use App\Models\User;

/**
 * Who may administer a mission — and who may see its target sequence.
 *
 * `missions.configuration` holds the ordered list of structures a student is
 * meant to trace. MissionService exists to keep it server-side; the admin
 * editor is the one place it is legitimately rendered, for the same reason the
 * review queue may show an answer key: you cannot edit a sequence you cannot
 * see (docs/handovers/13-admin-content.md, invariant 4).
 */
final class MissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Mission $mission): bool
    {
        return $user->isAdmin();
    }

    /**
     * See `configuration.steps[].target*` for this mission.
     *
     * Distinct from view() so a mission listing that does not need the sequence
     * cannot acquire it by being in the admin area, and so the exception to
     * invariant 4 is greppable.
     */
    public function viewTargetSequence(User $user, Mission $mission): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Mission $mission): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, Mission $mission): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Mission $mission): bool
    {
        return $user->isAdmin();
    }
}
