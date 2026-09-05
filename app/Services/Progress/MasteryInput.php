<?php

declare(strict_types=1);

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * Everything `MasteryCalculator` needs to score one topic, and nothing else.
 *
 * This object is the reason the calculator can be pure. It carries counts and
 * two timestamps — no models, no query builder, no `now()`. `asOf` is passed
 * in rather than read from the clock precisely so the recency decay is
 * testable: "this student last practised 28 days ago" is a fixture, not a
 * sleep (docs/architecture.md §10).
 *
 * Every field is a raw input to the formula:
 *
 *   accuracy      ← attempts, correctAttempts
 *   recency       ← lastActivityAt, asOf
 *   hint_penalty  ← attempts, hintedAttempts
 *   coverage      ← coveredStructures, totalStructures
 */
final readonly class MasteryInput
{
    public function __construct(
        /** Graded answers on this topic. Zero is a valid, and meaningful, value. */
        public int $attempts,
        public int $correctAttempts,
        public int $hintedAttempts,
        /** Distinct structures within the topic the student has attempted. */
        public int $coveredStructures,
        /**
         * Published structures the topic contains. Zero for a topic that has
         * none — an organ quizzed only by multiple choice, for instance — and
         * the calculator treats that as fully covered rather than uncovered.
         */
        public int $totalStructures,
        /** Null when the student has never attempted this topic. */
        public ?CarbonImmutable $lastActivityAt,
        /** The moment the score is being computed for. */
        public CarbonImmutable $asOf,
    ) {}

    /**
     * A topic the student has never touched.
     *
     * Named because it is the shape the recommendation asks about most often:
     * "how strong is this student on a system they have not opened?" The
     * answer is zero, and it needs a constructor that does not make the caller
     * spell out four zeroes to say so.
     */
    public static function untouched(CarbonImmutable $asOf, int $totalStructures = 0): self
    {
        return new self(
            attempts: 0,
            correctAttempts: 0,
            hintedAttempts: 0,
            coveredStructures: 0,
            totalStructures: $totalStructures,
            lastActivityAt: null,
            asOf: $asOf,
        );
    }
}
