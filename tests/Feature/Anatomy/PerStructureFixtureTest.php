<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use Database\Seeders\AnatomySeeder;

/*
| Groundwork for handover 17 Branch B.
|
| `scripts/make-structure-fixture.mjs` writes a per-structure GLB so that B and C
| do not have to wait for a licensed asset to exist. That is only true if the
| fixture's node names actually join to the rows B will seed — a fixture naming
| structures the seeder has never heard of would let every downstream test pass
| while testing nothing.
|
| The GLB reader below is deliberately minimal and lives here rather than in
| app/. `php artisan anatomy:verify-mesh-identity` is Branch B's deliverable and
| will need the same few lines against the shipped models; this proves they work
| and hands B something to promote rather than invent.
*/

/**
 * Node names out of a GLB's JSON chunk.
 *
 * A GLB is a 12-byte header followed by length-prefixed chunks, the first of
 * which is the JSON. No dependency needed, and none wanted: `ext-gltf` does not
 * exist and adding a Composer package to read 12 bytes would be the wrong trade.
 *
 * @return list<string>
 */
function glbNodeNames(string $path): array
{
    $binary = (string) file_get_contents($path);

    expect(substr($binary, 0, 4))->toBe('glTF', "{$path} is not a GLB.");

    /** @var array{0: int} $lengths */
    $lengths = array_values((array) unpack('V', substr($binary, 12, 4)));
    $jsonLength = $lengths[0];

    /** @var array<string, mixed> $gltf */
    $gltf = json_decode(substr($binary, 20, $jsonLength), true, 512, JSON_THROW_ON_ERROR);

    /** @var list<array<string, mixed>> $nodes */
    $nodes = $gltf['nodes'] ?? [];

    return array_values(array_filter(array_map(
        static fn (array $node): string => (string) ($node['name'] ?? ''),
        $nodes,
    ), static fn (string $name): bool => str_contains($name, '__')));
}

function fixturePath(string $file): string
{
    return base_path("tests/Fixtures/models/{$file}");
}

it('ships a per-structure fixture and a manifest describing it', function (): void {
    expect(fixturePath('heart-per-structure.glb'))->toBeReadableFile()
        ->and(fixturePath('manifest.json'))->toBeReadableFile();
});

it('names every node in the fixture to the agreed convention', function (): void {
    // <organ-slug>__<structure-slug>, lowercase kebab, double underscore
    // (scripts/lib/structureNodes.mjs). A node that fails this is a structure
    // that can never be selected.
    foreach (glbNodeNames(fixturePath('heart-per-structure.glb')) as $name) {
        expect($name)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*__[a-z0-9]+(?:-[a-z0-9]+)*$/');
    }
});

it('agrees with its own manifest about what it contains', function (): void {
    /** @var array{models: list<array{structureNodes: list<string>}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(fixturePath('manifest.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $declared = $manifest['models'][0]['structureNodes'];
    $actual = glbNodeNames(fixturePath('heart-per-structure.glb'));

    sort($declared);
    sort($actual);

    expect($actual)->toBe($declared, 'Run `npm run models:fixture` — the manifest is stale.');
});

it('joins every fixture node to a real seeded heart structure', function (): void {
    // The join Branch B makes when it populates `model_object_name`. If the
    // seeder renames a heart structure, this fails and says which — which is the
    // orphaning failure handover 17 calls the most likely in the lane, caught
    // before it reaches a model anyone paid for.
    $this->seed(AnatomySeeder::class);

    /** @var Organ $heart */
    $heart = Organ::query()->where('slug', 'heart')->sole();

    $seeded = AnatomicalStructure::query()
        ->where('organ_id', $heart->getKey())
        ->pluck('slug')
        ->all();

    foreach (glbNodeNames(fixturePath('heart-per-structure.glb')) as $name) {
        [$organSlug, $structureSlug] = explode('__', $name, 2);

        // `in_array` rather than `toContain`: Pest reads every extra argument to
        // toContain as another needle, so the failure message would become one.
        expect($organSlug)->toBe('heart')
            ->and(in_array($structureSlug, $seeded, strict: true))->toBeTrue(
                "The fixture names `{$structureSlug}`, which the heart no longer has."
            );
    }
});

it('covers enough of the heart to exercise a quiz', function (): void {
    // Six is the per-organ floor handover 16 publishes against. A fixture below
    // it could not stand in for a real organ in an assessment test.
    expect(glbNodeNames(fixturePath('heart-per-structure.glb')))
        ->toHaveCount(9);
});

it('leaves model_object_name null until Branch B populates it', function (): void {
    // The fixture exists; the seeding does not. Stated as a test so that when B
    // lands, this failing is the reminder to update it rather than a surprise.
    $this->seed(AnatomySeeder::class);

    expect(AnatomicalStructure::query()->whereNotNull('model_object_name')->count())->toBe(0);
});
