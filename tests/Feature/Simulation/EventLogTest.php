<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;

/*
| The event log records every action in order (docs/handovers/12-simulations.md).
|
| It is not a convenience: `events` is the run, and `state` and `result` are
| caches of replaying it. A log that lost an entry, reordered two, or recorded
| an action that was never taken would silently change what a replay produces
| — which is why the ordering assertions below are on the stored rows rather
| than on the response.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($this->organ)->create(['slug' => 'mitral-valve']);

    $this->simulation = Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve-closure',
    ]);
});

function applyAction(string $actionId): array
{
    return test()->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => $actionId])
        ->assertOk()
        ->json('data');
}

it('appends one entry per action, in the order they were taken', function (): void {
    applyAction('impair_mitral');
    applyAction('restore_mitral');
    applyAction('impair_mitral');

    $events = SimulationSession::query()->sole()->events;

    expect($events)->toHaveCount(3)
        ->and(array_column($events, 'action_id'))
        ->toBe(['impair_mitral', 'restore_mitral', 'impair_mitral'])
        ->and(array_column($events, 'sequence'))->toBe([1, 2, 3]);
});

it('records the state and outcomes each action produced', function (): void {
    applyAction('impair_mitral');

    $entry = SimulationSession::query()->sole()->events[0];

    expect($entry['state'])->toEqual(['valve_closure' => 0.6, 'output' => 0.75, 'oxygenation' => 0.98])
        ->and($entry['state_key'])->toBe('reduced_systemic_flow')
        ->and($entry['outcomes'])->toBe(['reduced_systemic_flow']);
});

it('carries no timestamp, so the log itself replays identically', function (): void {
    applyAction('impair_mitral');

    // A wall clock is the one value in a log entry that cannot be reproduced.
    // Recency lives on the row's updated_at, where it belongs.
    expect(SimulationSession::query()->sole()->events[0])
        ->not->toHaveKey('at')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('timestamp');
});

it('keeps the prose already written for an earlier step', function (): void {
    applyAction('impair_mitral');

    $first = SimulationSession::query()->sole()->events[0]['explanation'];

    applyAction('restore_mitral');

    $events = SimulationSession::query()->sole()->events;

    expect($first)->not->toBeNull()
        ->and($events[0]['explanation'])->toBe($first)
        ->and($events[1]['explanation'])->not->toBeNull()
        ->and($events[1]['explanation'])->not->toBe($first);
});

it('records the standing result after the last step', function (): void {
    applyAction('impair_mitral');

    expect(SimulationSession::query()->sole()->result)->toBe([
        'state_key' => 'reduced_systemic_flow',
        'label' => 'Less blood reaches the body with each beat',
        'outcomes' => ['reduced_systemic_flow'],
        'is_terminal' => false,
    ]);
});

it('empties the log on reset without starting a second run', function (): void {
    applyAction('impair_mitral');
    applyAction('restore_mitral');

    $this->postJson('/api/v1/simulations/mitral-valve-closure/reset')
        ->assertOk()
        ->assertJsonPath('data.sequence', 0)
        ->assertJsonPath('data.stateKey', 'baseline');

    $session = SimulationSession::query()->sole();

    expect($session->events)->toBe([])
        ->and($session->result)->toBeNull()
        ->and($session->state)->toEqual(['valve_closure' => 1.0, 'output' => 1.0, 'oxygenation' => 0.98])
        ->and(SimulationSession::query()->count())->toBe(1);
});

it('resumes a run across page loads from the stored log', function (): void {
    applyAction('impair_mitral');

    $this->get('/simulations/mitral-valve-closure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('step.sequence', 1)
            ->where('step.actionId', 'impair_mitral')
            ->where('step.stateKey', 'reduced_systemic_flow')
            ->count('events', 1));
});

it('rejects an action this simulation does not declare', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'not-an-action'])
        ->assertNotFound();

    expect(SimulationSession::query()->count())->toBe(0);
});

it('rejects a payload with no action at all', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('actionId');
});
