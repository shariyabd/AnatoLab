<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Lesson;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/lessons/{lesson}`.
 *
 * No `status`: publishing is its own route and its own ability, so saving an
 * edit can never publish a draft by accident.
 */
final class UpdateLessonRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && ($this->user()?->can('update', $lesson) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lesson = $this->route('lesson');

        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('lessons', 'slug')->ignore($lesson instanceof Lesson ? $lesson->getKey() : null),
            ],
            ...StoreLessonRequest::sharedRules(),
        ];
    }
}
