<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Organ;
use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;
use App\Services\Simulation\SimulationStep;
use Tests\Support\RecordingProvider;

/*
| The AI explains the result; it never decides one (PRD §14,
| docs/architecture.md §12).
|
| AI_PROVIDER is pinned to `null` in phpunit.xml, so nothing here can reach a
| real model even by accident (docs/engineering.md §9). Where a test needs a
| specific reply or a failure, the AIProviderInterface binding is swapped —
| never AITutorService itself, which belongs to Handover 08 and has Handover
| 11's retrieval already at its seam
| (docs/handovers/parallel-execution-plan.md D2).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
});

/**
 * A simulation with one action and control over whether its outcome is curated.
 *
 * @param  array<string, string>  $explanations
 */
function explainableSimulation(Organ $organ, array $explanations): Simulation
{
    return Simulation::factory()->published()->for($organ)->create([
        'slug' => 'explainable',
        'configuration' => [
            'initial_state' => ['output' => 1.0],
            'actions' => [['id' => 'drop', 'label' => 'Output falls', 'effects' => ['output' => -0.4]]],
            'thresholds' => [[
                'when' => 'output < 0.8',
                'outcome' => 'low_flow',
                'label' => 'Flow is reduced',
                'explain_key' => 'sim.low_flow',
            ]],
            'explanations' => $explanations,
        ],
    ]);
}

it('prefers curated content and reaches no provider at all', function (): void {
    explainableSimulation($this->organ, [
        'sim.low_flow' => 'Less blood leaves the ventricle with each beat.',
    ]);

    // Any call at all is a failure: a fully authored simulation must run with
    // no provider configured, which is what makes the demo independent of a key.
    app()->instance(AIProviderInterface::class, RecordingProvider::forbidden());

    $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])
        ->assertOk()
        ->assertJsonPath('data.explanation', 'Less blood leaves the ventricle with each beat.')
        ->assertJsonPath('data.explanationSource', SimulationStep::SOURCE_CURATED);
});

it('asks the tutor only when nobody authored an explanation', function (): void {
    explainableSimulation($this->organ, []);
    app()->instance(AIProviderInterface::class, RecordingProvider::answering());

    $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])
        ->assertOk()
        ->assertJsonPath('data.explanation', 'A generated educational explanation.')
        ->assertJsonPath('data.explanationSource', SimulationStep::SOURCE_TUTOR);
});

it('hands the tutor the state it computed, and forbids diagnosis in the prompt', function (): void {
    explainableSimulation($this->organ, []);
    app()->instance(AIProviderInterface::class, RecordingProvider::answering());

    $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])->assertOk();

    /** @var RecordingProvider $provider */
    $provider = app(AIProviderInterface::class);
    $prompt = $provider->prompt();

    expect($prompt)
        // The numbers are stated, not asked for: the transition happened before
        // this call and the model is captioning it.
        ->toContain('Output falls')
        ->toContain('0.6')
        ->toContain('flow is reduced')
        // PRD §24 and §14: educational, never diagnostic.
        ->toContain('do not diagnose')
        ->toContain('not a person');
});

it('threads a run\'s generated explanations into one conversation', function (): void {
    explainableSimulation($this->organ, []);
    app()->instance(AIProviderInterface::class, RecordingProvider::answering());

    $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])->assertOk();
    $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])->assertOk();

    expect(Conversation::query()->count())->toBe(1)
        ->and(SimulationSession::query()->sole()->conversation_id)
        ->toBe((int) Conversation::query()->sole()->getKey())
        // Two turns, each a question and an answer.
        ->and(ConversationMessage::query()->count())->toBe(4);
});

it('still computes the state when the provider fails', function (): void {
    explainableSimulation($this->organ, []);

    app()->instance(AIProviderInterface::class, RecordingProvider::failing());

    $data = $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])
        ->assertOk()
        ->json('data');

    // The deterministic half is untouched — which is the whole reason the
    // provider failure is caught here and not in AITutorService.
    expect($data['variables'])->toEqual(['output' => 0.6])
        ->and($data['stateKey'])->toBe('low_flow')
        ->and($data['explanationSource'])->toBe(SimulationStep::SOURCE_FALLBACK)
        ->and($data['explanation'])->toContain('Flow is reduced')
        // No raw provider error reaches a student (docs/engineering.md §11.7).
        ->and($data['explanation'])->not->toContain('Upstream is down');
});

it('never generates for the untouched starting state', function (): void {
    explainableSimulation($this->organ, []);

    // A forbidden provider, so a page load that reached one throws rather than
    // quietly costing a call.
    app()->instance(AIProviderInterface::class, RecordingProvider::forbidden());

    $this->get('/simulations/explainable')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('step.sequence', 0)
            ->where('step.explanationSource', SimulationStep::SOURCE_FALLBACK));
});

it('labels every step as educational rather than diagnostic', function (): void {
    explainableSimulation($this->organ, ['sim.low_flow' => 'Curated.']);

    $notice = $this->postJson('/api/v1/simulations/explainable/event', ['actionId' => 'drop'])
        ->assertOk()
        ->json('data.notice');

    expect($notice)
        ->toContain('educational model')
        ->toContain('not a medical diagnosis');
});
