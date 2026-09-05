<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\Organ;
use App\Models\User;

/*
|-------------------------------------------------------------------------------
| THE NON-NEGOTIABLE ONE
|-------------------------------------------------------------------------------
|
| Invariant 4, in this lane's form: the client receives prompts and hints and
| never the target sequence (docs/handovers/09-missions.md, "the rule that
| matters"; docs/architecture.md §5.4 rule 2). It is the single rule a student
| can exploit from the browser's network tab, so every payload this lane emits
| that carries a mission is asserted here — one test per endpoint, plus the
| Inertia page props, which are a payload too and are the one people forget.
|
| The attempt *response* is deliberately excluded from the blanket assertion:
| docs/architecture.md §9 specifies that a graded run returns per-step feedback,
| and walking a student back through a pathway means naming where it went. The
| last two tests pin the difference — it names at most one target, on the first
| missed step, and only after the run has been recorded.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->first = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
        'name' => 'Left Atrium',
    ]);
    $this->second = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left Ventricle',
    ]);
    $this->third = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'aorta',
        'name' => 'Aorta',
    ]);

    $this->mission = Mission::factory()
        ->published()
        ->tracing([$this->first, $this->second, $this->third])
        ->create(['slug' => 'trace-the-blood', 'title' => 'Trace the Blood']);
});

it('GET /api/v1/missions/{mission} carries no answer key', function (): void {
    $response = $this->getJson('/api/v1/missions/trace-the-blood')->assertOk();

    expect($response->json())->toCarryNoAnswerKey();
});

it('GET /api/v1/missions carries no answer key', function (): void {
    $response = $this->getJson('/api/v1/missions')->assertOk();

    expect($response->json())->toCarryNoAnswerKey();
});

it('GET /missions/{mission} page props carry no answer key', function (): void {
    // The page ships the whole mission as an Inertia prop rather than fetching
    // it, so the props are a payload with exactly the same obligation as the
    // JSON endpoint — and they are embedded in the HTML, where "view source" is
    // the network tab.
    $response = $this->get('/missions/trace-the-blood')->assertOk();

    expect($response->viewData('page')['props'])->toCarryNoAnswerKey();
});

it('GET /missions page props carry no answer key', function (): void {
    $response = $this->get('/missions')->assertOk();

    expect($response->viewData('page')['props'])->toCarryNoAnswerKey();
});

it('emits exactly three keys per step, and none of them is the target', function (): void {
    $response = $this->getJson('/api/v1/missions/trace-the-blood')->assertOk();

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $response->json('data.steps');

    expect($steps)->toHaveCount(3);

    foreach ($steps as $step) {
        expect(array_keys($step))->toBe(['index', 'prompt', 'hint']);
    }
});

it('never emits the structure_id key the configuration is authored with', function (): void {
    // The organ payload legitimately contains every structure and its slug, so
    // the assertion is about the *step* shape: the encoded steps must not name
    // a structure at all. That is what makes the sequence unreachable — the
    // student can see all nine structures and still not know which three, in
    // which order.
    $response = $this->getJson('/api/v1/missions/trace-the-blood')->assertOk();

    $steps = json_encode($response->json('data.steps'), JSON_THROW_ON_ERROR);

    expect($steps)
        ->not->toContain('structure_id')
        ->not->toContain('structureId')
        ->not->toContain('left-atrium')
        ->not->toContain('left-ventricle')
        ->not->toContain('aorta');
});

it('omits the per-step explanation until a run has been recorded', function (): void {
    // Each explanation names its own target in prose. It is an answer key that
    // happens to be readable — the same thing `questions.explanation` is.
    $mission = Mission::query()->sole();
    $configuration = $mission->configuration;
    $configuration['steps'][0]['explanation'] = 'It is the left atrium, obviously.';
    $mission->update(['configuration' => $configuration]);

    $response = $this->getJson('/api/v1/missions/trace-the-blood')->assertOk();

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('It is the left atrium, obviously.');
});

it('cannot be made to reveal a target without recording a run', function (): void {
    // The reveal is only safe because getting it costs a recorded run. If a
    // probe were ever free, the response would become an answer key with extra
    // steps (App\Services\Assessment\MissionStepResult).
    $before = MissionAttempt::query()->count();

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => null],
            ['selectedStructureId' => null],
            ['selectedStructureId' => null],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.perStep.0.revealedStructureId', (string) $this->first->getKey());

    expect(MissionAttempt::query()->count())->toBe($before + 1);
});

it('reveals at most one target per run, however badly the run goes', function (): void {
    // A mission grades a whole sequence in one request. Revealing every target
    // would sell the sequence for a single throwaway run, which the student
    // could then replay perfectly — so the reveal stops at the first miss and
    // the run's economics stay the quiz's: one recorded attempt, one answer.
    $response = $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => $this->third->getKey()],
            ['selectedStructureId' => $this->first->getKey()],
            ['selectedStructureId' => $this->second->getKey()],
        ],
    ])->assertOk();

    /** @var array<int, array<string, mixed>> $perStep */
    $perStep = $response->json('data.perStep');

    $revealed = array_values(array_filter(
        array_map(static fn (array $step): mixed => $step['revealedStructureId'], $perStep),
    ));

    expect($revealed)->toBe([(string) $this->first->getKey()]);
});
