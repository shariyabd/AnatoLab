<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\LearningEventType;
use App\Enums\QuestionStatus;
use App\Enums\TopicType;
use App\Jobs\RecordLearningEvents;
use App\Models\LearningEvent;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The entry point for everything written to `learning_events`.
 *
 * Thin on purpose. The validation that matters — which event types a browser
 * may assert, how large a batch may be, how old an event may be — is in
 * App\Http\Requests\Progress\RecordEventsRequest, because it is validation of
 * a request; and the insert is in a queued job, because analytics must not sit
 * on the critical path of the thing it measures (docs/architecture.md §13).
 * What is left is the seam both server-side emitters and the client batch pass
 * through, so there is one place to look for "who writes the log".
 *
 * Takes the User as an argument and reads no request state (invariant 1).
 */
final class AnalyticsService
{
    /**
     * The largest batch accepted from one flush.
     *
     * A student clicking through structures generates a handful of events a
     * minute; fifty is a comfortable ceiling for a flush after a long idle tab
     * and small enough that a single insert stays a single insert.
     */
    public const MAX_BATCH = 50;

    /**
     * Queue a batch for the log. Returns how many were accepted.
     *
     * @param  list<LearningEventData>  $events
     */
    public function record(User $student, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        RecordLearningEvents::dispatch((int) $student->getKey(), $events);

        return count($events);
    }

    /*
    |---------------------------------------------------------------------------
    | Aggregate reads for the admin analytics view (Handover 13)
    |---------------------------------------------------------------------------
    |
    | Read-only, and no new tables: the numbers come from `learning_events` and
    | `learning_mastery`, which F10 already writes (PRD §19, §29).
    |
    | Aggregated with Eloquent `count()` per bucket rather than a GROUP BY with
    | selectRaw. Invariant 6 keeps raw SQL out of app/, and the shape of the
    | data makes the cost a non-issue: `learning_events` is indexed on
    | (event_type, occurred_at) precisely for "count these events over this
    | window", the number of buckets is the number of enum cases, and this page
    | is opened by administrators rather than by students.
    |
    | It is deliberately not the alternative of loading the rows and counting
    | them in PHP: that is one query instead of thirteen, but its memory grows
    | with the log, which is the thing that grows fastest in this schema.
    */

    /**
     * Platform-wide learning activity over a window.
     *
     * `$since` and `$until` are passed in rather than derived from "now" so the
     * caller owns the clock — the same reason every method in ProgressService
     * takes an `$asOf` (invariant 1, and it makes this testable without
     * travelling time).
     */
    public function overview(CarbonImmutable $since, CarbonImmutable $until): AnalyticsOverview
    {
        $inWindow = static fn (): Builder => LearningEvent::query()
            ->whereBetween('occurred_at', [$since, $until]);

        $eventCounts = [];

        foreach (LearningEventType::cases() as $type) {
            $count = $inWindow()->where('event_type', $type)->count();

            // Only types that actually happened. A table of thirteen rows of
            // which eleven are zero buries the two numbers worth reading.
            if ($count > 0) {
                $eventCounts[$type->value] = $count;
            }
        }

        arsort($eventCounts);

        return new AnalyticsOverview(
            since: $since,
            until: $until,
            activeLearners: $inWindow()->distinct()->count('user_id'),
            totalEvents: array_sum($eventCounts),
            eventCounts: $eventCounts,
            dailyActivity: $this->dailyActivity($since, $until),
            masteryByOrgan: $this->masteryByOrgan(),
            questionsAwaitingReview: Question::query()
                ->where('status', QuestionStatus::Review)
                ->where('generated_by_ai', true)
                ->count(),
        );
    }

    /**
     * Events per day across the window, zero-filled.
     *
     * Zero-filled because a sparkline with missing days lies about the shape of
     * the trend — a quiet Sunday should read as a dip, not as a shorter week.
     *
     * Capped at 90 buckets: this is one indexed count per day, and a caller
     * asking for two years of daily detail wants a report, not a dashboard.
     *
     * @return list<array{date: string, events: int}>
     */
    private function dailyActivity(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $days = min((int) $since->startOfDay()->diffInDays($until->startOfDay()) + 1, 90);

        $activity = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $since->startOfDay()->addDays($offset);

            $activity[] = [
                'date' => $day->toDateString(),
                'events' => LearningEvent::query()
                    ->whereBetween('occurred_at', [$day, $day->endOfDay()])
                    ->count(),
            ];
        }

        return $activity;
    }

    /**
     * Average mastery per organ, and how many learners it is averaged over.
     *
     * The learner count travels with the score deliberately: 0.9 across two
     * students and 0.9 across two hundred are different facts, and an average
     * shown without its denominator invites reading the first as the second.
     *
     * `mastery_score` is a decimal column and arrives as a string; it is cast
     * once here so the page never has to.
     *
     * @return list<array{organ: string, mastery: float, learners: int}>
     */
    private function masteryByOrgan(): array
    {
        $rows = LearningMastery::query()
            ->where('topic_type', TopicType::Organ)
            ->get(['topic_id', 'mastery_score']);

        if ($rows->isEmpty()) {
            return [];
        }

        $organs = Organ::query()
            ->whereIn('id', $rows->pluck('topic_id')->unique()->all())
            ->pluck('name', 'id');

        $byOrgan = [];

        foreach ($rows as $row) {
            $name = $organs->get($row->topic_id);

            // A mastery row can outlive the organ it names — `topic_id` carries
            // no foreign key by design (see the migration). Skip rather than
            // label a bar "unknown".
            if (! is_string($name)) {
                continue;
            }

            $byOrgan[$name][] = (float) $row->mastery_score;
        }

        $summary = [];

        foreach ($byOrgan as $name => $scores) {
            $summary[] = [
                'organ' => $name,
                'mastery' => round(array_sum($scores) / count($scores), 3),
                'learners' => count($scores),
            ];
        }

        usort($summary, static fn (array $a, array $b): int => $b['mastery'] <=> $a['mastery']);

        return $summary;
    }
}
