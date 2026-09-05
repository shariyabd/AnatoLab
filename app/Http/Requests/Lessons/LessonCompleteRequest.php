<?php

declare(strict_types=1);

namespace App\Http\Requests\Lessons;

/**
 * `POST /api/v1/lessons/{lesson}/complete`.
 *
 * Takes no input at all: which lesson is in the path, which student is in the
 * session, and there is nothing else to decide. The empty rule set is the
 * point — anything a client sends is discarded rather than reaching the
 * service.
 */
final class LessonCompleteRequest extends LessonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
