<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ExploreController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Explore — Handover 05
|-------------------------------------------------------------------------------
|
| The student-facing page. Authenticated for the same reason the anatomy API
| is: until the licence gate closes (docs/licence-log.md §3) the model URLs in
| this payload must not be reachable without a login.
|
| `index` and `show` render the same Inertia component on purpose. That is what
| lets an organ switch be a partial reload — same component, new `organ` prop,
| viewer never remounted (app/Http/Controllers/Web/ExploreController.php).
|
| The wildcard is constrained to the slug character set so `/explore/anything`
| is a router 404 rather than a cache lookup for a key that cannot exist.
|
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/explore', [ExploreController::class, 'index'])
        ->name('explore.index');

    Route::get('/explore/{organ}', [ExploreController::class, 'show'])
        ->where('organ', '[a-z0-9-]+')
        ->name('explore.show');
});
