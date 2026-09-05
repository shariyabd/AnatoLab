<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Services\Progress\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Basic usage analytics (PRD §19, §29, §30).
 *
 * Read-only and aggregate. It reads `learning_events` and `learning_mastery`,
 * which F10 owns and writes, and creates nothing — Handover 13 owns no tables.
 *
 * Authorized against QuestionPolicy::viewAny rather than a policy of its own:
 * there is no Analytics model to attach one to, and inventing an empty model
 * to hang a policy on would be the abstraction docs/engineering.md §12 warns
 * against. The ability chosen is the content-review one, because that is the
 * capability this page is the reporting half of.
 */
final class AnalyticsAdminController extends Controller
{
    /**
     * The default reporting window.
     *
     * Thirty days: long enough for a weekly rhythm to be visible, short enough
     * that `dailyActivity` stays a readable chart rather than a smear.
     */
    private const DEFAULT_DAYS = 30;

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Question::class);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'between:1,90'],
        ]);

        $days = (int) ($validated['days'] ?? self::DEFAULT_DAYS);

        // The clock is resolved here and passed in, so the service stays
        // testable without travelling time (invariant 1).
        $until = CarbonImmutable::now();
        $since = $until->subDays($days - 1)->startOfDay();

        $overview = $this->analytics->overview($since, $until);

        return Inertia::render('Admin/Analytics', [
            'days' => $days,
            'overview' => [
                'since' => $overview->since->toDateString(),
                'until' => $overview->until->toDateString(),
                'activeLearners' => $overview->activeLearners,
                'totalEvents' => $overview->totalEvents,
                'eventCounts' => $overview->eventCounts,
                'dailyActivity' => $overview->dailyActivity,
                'masteryByOrgan' => $overview->masteryByOrgan,
                'questionsAwaitingReview' => $overview->questionsAwaitingReview,
            ],
        ]);
    }
}
