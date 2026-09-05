<?php

declare(strict_types=1);

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * What the admin analytics page shows (PRD §19, §29, §30).
 *
 * Aggregate only. Nothing here identifies a student — the page answers "is the
 * platform teaching anyone anything", and a named per-student breakdown is
 * teacher functionality, which PRD §19 defers to Phase 2.
 *
 * Readonly, like every other DTO crossing a service boundary here
 * (docs/engineering.md §2).
 */
final readonly class AnalyticsOverview
{
    /**
     * @param  array<string, int>  $eventCounts  event type => occurrences in the window
     * @param  list<array{date: string, events: int}>  $dailyActivity
     * @param  list<array{organ: string, mastery: float, learners: int}>  $masteryByOrgan
     */
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public int $activeLearners,
        public int $totalEvents,
        public array $eventCounts,
        public array $dailyActivity,
        public array $masteryByOrgan,
        public int $questionsAwaitingReview,
    ) {}
}
