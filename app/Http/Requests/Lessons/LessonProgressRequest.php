<?php

declare(strict_types=1);

namespace App\Http\Requests\Lessons;

/**
 * `POST /api/v1/lessons/{lesson}/progress` — the student reached a step.
 *
 * Beyond the three endpoints docs/handovers/06-lessons.md lists, and
 * deliberately: acceptance criterion 2 is that progress persists and survives
 * a refresh, and a lesson that only reports its own completion leaves
 * `lesson_progress.progress_percent` permanently 0 or 100. Handover 10 reads
 * that column.
 *
 * The client sends the step it reached, not the percentage. Percentages are
 * derived server-side from the lesson's own step count, so a client cannot
 * claim 100% on step one — the same reason grading never happens on the
 * client (docs/architecture.md §5.4 rule 2).
 */
final class LessonProgressRequest extends LessonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Zero-based. The upper bound is the service's, which clamps to
            // the lesson's real step count; this one only keeps an absurd
            // value out of an integer column.
            'stepIndex' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function stepIndex(): int
    {
        return (int) $this->validated('stepIndex');
    }
}
