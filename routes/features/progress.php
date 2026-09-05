<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\LearningEventController;
use App\Http\Controllers\Api\V1\ProgressController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Progress, mastery and gamification — Handover 10
|-------------------------------------------------------------------------------
|
| Authenticated throughout, and personal throughout: not one of these routes
| takes a user parameter, in the path or the query, so there is no request
| shape that asks for another student's progress. Scoping happens once, in the
| controller, from the authenticated User (docs/architecture.md §14).
|
| No `web` middleware is applied: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session
| and CSRF that session-cookie authentication needs (docs/architecture.md §7).
|
| The dashboard itself is not here. Handover 01 registered `/dashboard` in
| routes/web.php and left the controller empty for this lane to fill; adding a
| second progress page would give the same content two URLs and the navigation
| two entries for one destination. That is also why config/navigation.php gains
| no entry from this handover — "Dashboard" is already the first one in it.
|
*/

Route::prefix('api/v1/progress')
    ->name('api.v1.progress.')
    ->middleware(['auth', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [ProgressController::class, 'index'])->name('index');

        Route::get('/systems', [ProgressController::class, 'systems'])->name('systems');

        Route::get('/recommendations', [ProgressController::class, 'recommendations'])
            ->name('recommendations');
    });

/*
| The analytics sink. Its own rate limiter rather than `throttle:api`, because
| the traffic shape is different: the browser batches viewer interactions and
| flushes them on an interval and on page unload, so this endpoint sees few
| requests carrying many events, and each one is bounded by
| AnalyticsService::MAX_BATCH rather than by the request count
| (App\Providers\ProgressServiceProvider).
*/
Route::post('/api/v1/events', [LearningEventController::class, 'store'])
    ->middleware(['auth', 'throttle:learning-events'])
    ->name('api.v1.events.store');
