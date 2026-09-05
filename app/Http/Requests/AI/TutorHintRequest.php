<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\TutorTask;

/**
 * A nudge while the student is working something out.
 *
 * `question` carries what they are stuck on — a quiz prompt, in their own
 * words. It is optional because a student can also be stuck on the structure
 * itself with nothing to quote.
 *
 * The hint never states an answer. That is a prompt instruction (PromptBuilder)
 * rather than something this request can enforce, and it matters because
 * correctness belongs to Handover 07: a tutor that resolves a question has
 * scored it.
 */
final class TutorHintRequest extends TutorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'question' => ['nullable', 'string', 'max:'.self::MAX_QUESTION_LENGTH],
        ];
    }

    protected function task(): TutorTask
    {
        return TutorTask::Hint;
    }
}
