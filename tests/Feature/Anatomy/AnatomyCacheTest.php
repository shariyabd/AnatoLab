<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| docs/architecture.md §11: anatomy:organs, anatomy:organ:{slug} and
| anatomy:structure:{id} are cached for 24 h and cleared by model observers.
| A cache nobody invalidates is worse than no cache — a published structure
| that never appears looks like a seeder bug for a day.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function countAnatomyQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $queries = array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains((string) $entry['query'], 'organs')
            || str_contains((string) $entry['query'], 'anatomical_structures'),
    );

    DB::disableQueryLog();

    return count($queries);
}

it('serves a repeat organ request from the cache', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(3)->for($organ)->create();

    $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    $queries = countAnatomyQueries(function (): void {
        $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();
    });

    expect($queries)->toBe(0);
});

it('caches the organ under the documented key', function (): void {
    Organ::factory()->published()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    expect(Cache::get('anatomy:organ:heart'))->toBeInstanceOf(Organ::class);
});

it('does not cache a miss', function (): void {
    // Caching null would make an organ published one second after a 404 stay
    // invisible for the full 24 hours.
    $this->getJson('/api/v1/anatomy/organs/heart')->assertNotFound();

    Organ::factory()->published()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();
});

it('clears the organ cache when the organ is saved', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    $organ->update(['name' => 'The Heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')
        ->assertOk()
        ->assertJsonPath('data.name', 'The Heart');
});

it('clears the organ cache when one of its structures is saved', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')
        ->assertOk()
        ->assertJsonCount(0, 'data.structures');

    AnatomicalStructure::factory()->published()->for($organ)->create();

    $this->getJson('/api/v1/anatomy/organs/heart')
        ->assertOk()
        ->assertJsonCount(1, 'data.structures');
});

it('clears the entry cached under an organ slug that has just changed', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/anatomy/organs/heart')->assertOk();

    $organ->update(['slug' => 'cor']);

    expect(Cache::get('anatomy:organ:heart'))->toBeNull();

    $this->getJson('/api/v1/anatomy/organs/heart')->assertNotFound();
    $this->getJson('/api/v1/anatomy/organs/cor')->assertOk();
});

it('clears the organ list when an organ is published', function (): void {
    $organ = Organ::factory()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/anatomy/organs')->assertOk()->assertJsonCount(0, 'data');

    $organ->update(['status' => 'published']);

    $this->getJson('/api/v1/anatomy/organs')->assertOk()->assertJsonCount(1, 'data');
});

it('clears the structure cache when the structure is saved', function (): void {
    $structure = AnatomicalStructure::factory()->published()->create(['name' => 'Left Ventricle']);
    $structure->organ->update(['status' => 'published']);

    $this->getJson("/api/v1/anatomy/structures/{$structure->getKey()}")->assertOk();

    $structure->update(['name' => 'Left ventricle (LV)']);

    $this->getJson("/api/v1/anatomy/structures/{$structure->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Left ventricle (LV)');
});

it('resolves a cached organ well inside the 100 ms budget', function (): void {
    // docs/handovers/03-anatomy-domain-api.md acceptance criterion 3. Measured
    // on the service, not the HTTP round trip: the budget is about the cache
    // doing its job, and a test that also timed routing and JSON encoding
    // would be measuring the framework.
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(9)->for($organ)->create();

    $anatomy = app(AnatomyService::class);
    $anatomy->findPublishedOrganBySlug('heart');

    $startedAt = hrtime(true);
    $anatomy->findPublishedOrganBySlug('heart');
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(100.0);
});
