<?php

declare(strict_types=1);

use App\Enums\StructureRelationType;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\StructureRelation;
use App\Models\User;

/*
| GET /api/v1/anatomy/structures/{structure} — the metadata panel's payload.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('returns full metadata for a published structure', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left Ventricle',
        'ta_term' => 'Ventriculus sinister',
        'location' => 'Lower left of the heart.',
    ]);

    $this->getJson("/api/v1/anatomy/structures/{$structure->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $structure->getKey())
        ->assertJsonPath('data.slug', 'left-ventricle')
        ->assertJsonPath('data.taTerm', 'Ventriculus sinister')
        ->assertJsonPath('data.location', 'Lower left of the heart.')
        ->assertJsonPath('data.organ.slug', 'heart');
});

it('includes related structures with their relation verb', function (): void {
    $organ = Organ::factory()->published()->create();
    $ventricle = AnatomicalStructure::factory()->published()->for($organ)->create(['slug' => 'left-ventricle']);
    $aorta = AnatomicalStructure::factory()->published()->for($organ)->create(['slug' => 'aorta']);

    StructureRelation::query()->create([
        'structure_id' => $ventricle->getKey(),
        'related_structure_id' => $aorta->getKey(),
        'relation_type' => StructureRelationType::FlowsInto,
    ]);

    $this->getJson("/api/v1/anatomy/structures/{$ventricle->getKey()}")
        ->assertOk()
        ->assertJsonCount(1, 'data.relatedStructures')
        ->assertJsonPath('data.relatedStructures.0.slug', 'aorta')
        ->assertJsonPath('data.relatedStructures.0.relationType', 'flows_into')
        ->assertJsonPath('data.relatedStructures.0.relationLabel', 'Flows into');
});

it('omits an unpublished related structure', function (): void {
    $organ = Organ::factory()->published()->create();
    $ventricle = AnatomicalStructure::factory()->published()->for($organ)->create();
    $draft = AnatomicalStructure::factory()->for($organ)->create();

    StructureRelation::query()->create([
        'structure_id' => $ventricle->getKey(),
        'related_structure_id' => $draft->getKey(),
        'relation_type' => StructureRelationType::Adjacent,
    ]);

    $this->getJson("/api/v1/anatomy/structures/{$ventricle->getKey()}")
        ->assertOk()
        ->assertJsonCount(0, 'data.relatedStructures');
});

it('404s for an unpublished structure', function (): void {
    $structure = AnatomicalStructure::factory()->create();

    $this->getJson("/api/v1/anatomy/structures/{$structure->getKey()}")->assertNotFound();
});

it('404s for an unknown structure id', function (): void {
    $this->getJson('/api/v1/anatomy/structures/999999')->assertNotFound();
});

it('404s on a non-numeric structure id instead of erroring', function (): void {
    $this->getJson('/api/v1/anatomy/structures/left-ventricle')->assertNotFound();
});

it('carries no answer key', function (): void {
    // Invariant 4. Nothing in the anatomy payload is correctness data today,
    // and this is the assertion that notices if that changes — F07 adds
    // correct_structure_id to `questions`, and a careless `with()` here is
    // exactly how it would leak (docs/engineering.md §9).
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();

    expect($this->getJson("/api/v1/anatomy/structures/{$structure->getKey()}")->json())
        ->toCarryNoAnswerKey();

    expect($this->getJson('/api/v1/anatomy/organs/heart')->json())->toCarryNoAnswerKey();
});
