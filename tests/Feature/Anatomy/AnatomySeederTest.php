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

it('seeds the nine organs, published', function (): void {
    expect(Organ::query()->pluck('slug')->sort()->values()->all())
        ->toBe([
            'brain', 'eyeball', 'heart', 'intestine', 'kidneys',
            'liver', 'lungs', 'pancreas', 'skin',
        ]);

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
    expect(BodySystem::query()->count())->toBe(7)
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

it('seeds a model path per organ, matching the manifest convention', function (): void {
    // `modelPath` in public/models/manifest.json is `models/<slug>.glb`,
    // relative to public/ (scripts/README.md). The seeder and the manifest have
    // to agree on that shape or the viewer resolves a URL for a file that is
    // not there. Files now exist locally, but the manifest still reads
    // "pending-licence": nothing here may be deployed until docs/licence-log.md
    // §4 records a decision.
    Organ::query()->get()->each(
        fn (Organ $organ) => expect($organ->model_path)->toBe("models/{$organ->slug}.glb")
    );
});
