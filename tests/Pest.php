<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|-------------------------------------------------------------------------------
| Test bootstrap
|-------------------------------------------------------------------------------
|
| RefreshDatabase everywhere, including Unit: several "unit" tests here read
| config or resolve from the container, and a half-configured application is a
| worse debugging experience than a marginally slower suite. The database is
| in-memory SQLite (phpunit.xml), so the cost is small.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // The PHP suite must not depend on `npm run build` having been run.
        // Without this, every page test fails with ViteManifestNotFoundException
        // on a fresh clone and in CI job ordering — a frontend build problem
        // wearing a backend test's clothes. `npm run build` in /verify is what
        // actually guards the bundle.
        $this->withoutVite();
    })
    ->in('Feature', 'Unit');

/*
|-------------------------------------------------------------------------------
| Expectations
|-------------------------------------------------------------------------------
*/

/**
 * Assert a payload carries no correctness data.
 *
 * Invariant 4 (docs/engineering.md §1) is the one rule in this codebase that a
 * student can exploit from the browser's network tab, so every feature shipping
 * a question, mission, or attempt payload is required to assert its absence
 * (docs/engineering.md §9). This lives here so all of them assert it the same
 * way and no lane has to remember the field list.
 */
expect()->extend('toCarryNoAnswerKey', function (): mixed {
    $forbidden = [
        'is_correct',
        'isCorrect',
        'correct_option_id',
        'correctOptionId',
        'correct_structure_id',
        'correctStructureId',
        'correct_sequence',
        'correctSequence',
        'answer',
    ];

    $encoded = json_encode($this->value, JSON_THROW_ON_ERROR);

    foreach ($forbidden as $field) {
        expect($encoded)->not->toContain("\"{$field}\"");
    }

    return $this;
});
