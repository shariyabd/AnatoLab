<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\LearningEventType;
use App\Jobs\AwardAchievements;
use App\Jobs\RecordLearningEvents;
use App\Models\LessonProgress;
use App\Services\Progress\LearningEventData;
use Carbon\CarbonImmutable;

/**
 * Writes the `lesson_started` and `lesson_completed` events (PRD §29).
 *
 * Same reasoning as AttemptObserver: `lesson_progress` belongs to Handover 06
 * and this lane reads it without modifying it or its service. Observing the
 * model puts the event exactly where it happens, and the observer is attached
 * in ProgressServiceProvider rather than by an attribute on the model, so no
 * file of Handover 06's is edited at all
 * (docs/handovers/parallel-execution-plan.md, batch D).
 *
 * **`created` and `updated`, not `saved`.** The row is written on every step
 * the student scrolls past, and `wasRecentlyCreated` stays true on the same
 * instance for every later save it makes — so a `saved` handler would emit
 * `lesson_started` once per step and make "lessons started" a count of
 * scrolling. The two events are exactly the two transitions: the row appearing,
 * and `completed_at` changing.
 *
 * Completion also re-runs the badge evaluation. "Complete three lessons" is a
 * criterion, and without this it would only be noticed the next time the
 * student answered a question.
 */
final class LessonProgressObserver
{
    public function created(LessonProgress $progress): void
    {
        $events = [new LearningEventData(
            type: LearningEventType::LessonStarted,
            occurredAt: CarbonImmutable::now(),
            contextType: 'lesson',
            contextId: $progress->lesson_id,
        )];

        // A lesson can be created already finished — a one-step lesson, or a
        // completion posted before any progress was.
        $completedAt = $progress->completed_at;

        if ($completedAt !== null) {
            $events[] = $this->completion($progress, CarbonImmutable::instance($completedAt));
        }

        RecordLearningEvents::dispatch($progress->user_id, $events);

        if ($completedAt !== null) {
            AwardAchievements::dispatch($progress->user_id);
        }
    }

    public function updated(LessonProgress $progress): void
    {
        $completedAt = $progress->completed_at;

        // `wasChanged` and not merely "is completed": re-opening a finished
        // lesson saves the row again without moving `completed_at`, and that is
        // not a second completion.
        if ($completedAt === null || ! $progress->wasChanged('completed_at')) {
            return;
        }

        RecordLearningEvents::dispatch($progress->user_id, [
            $this->completion($progress, CarbonImmutable::instance($completedAt)),
        ]);

        AwardAchievements::dispatch($progress->user_id);
    }

    private function completion(LessonProgress $progress, CarbonImmutable $at): LearningEventData
    {
        return new LearningEventData(
            type: LearningEventType::LessonCompleted,
            // The moment the lesson was finished, not the moment the queue
            // drained — `occurred_at` and `created_at` are separate columns
            // precisely so this stays true (App\Models\LearningEvent).
            occurredAt: $at,
            contextType: 'lesson',
            contextId: $progress->lesson_id,
        );
    }
}
