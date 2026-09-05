<?php

declare(strict_types=1);

use App\Http\Resources\Anatomy\OrganResource;
use App\Http\Resources\Anatomy\StructureResource;
use App\Models\AnatomicalStructure;
use App\Models\Organ;

/*
| REQUIRED TEST (docs/feature-plan.md §7.8, parallel-execution-plan.md D7).
|
| resources/js/anatomy/types.ts is FROZEN and it is one half of a two-way
| contract: it mirrors the API Resources this lane owns. Changing one side
| alone is a silent runtime break — PHP happily emits a key Vue never reads,
| TypeScript happily types a key PHP never sends, and neither the type checker
| nor the PHP tests notice, because the payload is untyped at the network
| boundary.
|
| This test is the only thing that notices. It reads types.ts and compares the
| declared property names against what the Resources actually emit.
*/

/**
 * Property names declared on one interface in types.ts.
 *
 * @return list<string>
 */
function dtoProperties(string $interface): array
{
    $path = base_path('resources/js/anatomy/types.ts');

    expect(file_exists($path))->toBeTrue(
        'resources/js/anatomy/types.ts is missing; it is one half of the API contract.'
    );

    $source = (string) file_get_contents($path);

    $matched = preg_match(
        '/export\s+interface\s+'.preg_quote($interface, '/').'\s*\{(.*?)\n\}/s',
        $source,
        $matches,
    );

    expect($matched)->toBe(1, "Could not find `export interface {$interface}` in types.ts.");

    // Strip block comments first: they document fields by name, and a doc
    // comment mentioning `readonly` would otherwise be read as a declaration.
    $body = (string) preg_replace('#/\*.*?\*/#s', '', $matches[1]);

    preg_match_all('/^\s*readonly\s+(\w+)\??\s*:/m', $body, $properties);

    return $properties[1];
}

it('emits exactly the keys StructureDto declares', function (): void {
    $structure = AnatomicalStructure::factory()->make();

    $emitted = array_keys((new StructureResource($structure))->toArray(request()));

    expect($emitted)->toEqualCanonicalizing(dtoProperties('StructureDto'));
});

it('emits exactly the keys OrganDto declares', function (): void {
    $organ = Organ::factory()->make();
    $organ->setRelation('publishedStructures', AnatomicalStructure::factory()->count(2)->make());

    $emitted = array_keys((new OrganResource($organ))->toArray(request()));

    expect($emitted)->toEqualCanonicalizing(dtoProperties('OrganDto'));
});

it('types an id as a string, because StructureId is opaque on the other side', function (): void {
    // The viewer never parses, derives, or compares an id numerically
    // (docs/architecture.md §5.4 rule 4). Emitting an integer would work until
    // something on the Vue side did a === against a string.
    $organ = Organ::factory()->create();
    $structure = AnatomicalStructure::factory()->for($organ)->create();

    expect((new OrganResource($organ))->toArray(request())['id'])->toBeString()
        ->and((new StructureResource($structure))->toArray(request())['id'])->toBeString();
});

it('only emits a modelFormat the viewer union permits', function (): void {
    $path = base_path('resources/js/anatomy/types.ts');
    $source = (string) file_get_contents($path);

    preg_match("/readonly\s+modelFormat\s*:\s*([^\n]+)/", $source, $matches);

    preg_match_all("/'(\w+)'/", $matches[1] ?? '', $permitted);

    expect(App\Enums\ModelFormat::values())->toEqualCanonicalizing($permitted[1]);
});
