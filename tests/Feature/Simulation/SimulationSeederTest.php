<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Models\AnatomicalStructure;
use App\Models\Simulation;
use App\Models\User;
use App\Services\Simulation\SimulationConfiguration;
use App\Services\Simulation\SimulationEngine;
use App\Services\Simulation\SimulationService;
use App\Services\Simulation\SimulationStep;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\SimulationSeeder;
use Tests\Support\RecordingProvider;

/*
| The shipped demo content (docs/handovers/12-simulations.md, PRD §2.3 step 7).
|
| Content tests, not engine tests. The engine is proved elsewhere; what is
| proved here is that the two configurations this project actually ships are
| well-formed, resolve every structure they name, and are curated end to end —
| the three ways authored JSON can be wrong without any code being wrong.
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
    $this->seed(SimulationSeeder::class);

    $this->service = app(SimulationService::class);
});

it('seeds the heart valve simulation the demo story runs', function (): void {
    $simulation = $this->service->findPublished('mitral-valve-closure');

    expect($simulation)->not->toBeNull()
        ->and($simulation->organ->slug)->toBe('heart')
        ->and($simulation->title)->toContain('mitral valve');
});

it('parses every shipped configuration', function (): void {
    // Parsing throws on a malformed configuration, so reaching the assertion
    // at all is most of the test.
    foreach (Simulation::query()->get() as $simulation) {
        expect($this->service->configurationFor($simulation))
            ->toBeInstanceOf(SimulationConfiguration::class);
    }
});

it('names only structures its organ actually publishes', function (): void {
    foreach (Simulation::query()->with('organ')->get() as $simulation) {
        $configuration = $this->service->configurationFor($simulation);

        foreach ($configuration->structureSlugs() as $slug) {
            $published = AnatomicalStructure::query()
                ->published()
                ->where('organ_id', $simulation->organ_id)
                ->where('slug', $slug)
                ->exists();

            // An unresolvable slug is dropped silently at render time by
            // design (VisualDirectives). This assertion is what stops the drop
            // being how a typo is discovered.
            expect($published)->toBeTrue(
                "`{$simulation->slug}` points at `{$slug}`, which `{$simulation->organ->slug}` does not publish."
            );
        }
    }
});

it('has curated prose for the baseline and for every reachable outcome', function (): void {
    // With every state authored, the demo runs with no AI provider configured
    // at all — which is what makes it independent of a key and a network.
    app()->instance(AIProviderInterface::class, RecordingProvider::forbidden());

    $student = User::factory()->create();
    $engine = app(SimulationEngine::class);

    foreach (Simulation::query()->get() as $simulation) {
        $configuration = $this->service->configurationFor($simulation);
        $actionIds = array_keys($configuration->actions);

        expect(array_key_exists(SimulationConfiguration::BASELINE_KEY, $configuration->explanations))
            ->toBeTrue("`{$simulation->slug}` has no curated prose for its baseline.");

        // Every action applied twice, then all of them again in reverse: enough
        // to drive each variable to a clamp and back and to fire every
        // threshold the configuration declares.
        $sequence = [...$actionIds, ...$actionIds, ...array_reverse($actionIds)];

        foreach ($engine->replay($simulation->slug, $configuration, $sequence) as $step) {
            foreach ($step->fired as $threshold) {
                expect($threshold->explainKey)->not->toBeNull(
                    "`{$simulation->slug}` outcome `{$threshold->outcome}` has no explain_key."
                );

                expect(array_key_exists($threshold->explainKey, $configuration->explanations))
                    ->toBeTrue(
                        "`{$simulation->slug}` has no curated prose for `{$threshold->explainKey}`."
                    );
            }
        }

        // And through the real service, which is what actually chooses a source.
        $this->actingAs($student);
        $step = $this->service->applyAction($student, $simulation, $actionIds[0]);

        expect($step->explanationSource)->toBe(SimulationStep::SOURCE_CURATED);
    }
});

it('returns every run to its exact starting state when undone', function (): void {
    $engine = app(SimulationEngine::class);

    foreach (Simulation::query()->get() as $simulation) {
        $configuration = $this->service->configurationFor($simulation);
        $actionIds = array_keys($configuration->actions);

        $steps = $engine->replay($simulation->slug, $configuration, [...$actionIds, ...$actionIds]);
        $final = $steps[count($steps) - 1];

        // Each simulation ships the action that undoes its damage, applied last
        // by the ordering above. Clamping is only visible when a student can
        // drive a variable back to its ceiling.
        expect($final->state->variables)->toBe(
            $configuration->initialState,
            "`{$simulation->slug}` does not return to its starting state."
        );
        expect($final->state->stateKey)->toBe(SimulationConfiguration::BASELINE_KEY);
    }
});

it('runs the demo story end to end and reproduces it', function (): void {
    $student = User::factory()->create();
    $this->actingAs($student);

    $simulation = $this->service->findPublished('mitral-valve-closure');

    $run = function () use ($simulation): array {
        $last = null;

        foreach (['impair_mitral', 'raise_demand'] as $actionId) {
            $last = $this->postJson("/api/v1/simulations/{$simulation->slug}/event", [
                'actionId' => $actionId,
            ])->assertOk()->json('data');
        }

        return $last;
    };

    $first = $run();

    expect($first['stateKey'])->toBe('reduced_oxygen_delivery')
        ->and($first['isTerminal'])->toBeTrue()
        ->and($first['outcomes'])->toHaveCount(3)
        ->and($first['explanationSource'])->toBe(SimulationStep::SOURCE_CURATED)
        // The 3D view changed: the mitral valve is highlighted and the camera
        // has been flown to the left ventricle (PRD §2.3 step 7).
        ->and($first['visualDirectives'])->toHaveKeys(['highlight', 'tint', 'pulseRate', 'focus'])
        ->and($first['affectedStructureIds'])->not->toBeEmpty();

    $this->postJson("/api/v1/simulations/{$simulation->slug}/reset")->assertOk();

    expect($run())->toEqual($first);
});

it('is idempotent', function (): void {
    $before = Simulation::query()->count();

    $this->seed(SimulationSeeder::class);

    expect(Simulation::query()->count())->toBe($before);
});
