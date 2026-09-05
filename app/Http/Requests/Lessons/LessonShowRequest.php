<?php

declare(strict_types=1);

namespace App\Http\Requests\Lessons;

/**
 * `GET /api/v1/lessons/{lesson}` and the lesson page.
 *
 * A read, so it validates nothing — the slug is a path segment and the
 * student is the session. It exists for `student()`: the response carries the
 * requesting student's progress, and this is what hands LessonService a typed
 * User instead of the service reaching for `auth()` (invariant 1).
 */
final class LessonShowRequest extends LessonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
