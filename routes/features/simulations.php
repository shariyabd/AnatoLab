<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SimulationController as SimulationApiController;
use App\Http\Controllers\Web\SimulationController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Simulations — Handover 12
|-------------------------------------------------------------------------------
|
| `{simulation}` is a simulation slug, constrained to the slug character set so
| `/simulations/anything` is a router 404 rather than a database read for a row
| that cannot exist.
|
| Authenticated throughout. A run belongs to somebody — `simulation_sessions`
| is per student — and the payload embeds the same OrganDto the anatomy API
| gates behind a login until the licence gate closes (docs/licence-log.md §3).
|
| No `web` middleware is applied: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session and
| CSRF that session-cookie authentication needs (docs/architecture.md §7).
|
| RATE LIMITS. `event` carries `throttle:ai`, the shared limiter
| AppServiceProvider registers and config/ai.php names for this handover. A step
| with curated content reaches no provider, but a step without one calls the
| tutor for its explanation (App\Services\Simulation\SimulationExplainer), and
| the limit has to bound the path that can, not the path that usually does —
| rate limits ship with the endpoint, not after it (docs/engineering.md §10).
| `reset` cannot reach a provider on any path, so the shared `api` limiter is
| enough.
|
*/

Route::prefix('api/v1/simulations')
    ->name('api.v1.simulations.')
    ->middleware('auth')
    ->group(function (): void {
        Route::post('/{simulation}/event', [SimulationApiController::class, 'event'])
            ->middleware('throttle:ai')
            ->where('simulation', '[a-z0-9-]+')
            ->name('event');

        Route::post('/{simulation}/reset', [SimulationApiController::class, 'reset'])
            ->middleware('throttle:api')
            ->where('simulation', '[a-z0-9-]+')
            ->name('reset');
    });

/*
| The pages. `index` is the nav entry (config/navigation.php); `show` is the run
| itself and the deep link the explore page and the demo story point at.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/simulations', [SimulationController::class, 'index'])
        ->name('simulations.index');

    Route::get('/simulations/{simulation}', [SimulationController::class, 'show'])
        ->where('simulation', '[a-z0-9-]+')
        ->name('simulations.show');
});
