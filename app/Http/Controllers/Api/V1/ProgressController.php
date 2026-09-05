<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\ProgressRequest;
use App\Http\Resources\Progress\ProgressSummaryResource;
use App\Http\Resources\Progress\RecommendationResource;
use App\Http\Resources\Progress\SystemMasteryResource;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The three progress reads (docs/architecture.md §7).
 *
 *   GET /api/v1/progress                  overall, systems, activity, badges
 *   GET /api/v1/progress/systems          the per-system table alone
 *   GET /api/v1/progress/recommendations  what to do next, and why
 *
 * **Nothing here computes mastery.** Every score returned was written by
 * `RecalculateMastery` in a queued job; these endpoints read rows
 * (acceptance criterion 5, docs/architecture.md §10). If a score looks stale
 * the fix is in the job, never a recalculation here.
 *
 * Scoped to the authenticated student and to nobody else. There is no user
 * parameter on any of these routes, in the path or the query, so there is no
 * shape of request that asks for someone else's progress — which is why none
 * of them needs a policy (docs/architecture.md §14).
 *
 * Thin by construction: a FormRequest in, a service call, a Resource out
 * (invariant 7). The clock is read here and passed down, so the services stay
 * testable against a fixed moment.
 */
final class ProgressController extends Controller
{
    public function __construct(private readonly ProgressService $progress) {}

    public function index(ProgressRequest $request): ProgressSummaryResource
    {
        return new ProgressSummaryResource($this->progress->summaryFor(
            $request->student(),
            CarbonImmutable::now(),
            $request->currentOrganSlug(),
        ));
    }

    public function systems(ProgressRequest $request): AnonymousResourceCollection
    {
        return SystemMasteryResource::collection(
            $this->progress->systemMastery($request->student())
        );
    }

    /**
     * A student with nothing left to do gets `data: null`, not a 404.
     *
     * "There is no recommendation" is a real answer to a question that was
     * asked correctly — every published organ is finished, or none is
     * published yet — and a 404 would make the dashboard render an error where
     * it should render an empty card.
     */
    public function recommendations(ProgressRequest $request): RecommendationResource|JsonResponse
    {
        $recommendation = $this->progress->recommendFor(
            $request->student(),
            CarbonImmutable::now(),
            $request->currentOrganSlug(),
        );

        return $recommendation === null
            ? new JsonResponse(['data' => null])
            : new RecommendationResource($recommendation);
    }
}
