<?php

declare(strict_types=1);

namespace App\Services\Progress;

/**
 * Everything PRD §18's dashboard shows, for one student.
 *
 * Assembled per request and never cached. docs/architecture.md §11 is explicit
 * that personalised progress is not cached across users, and there is nothing
 * to gain from a per-user cache of a read that is already three indexed
 * queries — only a way for the number to be wrong right after an answer, which
 * is precisely acceptance criterion 1.
 */
final readonly class ProgressSummary
{
    /**
     * @param  list<SystemMastery>  $systems  every published system, weakest last
     * @param  list<ActivityEntry>  $recentActivity  newest first
     */
    public function __construct(
        /** Unweighted mean of the systems that have been attempted, 0–100. */
        public float $overallScore,
        public array $systems,
        public ?SystemMastery $strongest,
        public ?SystemMastery $needsPractice,
        public int $lessonsCompleted,
        public int $attempts,
        public int $correctAttempts,
        public array $recentActivity,
        public GamificationSummary $gamification,
        public ?Recommendation $recommendation,
    ) {}

    /** Quiz performance as a percentage, 0 when nothing has been attempted. */
    public function accuracyPercent(): float
    {
        if ($this->attempts <= 0) {
            return 0.0;
        }

        return round(100.0 * $this->correctAttempts / $this->attempts, 1);
    }
}
