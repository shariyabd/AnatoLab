<?php

declare(strict_types=1);

use App\Http\Resources\Simulations\SimulationStepResource;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Simulation;
use App\Models\User;
use App\Services\Simulation\VisualDirectives;

/*
| The visual vocabulary is closed — five directives and no sixth
| (docs/architecture.md §12, resources/js/anatomy/simulation.ts).
|
| The rule is not "we only author five". It is that nothing else can survive
| parsing, so `visualDirectives` provably contains only keys the viewer honours
| and the client can hand the object to `applySimulationState()` untouched. A
| directive the viewer would silently ignore is worse than none, because the UI
| would then advertise a change that does not happen (docs/architecture.md §5.3).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->mitral = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'mitral-valve',
    ]);
});

/**
 * @param  array<string, mixed>  $visual
 */
function directiveRun(Organ $organ, array $visual): Simulation
{
    return Simulation::factory()->published()->for($organ)->create([
        'slug' => 'directives',
        'configuration' => [
            'initial_state' => ['output' => 1.0],
            'actions' => [['id' => 'go', 'label' => 'Go', 'effects' => [], 'visual' => $visual]],
        ],
    ]);
}

it('emits only the five permitted keys, whatever the configuration asked for', function (): void {
    directiveRun($this->organ, [
        'highlight' => 'mitral-valve',
        'tint' => '#d1584f',
        'pulse_rate' => 1.35,
        'focus' => 'mitral-valve',
        'cross_section' => ['enabled' => true, 'axis' => 'y', 'offset' => 0.4],

        // Everything a single-mesh model cannot do. Each of these would need
        // per-structure geometry, and each must be gone by the time the
        // payload is built.
        'hide' => 'left-atrium',
        'explode' => 1.4,
        'animate' => 'systole',
        'opacity' => 0.5,
        'camera' => ['x' => 1, 'y' => 2, 'z' => 3],
    ]);

    $directives = $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
        ->assertOk()
        ->json('data.visualDirectives');

    expect(array_keys($directives))
        ->toEqualCanonicalizing(SimulationStepResource::permittedDirectiveKeys())
        ->and(array_keys($directives))->not->toContain('hide', 'explode', 'animate', 'opacity', 'camera');
});

it('exposes exactly the vocabulary the viewer library declares', function (): void {
    // The other half of this assertion lives in
    // resources/js/anatomy/simulation.ts as VISUAL_DIRECTIVE_KEYS. Two lists,
    // one vocabulary; a Vitest test asserts the viewer honours each of them.
    expect(VisualDirectives::KEYS)
        ->toBe(['highlight', 'tint', 'pulseRate', 'focus', 'crossSection']);
});

it('resolves authored slugs into the opaque ids the viewer round-trips', function (): void {
    directiveRun($this->organ, ['highlight' => 'mitral-valve', 'focus' => 'mitral-valve']);

    $data = $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
        ->assertOk()
        ->json('data');

    expect($data['visualDirectives']['highlight'])->toBe((string) $this->mitral->getKey())
        ->and($data['visualDirectives']['focus'])->toBe((string) $this->mitral->getKey())
        ->and($data['affectedStructureIds'])->toBe([(string) $this->mitral->getKey()]);
});

it('drops a structure the organ does not publish rather than pointing at nothing', function (): void {
    AnatomicalStructure::factory()->for($this->organ)->create(['slug' => 'draft-structure']);

    directiveRun($this->organ, ['highlight' => 'draft-structure', 'tint' => '#123456']);

    $data = $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
        ->assertOk()
        ->json('data');

    expect($data['visualDirectives'])->toBe(['tint' => '#123456'])
        ->and($data['affectedStructureIds'])->toBe([]);
});

it('drops a value the viewer could not honour and keeps the rest', function (): void {
    directiveRun($this->organ, [
        'tint' => 'crimson',
        'pulse_rate' => 'fast',
        'cross_section' => ['enabled' => true, 'axis' => 'w'],
        'highlight' => 'mitral-valve',
    ]);

    $directives = $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
        ->assertOk()
        ->json('data.visualDirectives');

    expect($directives)->toBe(['highlight' => (string) $this->mitral->getKey()]);
});

it('clamps a pulse rate rather than sending one the marker cannot show', function (): void {
    directiveRun($this->organ, ['pulse_rate' => 99]);

    // toEqual rather than toBe: 4.0 encodes as `4` in JSON and decodes as an
    // int. TypeScript sees one `number` either way, and asserting the PHP type
    // of a value that has been through the wire would be asserting the wrong
    // thing.
    expect(
        $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
            ->assertOk()
            ->json('data.visualDirectives.pulseRate')
    )->toEqual(4.0);
});

it('clamps a cross-section plane to the normalised model extent', function (): void {
    directiveRun($this->organ, ['cross_section' => ['enabled' => true, 'axis' => 'x', 'offset' => 50]]);

    // FIT_SIZE is 3.8 and the model is centred on the origin, so half of it is
    // as far as a plane can travel and still cut anything
    // (docs/architecture.md §5.4 rule 1).
    expect(
        $this->postJson('/api/v1/simulations/directives/event', ['actionId' => 'go'])
            ->assertOk()
            ->json('data.visualDirectives.crossSection.offset')
    )->toBe(1.9);
});

it('accumulates directives across a run and lets null clear one', function (): void {
    Simulation::factory()->published()->for($this->organ)->create([
        'slug' => 'accumulate',
        'configuration' => [
            'initial_state' => ['output' => 1.0],
            'actions' => [
                [
                    'id' => 'set',
                    'label' => 'Set',
                    'effects' => [],
                    'visual' => ['tint' => '#d1584f', 'pulse_rate' => 1.35],
                ],
                [
                    'id' => 'clear_tint',
                    'label' => 'Clear the tint only',
                    'effects' => [],
                    'visual' => ['tint' => null],
                ],
            ],
        ],
    ]);

    $this->postJson('/api/v1/simulations/accumulate/event', ['actionId' => 'set'])->assertOk();

    $directives = $this->postJson('/api/v1/simulations/accumulate/event', ['actionId' => 'clear_tint'])
        ->assertOk()
        ->json('data.visualDirectives');

    // The tint is present and null — "clear it" — while the pulse rate the
    // earlier action set is untouched. Absent would mean "leave it alone",
    // which is the opposite instruction.
    expect($directives)->toHaveKey('tint')
        ->and($directives['tint'])->toBeNull()
        ->and($directives['pulseRate'])->toBe(1.35);
});
