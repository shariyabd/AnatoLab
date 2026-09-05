<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\TutorTask;

/**
 * "Explain this" — the student has selected something and has no question of
 * their own yet.
 *
 * A structure or an organ is required: with neither, there is nothing to
 * explain, and the model would be free to pick a subject itself.
 */
final class TutorExplainRequest extends TutorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'structureId' => ['required_without:organSlug', 'nullable', 'integer', 'min:1'],
            'organSlug' => ['required_without:structureId', 'nullable', 'string', 'max:120'],
            'question' => ['nullable', 'string', 'max:'.self::MAX_QUESTION_LENGTH],
        ];
    }

    protected function task(): TutorTask
    {
        return TutorTask::Explain;
    }
}
