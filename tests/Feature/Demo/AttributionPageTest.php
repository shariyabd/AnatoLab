<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
| The attribution page (handover 02's Definition of Done §6, built by 14).
|
| It renders docs/asset-register.md rather than restating it, and it states the
| licence position in the first thing a reader sees. Both are release-gate
| facts, not presentation preferences (PRD §42).
*/

it('is readable without an account', function (): void {
    $this->get('/attribution')->assertOk();
});

it('renders the asset register itself', function (): void {
    $this->get('/attribution')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Attribution')
            ->where('register.updatedAt', fn (?string $date): bool => $date !== null)
            // The rows, not a summary of them: these IDs exist only in
            // docs/asset-register.md, so their presence proves the file was read.
            ->where('register.html', fn (string $html): bool => str_contains($html, 'MDL-01')
                && str_contains($html, 'Deployment sign-off')
                && str_contains($html, '<table>'))
    );
});

it('reports the release gate from the manifest, not from prose', function (): void {
    // The same file scripts/verify-models.mjs --release checks, so the page
    // cannot claim a clearance the release script would refuse.
    $this->get('/attribution')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('register.assetsCleared', false)
            ->where('register.modelCount', 0)
    );
});

it('strips any raw HTML the register might one day quote', function (): void {
    $this->get('/attribution')->assertInertia(
        fn (AssertableInertia $page) => $page->where(
            'register.html',
            fn (string $html): bool => ! str_contains($html, '<script')
                && ! str_contains($html, 'javascript:')
        )
    );
});

it('is reachable from the footer of a signed-in page', function (): void {
    $this->actingAs(User::factory()->create());

    // The link lives in the layout, so asserting the route resolves and the
    // page renders is the server half; tests/Browser/smoke.spec.ts walks the
    // rendered footer.
    $this->get('/attribution')->assertOk();
});
