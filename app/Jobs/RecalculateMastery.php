<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Progress\MasteryService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompute a student's mastery after an attempt (docs/architecture.md §10).
 *
 * Handover 07 created this job as a no-op and dispatched it from
 * `AssessmentService`; Handover 09 dispatches it from the same service for
 * mission steps. This handover owns the body from here
 * (docs/handovers/parallel-execution-plan.md D4). Nothing about either
 * dispatch site changed to make it work, which was the point of shipping the
 * empty job first.
 *
 * **Queued, and the only place mastery is ever computed.** No controller, no
 * resource, no page load recalculates: acceptance criterion 5 of
 * docs/handovers/10-progress-mastery.md, and §10's "never in the request".
 * The dashboard reads `learning_mastery` rows and nothing else.
 *
 * `$attemptId` is not read. It is kept because it is the dispatch signature two
 * other lanes already call, and because it is what a failed-job payload needs
 * to be traceable back to the answer that triggered it —
 * `MasteryService::recalculate()` rebuilds every topic for the student rather
 * than following one attempt, for the reasons documented there. A deleted
 * attempt is therefore not an error: the recalculation simply no longer sees
 * it, which is the correct outcome.
 *
 * `AwardAchievements` is chained after, not dispatched beside: badges are
 * thresholds on the mastery this job has just written.
 */
final class RecalculateMastery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  int  $userId  whose mastery is stale
     * @param  int  $attemptId  the attempt that made it stale — carried for
     *                          traceability; see the class docblock
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $attemptId,
    ) {
        $this->onQueue('default');
    }

    public function handle(MasteryService $mastery): void
    {
        $student = User::query()->find($this->userId);

        // The account was deleted between dispatch and execution. Its attempts
        // and mastery rows cascaded with it; there is nothing to recompute.
        if (! $student instanceof User) {
            return;
        }

        // The clock is read here, once, and handed down. Everything below this
        // line — including the formula itself — takes the moment as an
        // argument, which is what makes the recency decay testable
        // (App\Services\Progress\MasteryCalculator).
        $mastery->recalculate($student, CarbonImmutable::now());

        AwardAchievements::dispatch($this->userId);
    }
}
