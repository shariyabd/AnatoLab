<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| The route loader is the mechanism that lets thirteen features add routes
| without any two of them editing routes/web.php (docs/feature-plan.md §7.1).
| If it silently stops globbing, every later feature's routes vanish and the
| symptom is a 404 that looks like a controller problem.
*/

$fixture = null;

beforeEach(function () use (&$fixture): void {
    $fixture = base_path('routes/features/zz_test_fixture.php');

    file_put_contents($fixture, <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Facades\Route;

        Route::get('/__test__/loaded-from-feature-file', fn (): string => 'feature route ok')
            ->name('test.feature-file');
        PHP);

    // Routes are registered during bootstrap, so the file must exist before the
    // application is rebuilt.
    $this->refreshApplication();
});

afterEach(function () use (&$fixture): void {
    if (is_string($fixture) && file_exists($fixture)) {
        unlink($fixture);
    }
});

it('picks up a route file dropped into routes/features/', function (): void {
    $this->get('/__test__/loaded-from-feature-file')
        ->assertOk()
        ->assertSee('feature route ok');
});

it('registers the route under its declared name', function (): void {
    expect(Route::has('test.feature-file'))->toBeTrue();
});

it('applies the web middleware group to feature routes', function (): void {
    // Feature routes are required from routes/web.php, so they inherit session
    // and CSRF. That is what lets a feature's /api/v1 endpoints authenticate
    // with the Inertia session cookie instead of needing Sanctum.
    $route = Route::getRoutes()->getByName('test.feature-file');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('web');
});
