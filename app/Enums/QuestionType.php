<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a question is answered, and therefore how it is graded.
 *
 * The three cases are the three grading branches in AssessmentService, not a
 * presentation hint: `spatial` compares a structure id, `mcq` compares an
 * option id, `short_answer` compares text against a rubric. Adding a case here
 * without adding a branch there is a question no one can answer.
 */
enum QuestionType: string
{
    /** Pick one of several written options. */
    case Mcq = 'mcq';

    /** Click the structure on the model — the flagship type (PRD §12). */
    case Spatial = 'spatial';

    /** Free text, graded against the rubric in `questions.metadata`. */
    case ShortAnswer = 'short_answer';

    public function label(): string
    {
        return match ($this) {
            self::Mcq => 'Multiple choice',
            self::Spatial => 'Find it on the model',
            self::ShortAnswer => 'Short answer',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
