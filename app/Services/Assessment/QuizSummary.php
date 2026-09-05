<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\Organ;

/**
 * One card in the quiz picker: an organ, and how many questions it can ask.
 *
 * A DTO rather than an Organ with a `questions_count` attribute, because there
 * is no `Organ::questions()` relation to count through — `App\Models\Organ`
 * belongs to the Anatomy lane and this lane does not edit it
 * (docs/engineering.md §7). The count is computed alongside the organ in
 * AssessmentService and carried here.
 */
final readonly class QuizSummary
{
    public function __construct(
        public Organ $organ,
        public int $questionCount,
    ) {}
}
