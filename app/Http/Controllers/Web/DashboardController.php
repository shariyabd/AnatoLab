<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\ProgressRequest;
use App\Http\Resources\Progress\ProgressSummaryResource;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The progress dashboard (PRD §18).
 *
 * Handover 01 registered `/dashboard` and left this controller empty for this
 * lane to fill, which is why the progress page lives here rather than at a new
 * URL of its own: two routes for one screen would mean two navigation entries
 * pointing at the same content.
 *
 * The whole summary arrives as a page prop rather than being fetched after
 * mount, and it travels through the same `ProgressSummaryResource` the API
 * uses — one shaping code path with one set of tests, for the reason
 * Handover 07's quiz page documents at length.
 *
 * **It reads; it does not compute.** Every score on this page was written by
 * `RecalculateMastery` in a queued job (acceptance criterion 5,
 * docs/architecture.md §10). Nothing on this request path touches
 * `MasteryCalculator`.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly ProgressService $progress) {}

    public function __invoke(ProgressRequest $request): Response
    {
        return Inertia::render('Dashboard', [
            'progress' => fn (): array => self::payload(new ProgressSummaryResource(
                $this->progress->summaryFor(
                    $request->student(),
                    CarbonImmutable::now(),
                    $request->currentOrganSlug(),
                )
            )),
        ]);
    }

    /**
     * A Resource as the plain array the page should receive.
     *
     * Same helper and same reasoning as `QuizController::payload()`: Inertia
     * resolves a JsonResource through its HTTP response, and encoding here
     * settles the nested collections before it can wrap them in `data`.
     *
     * @return array<string, mixed>
     */
    private static function payload(JsonResource $resource): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
