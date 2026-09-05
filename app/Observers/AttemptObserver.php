<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\LearningEventType;
use App\Jobs\RecordLearningEvents;
use App\Models\Attempt;
use App\Services\Progress\LearningEventData;
use Carbon\CarbonImmutable;

/**
 * Writes the `question_answered` and `hint_requested` events (PRD §29).
 *
 * An observer rather than a call inside `AssessmentService` for one reason:
 * that service belongs to Handover 07 and Handover 09 is editing it in
 * parallel (docs/handovers/parallel-execution-plan.md, batch D). Observing the
 * model records the event at the moment it happens — which is what
 * docs/architecture.md §13 asks for — without this lane touching another
 * lane's file, and it catches every writer of the table at once: a quiz
 * answer, a mission step, and anything later, with no dispatch site to
 * remember.
 *
 * The write itself is queued (`RecordLearningEvents`). Analytics never sits on
 * the critical path of the thing it measures; a student waits for the verdict,
 * not for the log.
 *
 * The payload says `outcome: correct|incorrect` rather than carrying a boolean
 * named after the column. The wording is what an activity feed shows, and it
 * keeps the strings `/boundary-audit` greps for out of anything that could end
 * up serialised toward the client (invariant 4).
 */
final class AttemptObserver
{
    public function created(Attempt $attempt): void
    {
        $at = $attempt->created_at === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($attempt->created_at);

        // The question, or failing that the structure the answer was about —
        // the same order App\Services\Progress\MasteryService attributes in, so
        // an event and a mastery row agree on what the attempt was about.
        [$contextType, $contextId] = match (true) {
            $attempt->question_id !== null => ['question', $attempt->question_id],
            $attempt->selected_structure_id !== null => ['structure', $attempt->selected_structure_id],
            default => [null, null],
        };

        $events = [
            new LearningEventData(
                type: LearningEventType::QuestionAnswered,
                occurredAt: $at,
                contextType: $contextType,
                contextId: $contextId,
                payload: [
                    'outcome' => $attempt->is_correct ? 'correct' : 'incorrect',
                    'timeSpentMs' => $attempt->time_spent_ms,
                ],
            ),
        ];

        // A separate event rather than a flag on the one above: PRD §30 counts
        // hint usage as its own metric, and counting rows beats filtering a
        // JSON payload.
        if ($attempt->hint_used) {
            $events[] = new LearningEventData(
                type: LearningEventType::HintRequested,
                occurredAt: $at,
                contextType: $contextType,
                contextId: $contextId,
            );
        }

        RecordLearningEvents::dispatch($attempt->user_id, $events);
    }
}
