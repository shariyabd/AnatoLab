<?php

declare(strict_types=1);

use App\Models\User;

/*
|-------------------------------------------------------------------------------
| Design tokens — Handover 15 Phase 0
|-------------------------------------------------------------------------------
|
| The route contract only. What the page looks like is reviewed by looking at
| it, and docs/engineering.md §9 is explicit that Blade and Vue markup detail is
| not something to assert.
|
*/

it('renders the design tokens page for a reviewer with no account', function (): void {
    $this->get('/design-tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('DesignTokens'));
});

it('renders the same page for a signed-in user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/design-tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('DesignTokens'));
});

it('passes the page no props of its own', function (): void {
    // The palette has one source of truth — theme.css, read back by the
    // browser. A prop here would be a second copy of it and the first to rot.
    $shared = ['auth', 'navigation', 'flash', 'csrfToken', 'errors'];

    $this->get('/design-tokens')
        ->assertOk()
        ->assertInertia(function ($page) use ($shared): void {
            $page->component('DesignTokens');

            expect(array_values(array_diff(array_keys($page->toArray()['props']), $shared)))->toBe([]);
        });
});
