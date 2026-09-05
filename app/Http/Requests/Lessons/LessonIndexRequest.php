<?php

declare(strict_types=1);

namespace App\Http\Requests\Lessons;

use App\Enums\DifficultyPreference;
use App\Services\Lessons\LessonFilters;
use Illuminate\Validation\Rule;

/**
 * The lesson library's three filters (docs/handovers/06-lessons.md).
 *
 * A read endpoint with a FormRequest, which is unusual here — the anatomy
 * reads have none because nothing arrives but a path segment. These filters do
 * arrive from the client, so an unknown difficulty is a 422 rather than a
 * silently empty list, and the controller never touches the query string.
 */
final class LessonIndexRequest extends LessonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Slugs, matching how Handover 03's read API addresses an organ.
            'organ' => ['nullable', 'string', 'max:120'],
            'system' => ['nullable', 'string', 'max:120'],
            'difficulty' => ['nullable', Rule::enum(DifficultyPreference::class)],
        ];
    }

    public function filters(): LessonFilters
    {
        return LessonFilters::fromValidated($this->validated());
    }
}
