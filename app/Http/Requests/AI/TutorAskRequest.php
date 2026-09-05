<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\TutorTask;

/**
 * A free-form question about what the student is looking at (PRD §9).
 */
final class TutorAskRequest extends TutorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'question' => ['required', 'string', 'min:3', 'max:'.self::MAX_QUESTION_LENGTH],
        ];
    }

    protected function task(): TutorTask
    {
        return TutorTask::Ask;
    }
}
