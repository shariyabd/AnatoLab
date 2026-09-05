<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\LessonController as LessonApiController;
use App\Http\Controllers\Web\LessonController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Lessons — Handover 06
|-------------------------------------------------------------------------------
|
| Authenticated throughout. A lesson embeds an OrganDto, model URL included,
| and those must not be reachable without a login until the licence gate closes
| (docs/licence-log.md §3) — the same rule routes/features/anatomy.php and
| routes/features/explore.php apply.
|
| No `web` middleware is applied here: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session and
| CSRF that session-cookie authentication needs (docs/architecture.md §2, §7).
|
| The slug wildcard is constrained to the slug character set so `/lessons/x_y`
| is a router 404 rather than a cache lookup for a key that cannot exist.
|
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/lessons', [LessonController::class, 'index'])
        ->name('lessons.index');

    Route::get('/lessons/{lesson}', [LessonController::class, 'show'])
        ->where('lesson', '[a-z0-9-]+')
        ->name('lessons.show');
});

Route::prefix('api/v1/lessons')
    ->name('api.v1.lessons.')
    ->middleware(['auth', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [LessonApiController::class, 'index'])
            ->name('index');

        Route::get('/{lesson}', [LessonApiController::class, 'show'])
            ->where('lesson', '[a-z0-9-]+')
            ->name('show');

        /*
        | Progress before complete: two writes rather than one, because
        | `progress_percent` is only meaningful if something records the steps
        | in between. Handover 06 lists three endpoints and this is a fourth —
        | see App\Http\Requests\Lessons\LessonProgressRequest for why
        | acceptance criterion 2 needs it.
        */
        Route::post('/{lesson}/progress', [LessonApiController::class, 'progress'])
            ->where('lesson', '[a-z0-9-]+')
            ->name('progress');

        Route::post('/{lesson}/complete', [LessonApiController::class, 'complete'])
            ->where('lesson', '[a-z0-9-]+')
            ->name('complete');
    });
