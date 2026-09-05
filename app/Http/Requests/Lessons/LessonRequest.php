<?php

declare(strict_types=1);

namespace App\Http\Requests\Lessons;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared plumbing for the lesson endpoints.
 *
 * Its one job is turning the `auth` middleware's guarantee into a typed `User`
 * the service signature can rely on, instead of a nullable Authenticatable.
 * That is what lets LessonService take the student as an argument and never
 * call `auth()` (invariant 1).
 *
 * Note what is *not* accepted by any subclass: a user id. Progress is keyed on
 * the authenticated student and on nothing the client can send, which is why
 * "a student cannot complete another student's lesson" has no policy to check
 * — there is no parameter to check it against
 * (docs/handovers/06-lessons.md, Tests).
 */
abstract class LessonRequest extends FormRequest
{
    /**
     * Authorization here is the `auth` middleware plus the scoping above. The
     * lesson itself is resolved by published status in LessonService, so an
     * unpublished lesson is a 404 before any of this runs.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @throws AuthenticationException
     */
    public function student(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
