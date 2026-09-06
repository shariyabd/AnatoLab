<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Services\Anatomy\GlbNodeReader;
use App\Services\Anatomy\MeshIdentityVerifier;
use Database\Seeders\MeshIdentitySeeder;
use Illuminate\Support\Facades\Storage;

/*
| Handover 17 Branch B.
|
| `model_object_name` is a string in one system naming a node in another, and
| nothing in the database can enforce the join. These cover the three pieces
| that do: the reader, the verifier, and the command CI runs.
|
| Every model here is the generated fixture (tests/Fixtures/models), never a
| shipped GLB — those are gitignored and absent in CI, and the point of the
| fixture is that this suite does not wait on a licensed asset.
*/

const FIXTURE_GLB = 'tests/Fixtures/models/heart-per-structure.glb';

function fixtureBytes(): string
{
    return (string) file_get_contents(base_path(FIXTURE_GLB));
}

/** An organ whose model is the fixture, on a faked disk. */
function organWithFixtureModel(string $slug = 'heart'): Organ
{
    Storage::fake('models');
    Storage::disk('models')->put("models/{$slug}.glb", fixtureBytes());

    return Organ::factory()->published()->create([
        'slug' => $slug,
        'model_path' => "models/{$slug}.glb",
    ]);
}

function structureClaiming(Organ $organ, string $slug, ?string $node): AnatomicalStructure
{
    return AnatomicalStructure::factory()->published()->for($organ)->create([
        'slug' => $slug,
        'model_object_name' => $node,
    ]);
}

describe('GlbNodeReader', function (): void {
    it('reads every named node out of a real GLB', function (): void {
        $names = (new GlbNodeReader)->readNodeNames(fixtureBytes());

        expect($names)->toContain('heart__left-ventricle')
            ->and($names)->toContain('heart__apex')
            // The organ root and the pipeline's normalisation pivot are nodes
            // too, and both must survive the read — the verifier decides what
            // counts as a structure, not the reader.
            ->and($names)->toContain('heart')
            ->and($names)->toContain('anatolab_normalised_pivot');
    });

    it('counts meshes, which is how a single-mesh organ is recognised', function (): void {
        expect((new GlbNodeReader)->countMeshes(fixtureBytes()))->toBe(9);
    });

    it('refuses something that is not a GLB', function (): void {
        expect(fn () => (new GlbNodeReader)->readNodeNames(str_repeat('x', 64)))
            ->toThrow(RuntimeException::class, 'glTF magic');
    });

    it('refuses a file too short to hold a header', function (): void {
        expect(fn () => (new GlbNodeReader)->readNodeNames('glTF'))
            ->toThrow(RuntimeException::class, 'shorter than');
    });

    it('refuses a GLB whose JSON chunk is corrupt', function (): void {
        $binary = fixtureBytes();
        // Byte 20 is the first character of the JSON chunk — always `{`.
        $corrupted = substr_replace($binary, 'X', 20, 1);

        expect(fn () => (new GlbNodeReader)->readNodeNames($corrupted))
            ->toThrow(RuntimeException::class, 'not valid JSON');
    });
});

describe('MeshIdentityVerifier', function (): void {
    it('matches a structure whose node is in the model', function (): void {
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'left-ventricle', 'heart__left-ventricle');

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->passes())->toBeTrue()
            ->and($report->matched)->toBe(['left-ventricle'])
            ->and($report->orphans)->toBe([]);
    });

    it('orphans a structure naming a node the model does not contain', function (): void {
        // The failure handover 17 calls the most likely in the lane: a rename in
        // Blender that leaves the database pointing at a node that is gone.
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'apex', 'heart__apex-renamed');

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->passes())->toBeFalse()
            ->and($report->orphans)->toBe(['apex']);
    });

    it('leaves a structure with no claim on the dot fallback, and passes', function (): void {
        // anchor_position stays the permanent fallback. A mixed-mode organ is an
        // expected migration state, not a defect.
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'left-ventricle', 'heart__left-ventricle');
        structureClaiming($organ, 'aorta', null);

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->passes())->toBeTrue()
            ->and($report->dotOnly)->toBe(['aorta'])
            ->and($report->matched)->toBe(['left-ventricle']);
    });

    it('reports nodes in the model that no structure points at', function (): void {
        // A warning, not a failure — but it is the only signal that an export
        // and the database are drifting apart.
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'left-ventricle', 'heart__left-ventricle');

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->unclaimedNodes)->toHaveCount(8)
            ->and($report->unclaimedNodes)->toContain('heart__apex')
            // Never the organ root or the pivot: neither is structure-shaped.
            ->and($report->unclaimedNodes)->not->toContain('heart');
    });

    it('passes an organ where nothing claims anything, even with no model file', function (): void {
        // Every organ today. The check verifies claims and only claims, which is
        // what lets it run green in CI where .gitignore excludes every GLB.
        Storage::fake('models');
        $organ = Organ::factory()->published()->create(['model_path' => 'models/absent.glb']);
        structureClaiming($organ, 'aorta', null);

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->passes())->toBeTrue()
            ->and($report->isConverted())->toBeFalse();
    });

    it('fails a claim it cannot check, rather than passing it', function (): void {
        // "We could not read the model" and "the model is fine" are different
        // answers and must not share an exit code.
        Storage::fake('models');
        $organ = Organ::factory()->published()->create(['model_path' => 'models/absent.glb']);
        structureClaiming($organ, 'aorta', 'heart__aorta');

        $report = app(MeshIdentityVerifier::class)->verifyOrgan($organ);

        expect($report->passes())->toBeFalse()
            ->and($report->modelIsReadable)->toBeFalse()
            ->and($report->orphans)->toBe(['aorta']);
    });

    it('does not try to fetch a model hosted behind a URL', function (): void {
        Storage::fake('models');
        $organ = Organ::factory()->published()->create([
            'model_path' => 'https://cdn.example.test/heart.glb',
        ]);
        structureClaiming($organ, 'aorta', null);

        expect(app(MeshIdentityVerifier::class)->verifyOrgan($organ)->modelIsReadable)->toBeFalse();
    });

    it('ignores unpublished structures', function (): void {
        // The command asserts what students can reach. A draft structure with a
        // stale node name is an editing state, not a shipped defect.
        $organ = organWithFixtureModel();
        AnatomicalStructure::factory()->for($organ)->create([
            'slug' => 'ghost',
            'model_object_name' => 'heart__does-not-exist',
            'is_published' => false,
        ]);

        expect(app(MeshIdentityVerifier::class)->verifyOrgan($organ)->passes())->toBeTrue();
    });

    it('builds a node name the same way the export script does', function (): void {
        // Mirrors scripts/lib/structureNodes.mjs. The two are checked against
        // each other by the fixture: PerStructureFixtureTest asserts the GLB's
        // node names join to seeded slugs, and this builds the same string.
        expect(MeshIdentityVerifier::nodeNameFor('heart', 'left-ventricle'))
            ->toBe('heart__left-ventricle');
    });
});

describe('the anatomy:verify-mesh-identity command', function (): void {
    it('succeeds and says so plainly when nothing claims a mesh node', function (): void {
        Storage::fake('models');
        $organ = Organ::factory()->published()->create();
        structureClaiming($organ, 'aorta', null);

        $this->artisan('anatomy:verify-mesh-identity')
            ->expectsOutputToContain('No structure claims a mesh node yet')
            ->assertSuccessful();
    });

    it('fails when a node name is orphaned', function (): void {
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'apex', 'heart__apex-renamed');

        $this->artisan('anatomy:verify-mesh-identity')->assertFailed();
    });

    it('succeeds when every claim resolves', function (): void {
        $organ = organWithFixtureModel();
        structureClaiming($organ, 'left-ventricle', 'heart__left-ventricle');
        structureClaiming($organ, 'apex', 'heart__apex');

        $this->artisan('anatomy:verify-mesh-identity')
            ->expectsOutputToContain('every claimed mesh node present')
            ->assertSuccessful();
    });

    it('can be pointed at one organ', function (): void {
        $heart = organWithFixtureModel();
        structureClaiming($heart, 'apex', 'heart__apex-renamed');

        $lungs = Organ::factory()->published()->create(['slug' => 'lungs']);
        structureClaiming($lungs, 'trachea', null);

        $this->artisan('anatomy:verify-mesh-identity', ['--organ' => 'lungs'])->assertSuccessful();
        $this->artisan('anatomy:verify-mesh-identity', ['--organ' => 'heart'])->assertFailed();
    });

    it('fails on an organ slug that does not exist', function (): void {
        $this->artisan('anatomy:verify-mesh-identity', ['--organ' => 'gizzard'])->assertFailed();
    });
});

describe('MeshIdentitySeeder', function (): void {
    /** Writes a manifest the seeder can read, and returns its path. */
    function manifestWith(?array $structureNodes, string $organSlug = 'heart'): string
    {
        $row = ['organSlug' => $organSlug, 'modelPath' => "models/{$organSlug}.glb"];

        if ($structureNodes !== null) {
            $row['structureNodes'] = $structureNodes;
        }

        $path = tempnam(sys_get_temp_dir(), 'manifest').'.json';
        file_put_contents($path, json_encode(['version' => 1, 'models' => [$row]]));

        return $path;
    }

    it('populates model_object_name from the manifest', function (): void {
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'left-ventricle', null);
        structureClaiming($organ, 'apex', null);

        (new MeshIdentitySeeder(manifestWith(['heart__left-ventricle', 'heart__apex'])))->run();

        expect(AnatomicalStructure::query()->where('slug', 'left-ventricle')->value('model_object_name'))
            ->toBe('heart__left-ventricle');
    });

    it('leaves a structure the manifest does not name on the dot fallback', function (): void {
        // Never invented. A structure gets a node name only if the model has one.
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'left-ventricle', null);
        structureClaiming($organ, 'apex', null);

        (new MeshIdentitySeeder(manifestWith(['heart__left-ventricle'])))->run();

        expect(AnatomicalStructure::query()->where('slug', 'apex')->value('model_object_name'))
            ->toBeNull();
    });

    it('clears a claim whose node has left the manifest', function (): void {
        // Otherwise this seeder would manufacture the orphan the command exists
        // to catch, every time an export dropped a structure.
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'apex', 'heart__apex');

        (new MeshIdentitySeeder(manifestWith(['heart__left-ventricle'])))->run();

        expect(AnatomicalStructure::query()->where('slug', 'apex')->value('model_object_name'))
            ->toBeNull();
    });

    it('does nothing for a manifest row with no structureNodes', function (): void {
        // Every row in public/models/manifest.json today: single-mesh organs.
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'left-ventricle', null);

        (new MeshIdentitySeeder(manifestWith(null)))->run();

        expect(AnatomicalStructure::query()->whereNotNull('model_object_name')->count())->toBe(0);
    });

    it('survives a manifest that is missing or unreadable', function (): void {
        // The state of a fresh clone: .gitignore excludes the GLBs the manifest
        // describes. A seeder that threw here would make migrate:fresh --seed
        // depend on an asset download.
        Organ::factory()->published()->create(['slug' => 'heart']);

        expect(fn () => (new MeshIdentitySeeder('/nonexistent/manifest.json'))->run())
            ->not->toThrow(Exception::class);
    });

    it('is idempotent', function (): void {
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'apex', null);

        $manifest = manifestWith(['heart__apex']);

        (new MeshIdentitySeeder($manifest))->run();
        $first = AnatomicalStructure::query()->where('slug', 'apex')->sole()->updated_at;

        (new MeshIdentitySeeder($manifest))->run();

        expect(AnatomicalStructure::query()->where('slug', 'apex')->value('model_object_name'))
            ->toBe('heart__apex')
            // Unchanged rows are not re-saved, so nothing downstream of the
            // observer is invalidated on a no-op re-seed.
            ->and(AnatomicalStructure::query()->where('slug', 'apex')->sole()->updated_at)
            ->toEqual($first);
    });

    it('leaves the shipped manifest producing no claims today', function (): void {
        // The honest statement of where F17 actually is: the seeder is wired,
        // and public/models/manifest.json declares no structure nodes, so
        // nothing claims a mesh. When Branch A lands an export, this flips.
        $organ = Organ::factory()->published()->create(['slug' => 'heart']);
        structureClaiming($organ, 'apex', null);

        (new MeshIdentitySeeder)->run();

        expect(AnatomicalStructure::query()->whereNotNull('model_object_name')->count())->toBe(0);
    });
});
