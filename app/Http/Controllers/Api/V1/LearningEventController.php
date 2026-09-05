<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\RecordEventsRequest;
use App\Services\Progress\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/events` — the client's batched analytics flush (PRD §29).
 *
 * Returns **202 Accepted**, and the status code is the contract: the batch has
 * been queued, not written. The browser flushes on an interval and on page
 * unload, so this response has to come back before the page goes away, and the
 * insert happens in `RecordLearningEvents` afterwards
 * (docs/architecture.md §13).
 *
 * Nothing is echoed back but a count. There is no resource to return — the log
 * is append-only and write-only from the client's point of view, and returning
 * ids would invite a client that tries to reference or amend them.
 *
 * Which event types are acceptable, how many, and how old, are all decided in
 * `RecordEventsRequest`; this method never sees `$request->all()`
 * (invariant 7).
 */
final class LearningEventController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function store(RecordEventsRequest $request): JsonResponse
    {
        $accepted = $this->analytics->record($request->student(), $request->toData());

        return new JsonResponse(['accepted' => $accepted], JsonResponse::HTTP_ACCEPTED);
    }
}
