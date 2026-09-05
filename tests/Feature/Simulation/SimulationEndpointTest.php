<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Simulation;
use App\Models\User;

/*
| The two simulation endpoints and the two pages (docs/architecture.md §7, §12).
|
| The payload assertion that matters most here is a negative one: the browser
| is never sent `effects`, `thresholds` or `explanations`. A client holding the
| effect table could compute the next state itself, and a simulation whose
| numbers can be produced in two places is one that can disagree with itself —
| the same rule that keeps a question's answer key out of a quiz payload
| (invariant 4, docs/architecture.md §5.4 rule 2).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $this->mitral = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve',
        'name' => 'Mitral valve',
    ]);

    $this->simulation = Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve-closure',
        'title' => 'What happens if the mitral valve does not close?',
    ]);
});

it('returns the full step shape the viewer and the readouts need', function (): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'sequence', 'actionId', 'actionLabel',
                'stateKey', 'label', 'isTerminal', 'variables', 'affectedStructureIds',
                'outcomes' => [['key', 'label', 'condition', 'terminal']],
                'visualDirectives',
                'explanation', 'explanationSource', 'notice',
            ],
        ])
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.actionId', 'impair_mitral')
        ->assertJsonPath('data.actionLabel', 'Mitral valve does not close fully')
        ->assertJsonPath('data.stateKey', 'reduced_systemic_flow')
        ->assertJsonPath('data.isTerminal', false);
});

it('never ships the effects, thresholds or curated prose to the browser', function (): void {
    $page = $this->get('/simulations/mitral-valve-closure')->assertOk();

    $props = $page->viewData('page')['props'];
    $encoded = json_encode($props, JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toContain('"effects"')
        ->not->toContain('"thresholds"')
        ->not->toContain('"explanations"')
        ->not->toContain('"initial_state"')
        // The action the student may take is listed; what it does is not.
        ->and($props['simulation']['actions'][0])
        ->toHaveKeys(['id', 'label', 'description'])
        ->and(array_keys($props['simulation']['actions'][0]))->toBe(['id', 'label', 'description']);
});

it('hands the page an OrganDto the viewer can mount unchanged', function (): void {
    $this->get('/simulations/mitral-valve-closure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Not wrapped in `data` — the reason SimulationController encodes
            // the Resource before Inertia sees it.
            ->has('simulation.organ.structures', 1)
            ->where('simulation.organ.slug', 'heart')
            ->where('simulation.organ.structures.0.id', (string) $this->mitral->getKey())
            ->has('simulation.organ.modelUrl')
            ->has('simulation.organ.accentColor'));
});

it('lists published simulations on the picker and hides drafts', function (): void {
    Simulation::factory()->for($this->organ)->create(['slug' => 'unfinished', 'title' => 'Draft run']);

    $this->get('/simulations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Simulations/Index')
            ->has('simulations', 1)
            ->where('simulations.0.slug', 'mitral-valve-closure')
            ->where('simulations.0.organ.name', 'Heart'));
});

it('renders the picker without parsing a single configuration', function (): void {
    // A malformed configuration must break its own page and nothing else. The
    // picker reads title, description and organ; it never touches the column.
    Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'broken',
        'configuration' => ['initial_state' => 'not an object'],
    ]);

    $this->get('/simulations')->assertOk()
        ->assertInertia(fn ($page) => $page->has('simulations', 2));
});

it('describes the readouts once, on the simulation rather than on every step', function (): void {
    $this->get('/simulations/mitral-valve-closure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Simulations/Show')
            ->has('simulation.readouts', 3)
            ->where('simulation.readouts.0.key', 'valve_closure')
            ->where('simulation.readouts.0.label', 'Mitral valve closure')
            // 0 and 1, not 0.0 and 1.0: whole floats encode as integers in
            // JSON, and TypeScript sees one `number` either way.
            ->where('simulation.readouts.0.min', 0)
            ->where('simulation.readouts.0.max', 1)
            ->where('simulation.readouts.0.precision', 2));
});

it('rate limits the event endpoint, which is the path that can reach a provider', function (): void {
    $limit = (int) config('ai.limits.requests_per_minute', 10);

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
            ->assertOk();
    }

    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => 'impair_mitral'])
        ->assertStatus(429);
});

it('rejects an action id that could never name an action', function (string $actionId): void {
    $this->postJson('/api/v1/simulations/mitral-valve-closure/event', ['actionId' => $actionId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('actionId');
})->with([
    'a space' => ['impair mitral'],
    'a slash' => ['../../etc/passwd'],
    'an empty string' => [''],
    'too long' => [str_repeat('a', 65)],
]);
