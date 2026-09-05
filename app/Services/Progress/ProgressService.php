<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\LearningEventType;
use App\Enums\OrganStatus;
use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningEvent;
use App\Models\LearningMastery;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assembles the progress dashboard (PRD §18).
 *
 * **Reads only.** Nothing here computes a mastery score — every number comes
 * from `learning_mastery` rows that `RecalculateMastery` has already written
 * in a queued job. That is acceptance criterion 5 of
 * docs/handovers/10-progress-mastery.md and docs/architecture.md §10's "never
 * in the request", and it is the reason this class holds a
 * `RecommendationService` but not a `MasteryCalculator`.
 *
 * Scoped to the `User` it is handed, which the controller takes from the
 * authenticated request (invariant 1). There is no method here that can read
 * another student's progress, and none that takes a user id.
 *
 * Never cached. docs/architecture.md §11: personalised progress is not cached
 * across users, and a per-user cache would only make the number stale straight
 * after an answer.
 */
final class ProgressService
{
    /** PRD §18's "recent activity" is a glance, not a log. */
    private const ACTIVITY_LIMIT = 12;

    public function __construct(
        private readonly GamificationService $gamification,
        private readonly RecommendationService $recommendations,
    ) {}

    public function summaryFor(User $student, CarbonImmutable $asOf, ?string $currentOrganSlug = null): ProgressSummary
    {
        $systems = $this->systemMastery($student);

        $attempted = array_values(array_filter(
            $systems,
            static fn (SystemMastery $system): bool => $system->attempted(),
        ));

        // The mean over systems the student has actually worked in. Averaging
        // in the untouched ones would make "overall mastery" fall every time
        // an admin publishes a new body system, which is not something the
        // student did.
        $overall = $attempted === []
            ? 0.0
            : round(array_sum(array_map(
                static fn (SystemMastery $system): float => $system->score,
                $attempted,
            )) / count($attempted), 2);

        $strongest = null;
        $weakest = null;

        foreach ($attempted as $system) {
            if ($strongest === null || $system->score > $strongest->score) {
                $strongest = $system;
            }

            if ($weakest === null || $system->score < $weakest->score) {
                $weakest = $system;
            }
        }

        return new ProgressSummary(
            overallScore: $overall,
            systems: $systems,
            strongest: $strongest,
            needsPractice: $weakest,
            lessonsCompleted: LessonProgress::query()
                ->where('user_id', $student->getKey())
                ->completed()
                ->count(),
            attempts: Attempt::query()->forUser($student)->count(),
            correctAttempts: Attempt::query()->forUser($student)->where('is_correct', true)->count(),
            recentActivity: $this->recentActivity($student),
            gamification: $this->gamification->summarise($student, $asOf),
            recommendation: $this->recommendations->recommend($student, $asOf, $currentOrganSlug),
        );
    }

    /**
     * The next activity on its own, for the endpoint that asks for only that.
     *
     * A one-line delegation, and worth it: the controller depends on one
     * service rather than two, so "the dashboard's data" has a single entry
     * point and a later cache or reordering has one place to live.
     */
    public function recommendFor(User $student, CarbonImmutable $asOf, ?string $currentOrganSlug = null): ?Recommendation
    {
        return $this->recommendations->recommend($student, $asOf, $currentOrganSlug);
    }

    /**
     * PRD §15's per-system table: every published system, scored.
     *
     * Every system, not only the attempted ones — a system missing from the
     * list is indistinguishable from one that does not exist, and "Nervous
     * System 0%" is the row that makes a student click it.
     *
     * Systems with no published organ are left out. They have no content
     * behind them, so a score would be a statement about nothing.
     *
     * @return list<SystemMastery>
     */
    public function systemMastery(User $student): array
    {
        /** @var Collection<int, BodySystem> $systems */
        $systems = BodySystem::query()
            // Published organs only: a system whose content is all draft has
            // nothing behind its percentage.
            // Compared against the enum rather than through Organ's own
            // `published()` scope: `whereHas` hands the closure an untyped
            // builder, and a scope call on it is unverifiable.
            ->whereHas('organs', static fn (Builder $query): Builder => $query->where(
                'status',
                OrganStatus::Published,
            ))
            ->orderBy('name')
            ->get();

        if ($systems->isEmpty()) {
            return [];
        }

        $rows = LearningMastery::query()
            ->forUser($student)
            ->ofType(TopicType::System)
            ->get()
            ->keyBy(static fn (LearningMastery $row): int => $row->topic_id);

        $result = [];

        foreach ($systems as $system) {
            $row = $rows->get((int) $system->getKey());

            $result[] = $row instanceof LearningMastery
                ? new SystemMastery(
                    system: $system,
                    score: $row->score(),
                    attempts: $row->attempts,
                    correctAttempts: $row->correct_attempts,
                    coveredStructures: $row->covered_structures,
                    totalStructures: $row->total_structures,
                    lastActivityAt: $row->last_activity_at === null
                        ? null
                        : CarbonImmutable::instance($row->last_activity_at),
                )
                : new SystemMastery(
                    system: $system,
                    score: 0.0,
                    attempts: 0,
                    correctAttempts: 0,
                    coveredStructures: 0,
                    totalStructures: 0,
                    lastActivityAt: null,
                );
        }

        return $result;
    }

    /**
     * The last few things this student did, as finished sentences.
     *
     * Context labels are resolved in three batched reads — one per context
     * table the window actually references — rather than one per row. A
     * dashboard that N+1s over an activity feed is exactly the slow demo
     * docs/engineering.md §10 warns about, and `Model::shouldBeStrict` would
     * not catch it because these are separate queries, not lazy relations.
     *
     * @return list<ActivityEntry>
     */
    private function recentActivity(User $student): array
    {
        $events = LearningEvent::query()
            ->forUser($student)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        if ($events->isEmpty()) {
            return [];
        }

        $labels = $this->contextLabels($events);

        $entries = [];

        foreach ($events as $event) {
            $context = $event->context_type === null || $event->context_id === null
                ? null
                : ($labels["{$event->context_type}:{$event->context_id}"] ?? null);

            $entries[] = new ActivityEntry(
                type: $event->event_type,
                label: $this->describe($event->event_type, $context),
                occurredAt: CarbonImmutable::instance($event->occurred_at),
            );
        }

        return $entries;
    }

    /**
     * "type:id" → a human name, for the contexts this window references.
     *
     * Questions are deliberately absent. A question's text is its prose, and
     * echoing it into an activity feed would put "Which chamber pumps blood
     * into the aorta?" on a page the student can screenshot next to the
     * answer they gave. The feed says "Answered a question" instead.
     *
     * @param  Collection<int, LearningEvent>  $events
     * @return array<string, string>
     */
    private function contextLabels(Collection $events): array
    {
        $ids = ['organ' => [], 'structure' => [], 'lesson' => []];

        foreach ($events as $event) {
            if ($event->context_type !== null
                && $event->context_id !== null
                && array_key_exists($event->context_type, $ids)) {
                $ids[$event->context_type][$event->context_id] = true;
            }
        }

        $labels = [];

        if ($ids['organ'] !== []) {
            foreach (Organ::query()->whereKey(array_keys($ids['organ']))->get() as $organ) {
                $labels["organ:{$organ->getKey()}"] = $organ->name;
            }
        }

        if ($ids['structure'] !== []) {
            foreach (AnatomicalStructure::query()->whereKey(array_keys($ids['structure']))->get() as $structure) {
                $labels["structure:{$structure->getKey()}"] = $structure->name;
            }
        }

        if ($ids['lesson'] !== []) {
            foreach (Lesson::query()->whereKey(array_keys($ids['lesson']))->get() as $lesson) {
                $labels["lesson:{$lesson->getKey()}"] = $lesson->title;
            }
        }

        return $labels;
    }

    /**
     * One line of prose per event type.
     *
     * Reports learning outcomes rather than page views (PRD §30): "Answered a
     * question" and "Finished a lesson" are things a student did, where
     * "viewed /explore/heart" would be a thing a browser did.
     */
    private function describe(LearningEventType $type, ?string $context): string
    {
        $suffix = $context === null ? '' : " · {$context}";

        return match ($type) {
            LearningEventType::OrganViewed => "Opened an organ{$suffix}",
            LearningEventType::StructureSelected => "Looked at a structure{$suffix}",
            LearningEventType::StructureIsolated => "Isolated a structure{$suffix}",
            LearningEventType::LayerChanged => 'Changed the viewer layer',
            LearningEventType::LessonStarted => "Started a lesson{$suffix}",
            LearningEventType::LessonCompleted => "Finished a lesson{$suffix}",
            LearningEventType::QuestionAnswered => 'Answered a question',
            LearningEventType::HintRequested => 'Used a hint',
            LearningEventType::MissionStarted => 'Started a mission',
            LearningEventType::MissionCompleted => 'Completed a mission',
            LearningEventType::AiQuestionAsked => 'Asked the tutor a question',
            LearningEventType::SimulationStarted => 'Started a simulation',
            LearningEventType::SimulationCompleted => 'Completed a simulation',
        };
    }
}
