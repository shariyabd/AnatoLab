<?php

declare(strict_types=1);

use App\Http\Resources\Anatomy\StructureResource;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;

/*
| anchor_position is the working selection mechanism: the audited models are a
| single mesh with no per-structure geometry, so a marker's coordinate is the
| only thing that distinguishes one structure from another in 3D
| (docs/project-context.md §2.2).
|
| It has to survive the round trip as three floats, in order, in FIT_SIZE
| pivot space. A component silently becoming an int, a string, or an object
| with x/y/z keys is a viewer break that no type checker would catch, because
| the payload is untyped at the network boundary.
*/

it('round-trips as a three-float array through the database', function (): void {
    $structure = AnatomicalStructure::factory()->create([
        'anchor_position' => [0.70, -0.75, 0.65],
    ]);

    $fresh = AnatomicalStructure::query()->findOrFail($structure->getKey());

    expect($fresh->anchor_position)->toBe([0.70, -0.75, 0.65]);
});

it('widens every component to a float before encoding', function (): void {
    // 0 and -1 come back from the JSON column as PHP integers, and MySQL can
    // hand back strings. The Resource widens them so the payload is one type
    // all the way down rather than a mixed tuple.
    //
    // Asserted on the Resource, not on the decoded response: JSON itself has
    // no int/float distinction, json_encode writes 0.0 as `0` without
    // JSON_PRESERVE_ZERO_FRACTION, and TypeScript's `number` covers both. The
    // guarantee that means anything is the one on this side of the encoder.
    $structure = AnatomicalStructure::factory()->create(['anchor_position' => [0, -1, 0.5]]);

    $emitted = (new StructureResource($structure))->toArray(request())['anchorPosition'];

    expect($emitted)->toHaveCount(3)
        ->and($emitted)->each->toBeFloat()
        ->and($emitted)->toBe([0.0, -1.0, 0.5]);
});

it('preserves anchor order and value through the endpoint', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($organ)->create([
        'anchor_position' => [0.7, -0.75, 0.65],
    ]);

    $payload = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/anatomy/organs/heart')
        ->assertOk()
        ->json('data.structures.0.anchorPosition');

    expect($payload)->toBe([0.7, -0.75, 0.65]);
});

it('keeps every seeded anchor inside the FIT_SIZE cube', function (): void {
    // FIT_SIZE = 3.8 centred on the origin, so a coordinate outside ±1.9 is
    // outside the model and puts a marker in empty space
    // (docs/architecture.md §5.4 rule 1).
    $this->seed(Database\Seeders\AnatomySeeder::class);

    $halfExtent = ((float) config('anatomy.fit_size')) / 2.0;

    AnatomicalStructure::query()->get()->each(
        function (AnatomicalStructure $structure) use ($halfExtent): void {
            expect($structure->anchor_position)->toHaveCount(
                3,
                "{$structure->slug} has an anchor_position that is not a 3-tuple."
            );

            foreach ($structure->anchor_position as $component) {
                expect(abs((float) $component))->toBeLessThanOrEqual(
                    $halfExtent,
                    "{$structure->slug} is anchored outside the FIT_SIZE cube."
                );
            }
        }
    );
});
