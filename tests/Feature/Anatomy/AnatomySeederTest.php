<?php

declare(strict_types=1);

use App\Enums\OrganStatus;
use App\Models\AnatomicalStructure;
use App\Models\BodySystem;
use App\Models\Organ;
use App\Models\StructureRelation;
use Database\Seeders\AnatomySeeder;

/*
| The demo dataset is a first-class artefact (docs/engineering.md §6). These
| assert the acceptance criteria in docs/handovers/03-anatomy-domain-api.md:
| three organs, ~8 structures each, all with TA terms and anchor positions.
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
});

it('seeds the three MVP organs, published', function (): void {
    expect(Organ::query()->pluck('slug')->sort()->values()->all())
        ->toBe(['brain', 'heart', 'lungs']);

    Organ::query()->get()->each(
        fn (Organ $organ) => expect($organ->status)->toBe(OrganStatus::Published)
    );
});

it('gives every organ at least eight published structures', function (): void {
    Organ::query()->withCount('publishedStructures')->get()->each(
        fn (Organ $organ) => expect($organ->published_structures_count)
            ->toBeGreaterThanOrEqual(8, "{$organ->slug} has too few structures for a credible quiz.")
    );
});

it('gives every structure a TA term and an anchor position', function (): void {
    AnatomicalStructure::query()->get()->each(function (AnatomicalStructure $structure): void {
        expect($structure->ta_term)->not->toBeNull("{$structure->slug} has no TA term.")
            ->and($structure->ta_term)->not->toBe('')
            ->and($structure->anchor_position)->toHaveCount(3);
    });
});

it('leaves model_object_name null everywhere', function (): void {
    expect(AnatomicalStructure::query()->whereNotNull('model_object_name')->count())->toBe(0);
});

it('attaches every organ to a body system', function (): void {
    expect(BodySystem::query()->count())->toBe(3)
        ->and(Organ::query()->whereNull('body_system_id')->count())->toBe(0);
});

it('seeds related structures for the compare panel and the AI context', function (): void {
    expect(StructureRelation::query()->count())->toBeGreaterThan(20);
});

it('is idempotent', function (): void {
    $before = [
        BodySystem::query()->count(),
        Organ::query()->count(),
        AnatomicalStructure::query()->count(),
        StructureRelation::query()->count(),
    ];

    $this->seed(AnatomySeeder::class);

    expect([
        BodySystem::query()->count(),
        Organ::query()->count(),
        AnatomicalStructure::query()->count(),
        StructureRelation::query()->count(),
    ])->toBe($before);
});

it('seeds placeholder model paths that the licence swap will replace', function (): void {
    // public/models/manifest.json is "pending-licence" with an empty models
    // array (docs/licence-log.md §3). If this ever fails because a real path
    // landed, that is the swap commit and this expectation is what should be
    // updated — not the seeder quietly diverging from the asset register.
    Organ::query()->get()->each(
        fn (Organ $organ) => expect($organ->model_path)->toBe("models/{$organ->slug}.glb")
    );
});
