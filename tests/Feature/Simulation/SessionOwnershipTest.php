<?php

declare(strict_types=1);

use App\Models\Organ;
use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;
use App\Services\Simulation\SimulationService;

/*
| Sessions are scoped to the authenticated student
| (docs/handovers/12-simulations.md, tests; docs/engineering.md §10).
|
| Ownership comes from the User the service was handed, never from a request
| field — `SimulationSession::$fillable` omits `user_id` for the same reason
| `Attempt::$fillable` does. These tests attack that from both sides: a payload
| that names another user, and a request made while logged in as one.
*/

beforeEach(function (): void {
    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->simulation = Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve-closure',
    ]);

    $this->student = User::factory()->create();
    $this->classmate = User::factory()->create();
});

it('requires a login on every simulation route', function (string $method, string $uri): void {
    $this->{$method.'Json'}($uri, ['actionId' => 'impair_mitral'])->assertUnauthorized();
})->with([
    'event' => ['post', '/api/v1/simulations/mitral-valve-closure/event'],
    'reset' => ['post', '/api/v1/simulations/mitral-valve-closure/reset'],
]);

it('redirects a guest away from the pages', function (string $uri): void {
    $this->get($uri)->assertRedirect('/login');
})->with([
    '/simulations',
    '/simulations/mitral-valve-closure',
]);

it('files the run against the logged-in student, not a user named in the payload', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/simulations/mitral-valve-closure/event', [
            'actionId' => 'impair_mitral',
            // Not a field the request validates or the model fills. Sent anyway,
            // because "it is ignored" is the assertion.
            'userId' => $this->classmate->getKey(),
            'user_id' => $this->classmate->getKey(),
        ])
        ->assertOk();

    expect(SimulationSession::query()->sole()->user_id)->toBe((int) $this->student->getKey());
});

it('keeps two students runs of the same simulation apart', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertOk();

    $this->actingAs($this->classmate)
        ->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'restore_mitral'])
        ->assertOk()
        // The classmate started from the configuration's initial state, so
        // restoring a valve that was never impaired clamps and changes nothing.
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.stateKey', 'baseline');

    expect(SimulationSession::query()->count())->toBe(2);
});

it('never reads another student\'s session', function (): void {
    SimulationSession::factory()->create([
        'user_id' => $this->classmate->getKey(),
        'simulation_id' => $this->simulation->getKey(),
        'events' => [['sequence' => 1, 'action_id' => 'impair_mitral']],
        'state' => ['valve_closure' => 0.6, 'output' => 0.75, 'oxygenation' => 0.98],
    ]);

    $step = app(SimulationService::class)->currentStep($this->student, $this->simulation);

    expect($step->sequence)->toBe(0)
        ->and(app(SimulationService::class)->eventLog($this->student, $this->simulation))->toBe([]);
});

it('keeps one run per student per simulation', function (): void {
    $this->actingAs($this->student);

    foreach (['impair_mitral', 'restore_mitral', 'impair_mitral'] as $actionId) {
        $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => $actionId])
            ->assertOk();
    }

    expect(SimulationSession::query()->where('user_id', $this->student->getKey())->count())->toBe(1);
});

it('404s a draft simulation for a logged-in student', function (): void {
    Simulation::factory()->for($this->organ)->create(['slug' => 'unfinished']);

    $this->actingAs($this->student);

    // The same 404 an unknown slug gets. Distinguishing them would confirm
    // which drafts exist.
    $this->get('/simulations/unfinished')->assertNotFound();
    $this->postJson('/api/v1/simulations/unfinished/event', ['actionId' => 'impair_mitral'])
        ->assertNotFound();
    $this->get('/simulations/no-such-thing')->assertNotFound();
});
