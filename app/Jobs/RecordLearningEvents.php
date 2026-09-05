<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LearningEvent;
use App\Models\User;
use App\Services\Progress\LearningEventData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Append a batch of events to the learning log (docs/architecture.md §13).
 *
 * Queued and on `default`, as §11 allocates it. Analytics must never be on the
 * critical path of the thing it is measuring: a student answering a question
 * waits for the verdict, not for a log write, and the browser's batched flush
 * on page unload has to return before the page goes away.
 *
 * One insert for the whole batch rather than one per event. A flush of fifty
 * viewer interactions is a single round trip, and the log is append-only, so
 * there is nothing to reconcile row by row.
 *
 * Takes the user id and plain event data rather than models. `SerializesModels`
 * would re-query the User on unserialise and drop the whole batch if the
 * account were deleted in between — which is the correct outcome, but this way
 * it is an explicit check rather than a failed job.
 */
final class RecordLearningEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<LearningEventData>  $events
     */
    public function __construct(
        private readonly int $userId,
        private readonly array $events,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if ($this->events === []) {
            return;
        }

        // The account went away between dispatch and execution. The foreign
        // key would reject the insert; returning is the same outcome without
        // the failed job.
        if (! User::query()->whereKey($this->userId)->exists()) {
            return;
        }

        $recordedAt = CarbonImmutable::now();

        LearningEvent::query()->insert(array_map(
            fn (LearningEventData $event): array => $event->toRow($this->userId, $recordedAt),
            $this->events,
        ));
    }
}
