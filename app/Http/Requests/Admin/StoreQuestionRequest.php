<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /admin/questions`.
 *
 * This is the one write form that accepts an answer key. It is validated here
 * and graded nowhere near the client — invariant 4 is about what leaves the
 * server, not about what an administrator may type in.
 */
final class StoreQuestionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Question::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            ...self::sharedRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sharedRules(): array
    {
        return [
            'type' => ['required', Rule::enum(QuestionType::class)],
            'question' => ['required', 'string', 'max:2000'],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'explanation' => ['nullable', 'string', 'max:5000'],

            // The answer to a spatial question.
            'correct_structure_id' => ['nullable', 'integer', 'exists:anatomical_structures,id'],

            'options' => ['sometimes', 'array', 'max:8'],
            'options.*.label' => ['required', 'string', 'max:8'],
            'options.*.value' => ['required', 'string', 'max:500'],
            'options.*.is_correct' => ['required', 'boolean'],

            // Present so an admin can park a question for review rather than
            // publishing it. Publishing itself still goes through the review
            // route, which is the only path that reaches `published`.
            'status' => ['sometimes', Rule::enum(QuestionStatus::class)],
        ];
    }

    /**
     * Cross-field rules the rule strings cannot express.
     */
    public function withValidator(Validator $validator): void
    {
        self::requireAnAnswerForTheType($validator, $this);
    }

    /**
     * A question must be answerable in the way its own type implies.
     *
     * Mirrors AssessmentService::isAnswerable(), one layer earlier: catching it
     * here turns "you cannot publish this" at the end of a review into a field
     * error on the form that created it.
     */
    public static function requireAnAnswerForTheType(Validator $validator, AdminRequest $request): void
    {
        $validator->after(static function (Validator $validator) use ($request): void {
            $type = $request->input('type');
            $options = $request->input('options', []);

            if ($type === QuestionType::Mcq->value) {
                $hasCorrect = is_array($options) && collect($options)
                    ->contains(static fn (mixed $option): bool => is_array($option)
                        && filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN));

                if (! $hasCorrect) {
                    $validator->errors()->add('options', 'A multiple-choice question needs exactly one correct option marked.');
                }
            }

            if ($type === QuestionType::Spatial->value && $request->input('correct_structure_id') === null) {
                $validator->errors()->add('correct_structure_id', 'A spatial question needs the structure that answers it.');
            }
        });
    }
}
