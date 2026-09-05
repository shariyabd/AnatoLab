<?php

declare(strict_types=1);

use App\Models\Organ;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
| GET /tutor — the panel on its own route.
|
| Handover 05 owns Explore.vue and is building it in parallel, so the panel
| ships self-contained here and drops into the slot Explore leaves for it
| (docs/handovers/parallel-execution-plan.md D3).
*/

it('renders the panel with the published organ list', function (): void {
    $this->actingAs(User::factory()->create());

    Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    Organ::factory()->create(['slug' => 'draft-organ', 'name' => 'Draft organ']);

    $this->get('/tutor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Tutor/Index')
            ->has('organs', 1)
            ->where('organs.0.slug', 'heart'));
});

it('requires a login', function (): void {
    $this->get('/tutor')->assertRedirect('/login');
});

it('puts no credential into an Inertia prop', function (): void {
    // Secrets are server-side only: never in an Inertia prop, a VITE_* variable,
    // or a client bundle (docs/engineering.md §10).
    config([
        'ai.provider' => 'anthropic',
        'ai.providers.anthropic.api_key' => 'sk-ant-test-must-not-ship',
    ]);

    $this->actingAs(User::factory()->create());

    $body = $this->get('/tutor')->assertOk()->getContent();

    expect($body)
        ->not->toContain('sk-ant-test-must-not-ship')
        ->not->toContain('ANTHROPIC_API_KEY')
        // Not even which provider is configured: that is operational detail a
        // student has no use for and an attacker does.
        ->not->toContain('anthropic');
});

it('keeps the provider name out of a tutor response', function (): void {
    config(['ai.provider' => 'null']);

    $this->actingAs(User::factory()->create());

    $body = json_encode(
        $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'What is an artery?'])
            ->assertOk()
            ->json(),
        JSON_THROW_ON_ERROR,
    );

    expect($body)->not->toContain('anthropic')->not->toContain('openai');
});
