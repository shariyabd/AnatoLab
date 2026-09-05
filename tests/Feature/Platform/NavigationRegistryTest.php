<?php

declare(strict_types=1);

use App\Models\User;

/*
| Features add navigation through config/navigation.php and never edit the
| layout component (docs/feature-plan.md §7.4).
*/

it('shares only the entries a student may see', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->has('navigation.main')
            ->where('navigation.main.0.key', 'dashboard')
            ->where('navigation.admin', [])
        );
});

it('shares no navigation with a guest', function (): void {
    $this->get('/')
        ->assertInertia(fn ($page) => $page->where('navigation.main', []));
});

it('resolves every configured route name', function (): void {
    // A nav entry naming a route that does not exist throws at render time on
    // whichever page happens to be loaded first — far from the config that
    // caused it. Fail here instead.
    /** @var array<string, list<array<string, mixed>>> $sections */
    $sections = config('navigation');

    foreach ($sections as $section => $items) {
        foreach ($items as $item) {
            expect(Route::has($item['route']))->toBeTrue(
                "config/navigation.php [{$section}] references unknown route [{$item['route']}]."
            );
        }
    }
})->skip(fn (): bool => config('navigation.main') === [], 'No navigation entries configured.');

it('sorts entries by their declared order, not by config position', function (): void {
    config()->set('navigation.main', [
        ['key' => 'second', 'label' => 'Second', 'route' => 'dashboard', 'icon' => null, 'roles' => ['student'], 'order' => 20],
        ['key' => 'first', 'label' => 'First', 'route' => 'dashboard', 'icon' => null, 'roles' => ['student'], 'order' => 10],
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('navigation.main.0.key', 'first')
            ->where('navigation.main.1.key', 'second')
        );
});

it('hides an admin-only entry from a student', function (): void {
    config()->set('navigation.admin', [
        ['key' => 'content', 'label' => 'Content', 'route' => 'dashboard', 'icon' => null, 'roles' => ['admin'], 'order' => 10],
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('navigation.admin', []));

    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('navigation.admin.0.key', 'content'));
});
