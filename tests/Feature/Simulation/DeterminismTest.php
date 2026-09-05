<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;
use App\Services\Simulation\SimulationConfiguration;
use App\Services\Simulation\SimulationEngine;
use App\Services\Simulation\SimulationService;

/*
| The acceptance criterion this lane exists to satisfy: replaying the same
| action sequence reproduces the same state exactly
| (docs/handovers/12-simulations.md, PRD §14).
|
| "Exactly" is meant literally throughout this file. Every assertion is an
| equality, never a comparison within a tolerance — the engine clamps and then
| rounds to each variable's declared precision, so a state survives the JSON
| round-trip through `simulation_sessions.state` and comes back identical
| rather than nearly identical. A test written with an epsilon would pass while
| that property was broken.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($this->organ)->create(['slug' => 'mitral-valve']);

    $this->simulation = Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve-closure',
    ]);

    $this->engine = app(SimulationEngine::class);
    $this->service = app(SimulationService::class);
    $this->configuration = $this->service->configurationFor($this->simulation);
});

it('produces an identical final state for the same sequence, every time', function (): void {
    $sequence = ['impair_mitral', 'impair_mitral', 'restore_mitral'];

    $first = $this->engine->replay('mitral-valve-closure', $this->configuration, $sequence);
    $second = $this->engine->replay('mitral-valve-closure', $this->configuration, $sequence);

    $final = static fn (array $steps): array => $steps[count($steps) - 1]->state->variables;

    expect($final($second))->toBe($final($first));
});

it('produces an identical step for step, not merely an identical ending', function (): void {
    $sequence = ['impair_mitral', 'restore_mitral', 'impair_mitral'];

    $first = $this->engine->replay('mitral-valve-closure', $this->configuration, $sequence);
    $second = $this->engine->replay('mitral-valve-closure', $this->configuration, $sequence);

    foreach ($first as $index => $step) {
        expect($second[$index]->state->variables)->toBe($step->state->variables)
            ->and($second[$index]->state->stateKey)->toBe($step->state->stateKey)
            ->and($second[$index]->directives->toArray())->toBe($step->directives->toArray());
    }
});

it('reproduces the run through the endpoint after a reset', function (): void {
    $sequence = ['impair_mitral', 'impair_mitral', 'restore_mitral'];

    $run = function (array $sequence): array {
        $body = [];

        foreach ($sequence as $actionId) {
            $body = $this->postJson('/api/v1/simulations/mitral-valve-closure/event', [
                'actionId' => $actionId,
            ])->assertOk()->json('data');
        }

        return $body;
    };

    $first = $run($sequence);

    $this->postJson('/api/v1/simulations/mitral-valve-closure/reset')->assertOk();

    $second = $run($sequence);

    expect($second['variables'])->toBe($first['variables'])
        ->and($second['stateKey'])->toBe($first['stateKey'])
        ->and($second['visualDirectives'])->toBe($first['visualDirectives'])
        ->and($second['isTerminal'])->toBe($first['isTerminal']);
});

it('survives the database round-trip with the state unchanged', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertOk();

    $stored = SimulationSession::query()->sole();

    $replayed = $this->engine->replay(
        'mitral-valve-closure',
        $this->configuration,
        $stored->actionSequence(),
    );

    // Not a tolerance: the stored floats came back through JSON and must equal
    // the recomputed ones on the nose.
    expect($stored->state)->toEqual($replayed[count($replayed) - 1]->state->variables);
});

it('never trusts the stored state, only the stored action log', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertOk();

    // Corrupt the cache. A service that resumed from it would carry the lie
    // forward; one that replays the log cannot.
    $session = SimulationSession::query()->sole();
    $session->fill(['state' => ['valve_closure' => 0.0, 'output' => 0.0, 'oxygenation' => 0.0]])->save();

    $step = $this->service->currentStep($this->student, $this->simulation);

    expect($step->state->variables)->toBe(['valve_closure' => 0.6, 'output' => 0.75, 'oxygenation' => 0.98]);
});

it('starts every run from the configuration, not from another student', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertOk();

    $other = User::factory()->create();

    $step = $this->service->currentStep($other, $this->simulation);

    expect($step->sequence)->toBe(0)
        ->and($step->state->variables)->toBe($this->configuration->initialState)
        ->and($step->state->stateKey)->toBe(SimulationConfiguration::BASELINE_KEY);
});
