<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DifficultyPreference;
use App\Enums\LessonStepType;
use App\Models\Lesson;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/lessons`.
 *
 * `content` is validated structurally, not just as "an array". LessonService
 * counts `content.steps` to compute a student's progress percentage, and a
 * malformed shape there is a division that produces a wrong number rather than
 * an error anyone notices.
 */
final class StoreLessonRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Lesson::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:lessons,slug'],
            ...self::sharedRules(),
        ];
    }

    /**
     * The rules the create and update forms share.
     *
     * @return array<string, mixed>
     */
    public static function sharedRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'objective' => ['required', 'string', 'max:2000'],
            'difficulty' => ['required', Rule::enum(DifficultyPreference::class)],
            'estimated_minutes' => ['required', 'integer', 'between:1,240'],

            'content' => ['required', 'array'],
            'content.steps' => ['required', 'array', 'min:1'],
            'content.steps.*.type' => ['required', Rule::enum(LessonStepType::class)],
            'content.steps.*.title' => ['required', 'string', 'max:255'],
            'content.steps.*.body' => ['nullable', 'string', 'max:20000'],
            // Names a structure by slug rather than id: a lesson authored
            // against one organ's seed data should survive a reseed, and the
            // slug is the stable identity (docs/handovers/06-lessons.md).
            'content.steps.*.structureSlug' => ['nullable', 'string', 'max:255'],
        ];
    }
}
