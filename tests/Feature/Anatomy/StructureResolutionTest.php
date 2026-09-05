<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Services\Anatomy\AnatomyService;

/*
| Structure resolution by slug and by Terminologia Anatomica term
| (docs/handovers/03-anatomy-domain-api.md §Tests).
|
| The TA term is the locale-independent identity the question bank, the RAG
| filter, and the AI context all key off (docs/project-context.md §2.3), so
| resolving one has to work from a string that arrived from a model response
| or a curriculum document, not just from the database.
*/

beforeEach(function (): void {
    $this->anatomy = app(AnatomyService::class);
});

it('resolves a structure by its slug within an organ', function (): void {
    $heart = Organ::factory()->create(['slug' => 'heart']);
    $lungs = Organ::factory()->create(['slug' => 'lungs']);

    $heartApex = AnatomicalStructure::factory()->for($heart)->create(['slug' => 'apex']);
    AnatomicalStructure::factory()->for($lungs)->create(['slug' => 'apex']);

    expect($this->anatomy->findStructureBySlug($heart, 'apex')?->getKey())
        ->toBe($heartApex->getKey());
});

it('returns null for a slug that belongs to a different organ', function (): void {
    $heart = Organ::factory()->create();
    $lungs = Organ::factory()->create();

    AnatomicalStructure::factory()->for($lungs)->create(['slug' => 'carina']);

    expect($this->anatomy->findStructureBySlug($heart, 'carina'))->toBeNull();
});

it('resolves a structure by its TA term', function (): void {
    $structure = AnatomicalStructure::factory()->create(['ta_term' => 'Ventriculus sinister']);

    expect($this->anatomy->findStructureByTaTerm('Ventriculus sinister')?->getKey())
        ->toBe($structure->getKey());
});

it('resolves a TA term regardless of case or surrounding whitespace', function (): void {
    $structure = AnatomicalStructure::factory()->create(['ta_term' => 'Ventriculus sinister']);

    expect($this->anatomy->findStructureByTaTerm('  ventriculus SINISTER ')?->getKey())
        ->toBe($structure->getKey());
});

it('does not treat a caller-supplied wildcard as a pattern', function (): void {
    AnatomicalStructure::factory()->create(['ta_term' => 'Ventriculus sinister']);

    expect($this->anatomy->findStructureByTaTerm('Ventriculus%'))->toBeNull();
});

it('returns null for an empty TA term', function (): void {
    AnatomicalStructure::factory()->create(['ta_term' => 'Ventriculus sinister']);

    expect($this->anatomy->findStructureByTaTerm('   '))->toBeNull();
});
