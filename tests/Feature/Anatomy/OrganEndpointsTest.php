<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| GET /api/v1/anatomy/organs and /organs/{organ}.
|
| The organ payload is the single most-consumed contract in the project
| (docs/handovers/03-anatomy-domain-api.md): F04, F05, F07, F08, F09, F12 and
| F13 all resolve structures through it.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists only published organs', function (): void {
    $published = Organ::factory()->published()->create(['name' => 'Heart']);
    Organ::factory()->create(['name' => 'Draft Organ']);

    $this->getJson('/api/v1/anatomy/organs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $published->slug)
        ->assertJsonPath('data.0.id', (string) $published->getKey());
});

it('counts only published structures on a list card', function (): void {
    $organ = Organ::factory()->published()->create();
    AnatomicalStructure::factory()->published()->count(3)->for($organ)->create();
    AnatomicalStructure::factory()->count(2)->for($organ)->create();

    $this->getJson('/api/v1/anatomy/organs')
        ->assertOk()
        ->assertJsonPath('data.0.structureCount', 3);
});

it('returns an organ with its published structures', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'slug' => 'left-ventricle',
        'ta_term' => 'Ventriculus sinister',
        'anchor_position' => [0.7, -0.75, 0.65],
    ]);

    $this->getJson('/api/v1/anatomy/organs/heart')
        ->assertOk()
        ->assertJsonPath('data.slug', 'heart')
        ->assertJsonPath('data.structures.0.id', (string) $structure->getKey())
        ->assertJsonPath('data.structures.0.taTerm', 'Ventriculus sinister')
        ->assertJsonPath('data.structures.0.anchorPosition', [0.7, -0.75, 0.65]);
});

it('hides unpublished structures from the organ payload', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($organ)->create(['slug' => 'verified']);
    AnatomicalStructure::factory()->for($organ)->create(['slug' => 'unverified']);

    $response = $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    expect($response->json('data.structures'))->toHaveCount(1)
        ->and($response->json('data.structures.0.slug'))->toBe('verified');
});

it('404s for an unknown organ slug', function (): void {
    $this->getJson('/api/v1/anatomy/organs/pancreas')->assertNotFound();
});

it('404s for a draft organ rather than revealing it exists', function (): void {
    $organ = Organ::factory()->create(['slug' => 'heart']);

    $this->getJson("/api/v1/anatomy/organs/{$organ->slug}")->assertNotFound();
});

it('emits modelObjectName as null on every structure', function (): void {
    // Invariant from docs/project-context.md §2.2: the assets are a single mesh
    // with no named sub-objects. A non-null value here would tell the viewer to
    // raycast against geometry that does not exist.
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(3)->for($organ)->create();

    $response = $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    foreach ($response->json('data.structures') as $structure) {
        expect($structure['modelObjectName'])->toBeNull();
    }
});

it('lists organs without N+1 queries', function (): void {
    Organ::factory()->published()->count(5)->create()
        ->each(fn (Organ $organ) => AnatomicalStructure::factory()->published()->count(4)->for($organ)->create());

    // Model::shouldBeStrict already throws on a lazy load, so this asserts the
    // weaker but more legible property: query count does not grow with rows.
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson('/api/v1/anatomy/organs')->assertOk();

    $anatomyQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains((string) $entry['query'], 'organs')
            || str_contains((string) $entry['query'], 'anatomical_structures')
            || str_contains((string) $entry['query'], 'body_systems'),
    );

    DB::disableQueryLog();

    // Two: the organ list (its structure count rides along as a subquery) and
    // one eager load of body_systems. Five organs with four structures each
    // would be eleven queries if either were lazy.
    expect($anatomyQueries)->toHaveCount(2);
});

it('requires authentication', function (): void {
    auth()->logout();

    $this->getJson('/api/v1/anatomy/organs')->assertUnauthorized();
});
