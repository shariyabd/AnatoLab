<?php

declare(strict_types=1);

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;
use App\Services\AI\AIContextBuilder;

/*
| AIContextBuilder — everything the tutor is allowed to know (PRD §9.2, §39).
*/

beforeEach(function (): void {
    $this->builder = new AIContextBuilder;

    $this->student = User::factory()->create([
        'education_level' => EducationLevel::MiddleSchool,
        'difficulty_preference' => DifficultyPreference::Beginner,
    ]);
});

it('assembles the student, the organ and the structure', function (): void {
    $organ = Organ::factory()->published()->create(['name' => 'Heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'name' => 'Left ventricle',
    ]);

    $context = $this->builder->build($this->student, $organ, $structure, 'Blood circulation');

    expect($context->userId)->toBe($this->student->getKey())
        ->and($context->educationLevel)->toBe('middle_school')
        ->and($context->difficultyPreference)->toBe('beginner')
        ->and($context->organName)->toBe('Heart')
        ->and($context->structureName)->toBe('Left ventricle')
        ->and($context->lessonTitle)->toBe('Blood circulation');
});

it('builds a context with nothing selected', function (): void {
    $context = $this->builder->build($this->student);

    expect($context->organName)->toBeNull()
        ->and($context->structureName)->toBeNull()
        ->and($context->recentMistakes)->toBe([])
        ->and($context->masteryGaps)->toBe([]);
});

it('carries no model, id, or credential into the context', function (): void {
    // LearningContext is serialised into a prompt and into a queued job.
    $organ = Organ::factory()->published()->create();
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();

    $encoded = json_encode($this->builder->build($this->student, $organ, $structure), JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('anchor_position');
});

it('gathers the curated notes an answer is grounded in', function (): void {
    // Until Handover 11 lands retrieval, this published metadata is the
    // grounding (docs/architecture.md §8.2).
    $organ = Organ::factory()->published()->create([
        'name' => 'Heart',
        'description' => 'A four-chambered muscular pump.',
    ]);

    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'name' => 'Left ventricle',
        'ta_term' => 'Ventriculus sinister',
        'description' => 'The lower left chamber.',
        'function' => 'Pumps oxygenated blood into the aorta.',
        'location' => 'Lower left of the heart.',
    ]);

    $notes = $this->builder->curatedNotesFor($organ, $structure);

    expect($notes)->toContain('Heart: A four-chambered muscular pump.')
        ->toContain('Left ventricle (Terminologia Anatomica: Ventriculus sinister)')
        ->toContain('Description of Left ventricle: The lower left chamber.')
        ->toContain('Function of Left ventricle: Pumps oxygenated blood into the aorta.')
        ->toContain('Location of Left ventricle: Lower left of the heart.');
});

it('skips metadata an editor has not written yet', function (): void {
    $organ = Organ::factory()->published()->create(['name' => 'Heart', 'description' => null]);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'name' => 'Left ventricle',
        'ta_term' => null,
        'description' => null,
        'function' => 'Pumps blood.',
        'location' => null,
    ]);

    expect($this->builder->curatedNotesFor($organ, $structure))
        ->toBe(['Function of Left ventricle: Pumps blood.']);
});

it('returns no notes when nothing is selected', function (): void {
    expect($this->builder->curatedNotesFor(null, null))->toBe([]);
});

it('reports no recent mistakes until the attempts table exists', function (): void {
    // SEAM — Handover 07. An empty list is the honest answer for a student with
    // no recorded attempts, so the prompt degrades to "no known
    // misconceptions" rather than to something wrong.
    expect($this->builder->build($this->student)->recentMistakes)->toBe([]);
});
