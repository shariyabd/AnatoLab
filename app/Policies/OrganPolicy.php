<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organ;
use App\Models\User;

/**
 * Who may administer an organ.
 *
 * The `admin` middleware answers "may you be in /admin at all"; this answers
 * "may you do this to this record". Both are required, and neither substitutes
 * for the other (docs/architecture.md §14, docs/engineering.md §10).
 *
 * That is not ceremony. The middleware is attached to a route group, so a route
 * added to the wrong group loses the check silently; the policy is attached to
 * the *resource*, so an organ cannot be written from a controller that forgot
 * to authorize — `authorize()` throws rather than defaulting to allow.
 */
final class OrganPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admins see draft organs; students reach organs through
     * AnatomyService::findPublishedOrganBySlug(), which filters by status and
     * never consults a policy. This ability is the admin listing's, not the
     * viewer's.
     */
    public function view(User $user, Organ $organ): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Organ $organ): bool
    {
        return $user->isAdmin();
    }

    /**
     * Publishing is separated from updating because it is the act that puts
     * content in front of a student. Today both require the same role; keeping
     * them distinct means a future reviewer role can edit without publishing
     * without every call site having to be found again.
     */
    public function publish(User $user, Organ $organ): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Organ $organ): bool
    {
        return $user->isAdmin();
    }
}
