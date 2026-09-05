<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Models\BodySystem;
use Carbon\CarbonImmutable;

/**
 * One row of PRD §15's per-system table.
 *
 * Carries the counts as well as the score because the dashboard says *why* a
 * percentage is what it is — "3 of 11 structures seen" next to "51%" is the
 * difference between a number a student can act on and one they cannot.
 *
 * A system the student has never touched still gets one of these, scored zero.
 * PRD §15 shows the whole body, not the parts already visited, and a system
 * missing from the table is indistinguishable from one that does not exist.
 */
final readonly class SystemMastery
{
    public function __construct(
        public BodySystem $system,
        public float $score,
        public int $attempts,
        public int $correctAttempts,
        public int $coveredStructures,
        public int $totalStructures,
        public ?CarbonImmutable $lastActivityAt,
    ) {}

    public function attempted(): bool
    {
        return $this->attempts > 0;
    }
}
