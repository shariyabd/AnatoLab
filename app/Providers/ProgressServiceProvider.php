<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Attempt;
use App\Models\LessonProgress;
use App\Observers\AttemptObserver;
use App\Observers\LessonProgressObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Registration for the progress lane (docs/feature-plan.md §7.3).
 *
 * A provider rather than edits to AppServiceProvider, which Handover 01 owns —
 * the same arrangement AiServiceProvider and RagServiceProvider use.
 *
 * The two observers are attached here rather than by an `#[ObservedBy]`
 * attribute on the models, because both models belong to other lanes:
 * `Attempt` to Handover 07 (which Handover 09 is editing in parallel) and
 * `LessonProgress` to Handover 06. Registering from this side means the
 * analytics events are recorded where they happen — docs/architecture.md §13 —
 * with no file of theirs touched
 * (docs/handovers/parallel-execution-plan.md, batch D).
 *
 * Nothing is bound into the container: the progress services are concrete
 * classes with constructor-injected concrete dependencies, so autowiring
 * resolves them. There is no `App\Contracts` interface to bind because there
 * is no second implementation to swap in — the mastery formula is fixed by
 * docs/architecture.md §10, not selected by config.
 */
final class ProgressServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Attempt::observe(AttemptObserver::class);
        LessonProgress::observe(LessonProgressObserver::class);

        $this->configureRateLimiting();
    }

    /**
     * The analytics endpoint's own limit.
     *
     * Separate from `throttle:api` and deliberately lower in requests but
     * higher in effective events: the client batches, so one flush every two
     * seconds is already generous for a page that emits an event per structure
     * click. Rate limits ship with the endpoint, not after it
     * (docs/engineering.md §10).
     *
     * Keyed by user, falling back to IP for the unauthenticated case the route
     * does not actually allow — a limiter that returns an unkeyed Limit
     * throttles every student as one bucket.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('learning-events', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? Str::random(8))));
    }
}
