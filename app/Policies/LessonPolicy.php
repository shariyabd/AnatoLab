<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

/**
 * Who may administer a lesson.
 *
 * Only the admin side lives here. A student's access to a lesson is decided by
 * LessonService, which serves published lessons on published organs and never
 * asks a policy — so there is no student ability to grant here by accident
 * (docs/handovers/06-lessons.md).
 */
final class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }
}
