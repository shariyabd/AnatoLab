<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| The Explore page's Inertia props (handover 05).
|
| The `organ` prop is an OrganDto, unwrapped, because it is handed straight to
| useAnatomyViewer and the frozen contract in resources/js/anatomy/types.ts
| says what that object looks like (docs/feature-plan.md §7.8). A `data`
| wrapper here is a contract break that TypeScript cannot see.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('sends a guest to log in rather than to a model URL', function (): void {
    auth()->logout();

    $this->get('/explore')->assertRedirect('/login');
});

it('opens on the first published organ, structures and all', function (): void {
    $first = Organ::factory()->published()->create(['name' => 'Aorta']);
    Organ::factory()->published()->create(['name' => 'Zygoma']);
    AnatomicalStructure::factory()->published()->count(2)->for($first)->create();

    // The library row is loaded without its structures on purpose, and
    // OrganResource omits the key when the relation is missing. Rendering that
    // row would hand the viewer an organ with no hotspots and leave the
    // structure list empty — the failure this asserts against.
    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Explore')
            ->has('organs', 2)
            ->where('organ.name', 'Aorta')
            ->has('organ.structures', 2)
        );
});

it('lists only published organs in the library', function (): void {
    Organ::factory()->published()->create(['name' => 'Heart']);
    Organ::factory()->create(['name' => 'Draft Organ']);

    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('organs', 1)
            ->where('organs.0.name', 'Heart')
        );
});

it('gives each card the model URL its hover prefetch needs', function (): void {
    $organ = Organ::factory()->published()->create();

    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organs.0.modelUrl', fn (string $url): bool => str_contains($url, $organ->slug))
            ->has('organs.0.thumbnailUrl')
            ->has('organs.0.structureCount')
        );
});

it('renders the page when nothing has been published yet', function (): void {
    // The navigation links here unconditionally. A 404 on an empty database is
    // a broken nav entry, not an honest empty state.
    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('organs', 0)->where('organ', null));
});

it('deep links to one organ by slug', function (): void {
    Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    Organ::factory()->published()->create(['slug' => 'lungs', 'name' => 'Lungs']);

    $this->get('/explore/lungs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('organ.slug', 'lungs'));
});

it('renders the coming-soon state for an organ that is not published', function (): void {
    // Changed by handover 16. A draft organ used to 404, which told a student
    // the pancreas was not part of this product. The full taxonomy is now
    // seeded as draft rows on purpose, so a slug in the taxonomy renders an
    // inert "coming soon" panel and no organ (docs/organ-taxonomy.md §7).
    $draft = Organ::factory()->create(['slug' => 'pancreas', 'name' => 'Pancreas']);

    $this->get("/explore/{$draft->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Explore')
            ->where('organ', null)
            ->where('comingSoon.slug', 'pancreas')
            ->where('comingSoon.name', 'Pancreas')
        );
});

it('never sends a model URL for an organ it will not serve', function (): void {
    // The coming-soon payload is the one place a draft organ's `model_path`
    // could reach the client. It is a `models/pending/` placeholder today and
    // may be a licence-blocked asset tomorrow; neither belongs in a page prop
    // (App\Http\Resources\Explore\UpcomingOrganResource).
    Organ::factory()->create(['slug' => 'pancreas']);

    $this->get('/explore/pancreas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('comingSoon.modelUrl')
            ->missing('comingSoon.thumbnailUrl')
            ->missing('comingSoon.structureCount')
        );
});

it('lists the taxonomy alongside the library, without model URLs', function (): void {
    Organ::factory()->published()->create(['name' => 'Heart']);
    Organ::factory()->count(3)->create();

    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('organs', 1)
            ->has('upcoming', 3)
            ->missing('upcoming.0.modelUrl')
        );
});

it('404s on a slug that does not exist', function (): void {
    $this->get('/explore/no-such-organ')->assertNotFound();
});

it('hands the viewer an unwrapped OrganDto, not a resource envelope', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($organ)->create(['name' => 'Aorta']);

    $this->get('/explore/heart')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('organ.data')
            ->has('organ', fn ($dto) => $dto
                ->hasAll(['id', 'slug', 'name', 'modelUrl', 'modelFormat', 'accentColor', 'structures'])
                ->has('structures', 1, fn ($structure) => $structure
                    ->hasAll(['id', 'slug', 'name', 'taTerm', 'anchorPosition', 'markerColor'])
                    ->etc()
                )
                ->etc()
            )
        );
});

it('withholds unpublished structures from the payload', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(2)->for($organ)->create();
    AnatomicalStructure::factory()->count(3)->for($organ)->create();

    $this->get('/explore/heart')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('organ.structures', 2));
});

it('carries no answer key', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(3)->for($organ)->create();

    $response = $this->get('/explore/heart')->assertOk();

    expect($response->viewData('page')['props'])->toCarryNoAnswerKey();
});

it('does not scale queries with the size of the organ library', function (): void {
    // The N+1 that matters here is the library: one card renders a body system
    // and a structure count, and both are eager-loaded by AnatomyService.
    $count = function (): int {
        Cache::flush();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/explore')->assertOk();

        return $queries;
    };

    Organ::factory()->published()->count(2)->create()
        ->each(fn (Organ $organ) => AnatomicalStructure::factory()->published()->count(2)->for($organ)->create());

    $withTwo = $count();

    Organ::factory()->published()->count(6)->create()
        ->each(fn (Organ $organ) => AnatomicalStructure::factory()->published()->count(2)->for($organ)->create());

    $withEight = $count();

    expect($withEight)->toBe($withTwo);
});

it('re-serialises only the organ when the client switches organs', function (): void {
    Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    Organ::factory()->published()->create(['slug' => 'lungs', 'name' => 'Lungs']);

    // This is what keeps the WebGL context alive across a switch: same
    // component, one prop, no remount (app/Http/Controllers/Web/ExploreController.php).
    // Asserted against the raw Inertia envelope, because a partial reload
    // answers with JSON rather than the HTML page assertInertia() reads.
    $page = $this->withHeaders([
        'X-Inertia' => 'true',
        // Resolved the way the middleware resolves it: a stale version header
        // is a 409 and would make this test look like a routing failure.
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Explore',
        // Both props the client asks for on a switch. `comingSoon` travels with
        // `organ` so a switch away from a coming-soon deep link clears the
        // banner (Pages/Explore.vue, openOrgan).
        'X-Inertia-Partial-Data' => 'organ,comingSoon',
    ])
        ->get('/explore/lungs')
        ->assertOk()
        ->json();

    expect($page['component'])->toBe('Explore')
        ->and($page['props'])->toHaveKey('organ')
        ->and($page['props']['organ']['slug'])->toBe('lungs')
        ->and($page['props'])->toHaveKey('comingSoon')
        ->and($page['props']['comingSoon'])->toBeNull()
        ->and($page['props'])->not->toHaveKey('organs')
        ->and($page['props'])->not->toHaveKey('upcoming');
});
