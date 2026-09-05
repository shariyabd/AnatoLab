<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MissionController as MissionApiController;
use App\Http\Controllers\Web\MissionController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Missions — Handover 09
|-------------------------------------------------------------------------------
|
| `{mission}` is the mission's own slug, not an organ's: several missions can
| sit on one organ, which is the difference between this and
| routes/features/quiz.php, where a quiz *is* an organ's published question set
| and has no row of its own.
|
| Authenticated throughout. A run has to belong to somebody — mastery is per
| student — and the mission payload embeds the same OrganDto the anatomy API
| gates behind a login until the licence gate closes (docs/licence-log.md §3).
|
| No `web` middleware is applied: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session and
| CSRF that session-cookie authentication needs (docs/architecture.md §7).
|
| The wildcard is constrained to the slug character set so `/missions/anything`
| is a router 404 rather than a lookup for a key that cannot exist.
|
*/

Route::prefix('api/v1/missions')
    ->name('api.v1.missions.')
    ->middleware(['auth', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [MissionApiController::class, 'index'])
            ->name('index');

        Route::get('/{mission}', [MissionApiController::class, 'show'])
            ->where('mission', '[a-z0-9-]+')
            ->name('show');

        Route::post('/{mission}/attempt', [MissionApiController::class, 'attempt'])
            ->where('mission', '[a-z0-9-]+')
            ->name('attempt');
    });

/*
| The pages. `index` is the nav entry (config/navigation.php), `show` is the run
| itself and the deep link Handover 14's demo journey walks
| (docs/handovers/14-demo-polish.md).
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/missions', [MissionController::class, 'index'])
        ->name('missions.index');

    Route::get('/missions/{mission}', [MissionController::class, 'show'])
        ->where('mission', '[a-z0-9-]+')
        ->name('missions.show');
});
