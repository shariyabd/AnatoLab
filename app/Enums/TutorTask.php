<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which of the three tutor endpoints is being served.
 *
 * One service and one prompt builder handle all three; this is what they branch
 * on. An enum rather than three near-identical services because the pipeline —
 * context, retrieval, prompt, provider, validation, persistence — is identical
 * and only the instruction paragraph differs (docs/architecture.md §8.2).
 */
enum TutorTask: string
{
    /** A free-form question about what the student is looking at. */
    case Ask = 'ask';

    /** "Explain this structure", with no question of the student's own. */
    case Explain = 'explain';

    /**
     * A nudge toward an answer the student is working out.
     *
     * The tutor never states the answer here — correctness belongs to Handover
     * 07, and a hint that gives the answer away defeats the assessment.
     */
    case Hint = 'hint';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
