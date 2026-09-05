<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Question;
use Illuminate\Validation\Validator;

/**
 * `PUT /admin/questions/{question}`.
 *
 * Edited from the review queue as well as from the bank: a reviewer who spots
 * a wrong answer key fixes it here and then approves, rather than rejecting a
 * question that was one option away from correct.
 */
final class UpdateQuestionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $question = $this->route('question');

        return $question instanceof Question && ($this->user()?->can('update', $question) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            ...StoreQuestionRequest::sharedRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        StoreQuestionRequest::requireAnAnswerForTheType($validator, $this);
    }
}
