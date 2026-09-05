<?php

declare(strict_types=1);

use App\Enums\MissionStatus;
use App\Enums\MissionType;
use App\Models\Mission;
use App\Models\User;
use App\Services\Assessment\MissionService;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\MissionSeeder;

/*
| The demo content (docs/handovers/09-missions.md, Content).
|
| "Trace the Blood" is named in PRD §13 and Handover 14's demo journey walks it,
| so these assertions are about a fixture other lanes depend on, not just about
| a seeder running without error.
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
    $this->seed(MissionSeeder::class);
});

it('seeds Trace the Blood as a published, runnable pathway on the heart', function (): void {
    $mission = Mission::query()->where('slug', 'trace-the-blood')->sole();

    expect($mission->title)->toBe('Trace the Blood')
        ->and($mission->type)->toBe(MissionType::TracePathway)
        ->and($mission->status)->toBe(MissionStatus::Published)
        ->and($mission->organ->slug)->toBe('heart');

    // The pathway docs/architecture.md §9 authors verbatim.
    $definition = app(MissionService::class)->findPublishedBySlug('trace-the-blood');

    expect($definition)->not->toBeNull()
        ->and(array_map(
            static fn (App\Services\Assessment\MissionStep $step): string => $step->targetSlug,
            $definition->configuration->steps,
        ))->toBe(['left-atrium', 'left-ventricle', 'aorta']);
});

it('seeds a second mission of a different type', function (): void {
    // Proves the configuration generalises past a single ordered pathway
    // (docs/handovers/09-missions.md, Content).
    $mission = Mission::query()->where('slug', 'name-the-airways')->sole();

    expect($mission->type)->toBe(MissionType::Identify)
        ->and($mission->organ->slug)->toBe('lungs')
        ->and(app(MissionService::class)->findPublishedBySlug('name-the-airways'))->not->toBeNull();
});

it('never names a target in a prompt or a hint', function (): void {
    // Prompts and hints are the strings that reach the browser. One reading
    // "click the left atrium" would ship the answer key in the one field that
    // is supposed to be safe (MissionSeeder).
    $missions = app(MissionService::class)->listPublished();

    expect($missions)->not->toBeEmpty();

    foreach ($missions as $definition) {
        foreach ($definition->configuration->steps as $step) {
            $target = $definition->targets->get($step->targetSlug);
            $name = mb_strtolower((string) $target?->name);
            $visible = mb_strtolower($step->prompt.' '.$step->hint);

            expect($visible)->not->toContain($name, "A prompt or hint names its own target: {$step->prompt}");
        }
    }
});

it('is idempotent', function (): void {
    $before = Mission::query()->count();

    $this->seed(MissionSeeder::class);

    expect(Mission::query()->count())->toBe($before);
});

it('runs Trace the Blood end to end', function (): void {
    // Acceptance criterion 1, against the seeded content rather than a factory.
    $this->actingAs(User::factory()->create());

    $mission = $this->getJson('/api/v1/missions/trace-the-blood')->assertOk();

    /** @var array<int, array{id: string, slug: string}> $structures */
    $structures = $mission->json('data.organ.structures');
    $bySlug = array_column($structures, 'id', 'slug');

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => (int) $bySlug['left-atrium'], 'timeSpentMs' => 4_000],
            ['selectedStructureId' => (int) $bySlug['left-ventricle'], 'timeSpentMs' => 5_000],
            ['selectedStructureId' => (int) $bySlug['aorta'], 'timeSpentMs' => 3_000],
        ],
        'durationMs' => 12_000,
    ])
        ->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.score', 30)
        ->assertJsonPath('data.perStep.2.explanation', fn (?string $explanation): bool => is_string($explanation)
            && str_contains($explanation, 'aorta'));
});
