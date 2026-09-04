# Feature routes

One file per feature. `routes/web.php` and `routes/api.php` are owned by Handover 01
and are never edited again — this directory is how every other feature adds routes
without colliding (`docs/feature-plan.md` §7.1).

## Adding routes

Create `routes/features/<feature>.php`, named for your feature, not your lane:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ExploreController;
use Illuminate\Support\Facades\Route;

// Web pages
Route::middleware('auth')->group(function (): void {
    Route::get('/explore/{organ:slug}', ExploreController::class)->name('explore.show');
});

// JSON API — mirror the /api/v1 prefix and the api.v1. name prefix yourself.
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware(['web', 'auth'])
    ->group(function (): void {
        Route::get('/organs/{organ:slug}', [OrganApiController::class, 'show'])->name('organs.show');
    });
```

## Rules

- **Apply your own middleware.** The loader adds none. Be explicit about whether a
  route is public, `auth`, or `auth` + `admin`.
- **Name every route.** `config/navigation.php` resolves entries with `route()`, and
  an unnamed route cannot appear in the nav.
- **Prefix your route names** with your feature (`explore.`, `quiz.`, `mission.`) so
  two features cannot register the same name. A duplicate name silently wins over the
  earlier one — `route:list` is where you would find out, which is why `/verify` runs it.
- **Do not depend on load order.** Files load alphabetically, but treat that as an
  implementation detail: no file may assume another has already run.
