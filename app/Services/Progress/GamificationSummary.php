<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Models\Achievement;

/**
 * XP, level, streak and badges, as the dashboard shows them (PRD §16, §18).
 *
 * `xpIntoLevel` and `xpForLevel` are carried rather than left to the client to
 * derive: the level curve is a server-side rule, and a progress bar that
 * computes its own would drift the moment the curve changes.
 *
 * `newlyEarned` is the badges awarded by the run that produced this summary —
 * empty on a plain read. It exists so a later "you earned a badge" toast has
 * something to render without diffing two dashboard loads.
 */
final readonly class GamificationSummary
{
    /**
     * @param  list<Achievement>  $earned  every badge this student holds, newest first
     * @param  list<Achievement>  $newlyEarned  the subset awarded by this run
     */
    public function __construct(
        public int $xp,
        public int $level,
        /** XP earned since this level began. */
        public int $xpIntoLevel,
        /** XP this level spans, so the bar is `xpIntoLevel / xpForLevel`. */
        public int $xpForLevel,
        /** Consecutive days with activity, ending today or yesterday. */
        public int $streakDays,
        public array $earned = [],
        public array $newlyEarned = [],
    ) {}
}
