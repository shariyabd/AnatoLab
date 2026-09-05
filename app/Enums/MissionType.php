<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a mission's steps are validated, and therefore how it is scored.
 *
 * The two cases are the two validation branches in
 * App\Services\Assessment\MissionService, not a presentation hint — exactly as
 * QuestionType's three cases are AssessmentService's three grading branches.
 * Adding a case here without adding a branch there is a mission nobody can
 * complete.
 *
 * `compare` is deliberately absent. docs/handovers/09-missions.md offers
 * "identify or compare" as the second seeded type and this lane implements
 * `identify`; a case with no branch would be a promise the scorer cannot keep.
 */
enum MissionType: string
{
    /**
     * An ordered walk. Step *n* is graded against step *n*'s target, so the
     * same three structures clicked in the wrong order is a different result
     * from the right order — which is the whole point of tracing a pathway.
     */
    case TracePathway = 'trace_pathway';

    /**
     * A set to find, order irrelevant. A pick is graded against every target
     * not yet claimed, so a student who works right-to-left down the list is
     * not penalised for it.
     */
    case Identify = 'identify';

    public function label(): string
    {
        return match ($this) {
            self::TracePathway => 'Trace the pathway',
            self::Identify => 'Find them all',
        };
    }

    /** Whether the order the steps are answered in affects the score. */
    public function isOrdered(): bool
    {
        return $this === self::TracePathway;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
