<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AnatomyController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Anatomy — Handover 03
|-------------------------------------------------------------------------------
|
| The read surface F04 (viewer), F05 (Explore), F07 (quiz), F08 (tutor), F09
| (missions) and F13 (admin) all resolve structures through.
|
| Authenticated, not public: docs/architecture.md §7 authenticates /api/v1 with
| the Inertia session cookie, and until the licence gate closes
| (docs/licence-log.md §3) the model URLs in this payload must not be reachable
| without a login.
|
| No `web` middleware is applied here — routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group and have the
| session and CSRF that session-cookie authentication needs.
|
*/

Route::prefix('api/v1/anatomy')
    ->name('api.v1.anatomy.')
    ->middleware(['auth', 'throttle:api'])
    ->group(function (): void {
        Route::get('/organs', [AnatomyController::class, 'index'])
            ->name('organs.index');

        Route::get('/organs/{organ}', [AnatomyController::class, 'show'])
            ->name('organs.show');

        // Constrained to digits so a non-numeric id is a 404 from the router
        // rather than a TypeError from the controller signature.
        Route::get('/structures/{structure}', [AnatomyController::class, 'structure'])
            ->whereNumber('structure')
            ->name('structures.show');
    });
