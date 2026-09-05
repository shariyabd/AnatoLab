<?php

declare(strict_types=1);

use App\Enums\MissionStepOutcome;
use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\Organ;
use App\Models\User;

/*
| POST /api/v1/missions/{mission}/attempt — sequence validation and per-step
| scoring (docs/handovers/09-missions.md, "scoring and feedback").
|
| The property under test throughout: every outcome is derived server-side from
| `missions.configuration`, and nothing the client sends can influence it. The
| client has never seen a target, so there is nothing for it to assert.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->atrium = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
        'name' => 'Left Atrium',
    ]);
    $this->ventricle = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left Ventricle',
    ]);
    $this->aorta = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'aorta',
        'name' => 'Aorta',
    ]);
    $this->decoy = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'pulmonary-trunk',
        'name' => 'Pulmonary Trunk',
    ]);

    $this->mission = Mission::factory()
        ->published()
        ->tracing([$this->atrium, $this->ventricle, $this->aorta])
        ->create(['slug' => 'trace-the-blood']);

    $this->trace = fn (array $picks, array $hints = []): array => [
        'steps' => array_map(
            static fn (?int $id, int $index): array => [
                'selectedStructureId' => $id,
                'hintUsed' => in_array($index, $hints, true),
                'timeSpentMs' => 3_000,
            ],
            $picks,
            array_keys($picks),
        ),
        'durationMs' => 12_000,
    ];
});

it('scores a correct sequence in full and marks the run completed', function (): void {
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)([
        $this->atrium->getKey(),
        $this->ventricle->getKey(),
        $this->aorta->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.score', 30)
        ->assertJsonPath('data.maxScore', 30)
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.correctCount', 3)
        ->assertJsonPath('data.firstMissedStep', null)
        ->assertJsonPath('data.feedback', 'Perfect trace — all 3 steps in the right order.')
        ->assertJsonPath('data.perStep.0.outcome', MissionStepOutcome::Correct->value)
        ->assertJsonPath('data.perStep.1.outcome', MissionStepOutcome::Correct->value)
        ->assertJsonPath('data.perStep.2.outcome', MissionStepOutcome::Correct->value);

    $attempt = MissionAttempt::query()->sole();

    expect($attempt->score)->toBe(30)
        ->and($attempt->completed)->toBeTrue()
        ->and($attempt->user_id)->toBe($this->student->getKey())
        ->and($attempt->duration_ms)->toBe(12_000);
});

it('scores an out-of-order run partially and names the step that broke', function (): void {
    // The same three structures, in the wrong order. Step 3 happens to line up
    // — the aorta really is third — so a partial score with a named break is
    // the correct answer, not a flat zero (acceptance criterion 2).
    $response = $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)([
        $this->ventricle->getKey(),
        $this->atrium->getKey(),
        $this->aorta->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.score', 10)
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.correctCount', 1)
        ->assertJsonPath('data.firstMissedStep', 0)
        ->assertJsonPath('data.feedback', '1 of 3 steps traced correctly. The pathway breaks at step 1.')
        ->assertJsonPath('data.perStep.0.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.1.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.2.outcome', MissionStepOutcome::Correct->value);

    // What was picked comes back on every step so the UI can replay the run;
    // what was *right* comes back on the first miss only.
    expect($response->json('data.perStep.0.selectedStructureId'))->toBe((string) $this->ventricle->getKey())
        ->and($response->json('data.perStep.0.revealedStructureId'))->toBe((string) $this->atrium->getKey())
        ->and($response->json('data.perStep.1.revealedStructureId'))->toBeNull();
});

it('pays a hinted step less, and records that the hint was used', function (): void {
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)(
        [$this->atrium->getKey(), $this->ventricle->getKey(), $this->aorta->getKey()],
        hints: [1],
    ))
        ->assertOk()
        // 10 + 6 + 10 rather than 30.
        ->assertJsonPath('data.score', 26)
        // Still completed: a hint changes what a step is worth, not whether it
        // was right (App\Enums\MissionStepOutcome).
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.perStep.1.outcome', MissionStepOutcome::CorrectAfterHint->value)
        ->assertJsonPath('data.perStep.1.points', 6);

    $hinted = App\Models\Attempt::query()->where('hint_used', true)->get();

    expect($hinted)->toHaveCount(1)
        ->and($hinted->first()?->is_correct)->toBeTrue();
});

it('does not pay for a hint that did not save the step', function (): void {
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)(
        [$this->decoy->getKey(), $this->ventricle->getKey(), $this->aorta->getKey()],
        hints: [0],
    ))
        ->assertOk()
        ->assertJsonPath('data.score', 20)
        ->assertJsonPath('data.perStep.0.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.0.points', 0);
});

it('honours the authored scoring table rather than the defaults', function (): void {
    Mission::query()->sole()->update([
        'configuration' => [
            ...Mission::query()->sole()->configuration,
            'scoring' => ['correct' => 25, 'after_hint' => 15, 'wrong' => 0],
        ],
    ]);

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)(
        [$this->atrium->getKey(), $this->ventricle->getKey(), $this->aorta->getKey()],
        hints: [2],
    ))
        ->assertOk()
        ->assertJsonPath('data.score', 65)
        ->assertJsonPath('data.maxScore', 75);
});

it('treats a skipped step and an abandoned run as misses with no pick', function (): void {
    // Two steps submitted for a three-step mission: the run was abandoned. The
    // missing step is scored, not ignored, or a student could bank a perfect
    // score by submitting only the steps they were sure of.
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => $this->atrium->getKey()],
            ['selectedStructureId' => null],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.score', 10)
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.correctCount', 1)
        ->assertJsonPath('data.perStep.1.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.2.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.2.selectedStructureId', null);
});

it('refuses a pick that belongs to another organ', function (): void {
    // The id exists. It is still not an answer to this mission, and the service
    // resolves every pick against the organ payload the client was actually
    // sent (App\Services\Assessment\MissionService::resolvePick).
    $elsewhere = AnatomicalStructure::factory()->published()->create();

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', ($this->trace)([
        $elsewhere->getKey(),
        $this->ventricle->getKey(),
        $this->aorta->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.perStep.0.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.perStep.0.selectedStructureId', null);
});

it('ignores order in an identify mission', function (): void {
    Mission::factory()
        ->published()
        ->identifying([$this->atrium, $this->ventricle, $this->aorta])
        ->create(['slug' => 'find-the-left-side']);

    $this->postJson('/api/v1/missions/find-the-left-side/attempt', ($this->trace)([
        $this->aorta->getKey(),
        $this->atrium->getKey(),
        $this->ventricle->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.score', 30)
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.feedback', 'All 3 found, without a wrong turn.');
});

it('does not let one structure claim two steps of an identify mission', function (): void {
    Mission::factory()
        ->published()
        ->identifying([$this->atrium, $this->ventricle, $this->aorta])
        ->create(['slug' => 'find-the-left-side']);

    $response = $this->postJson('/api/v1/missions/find-the-left-side/attempt', ($this->trace)([
        $this->atrium->getKey(),
        $this->atrium->getKey(),
        $this->aorta->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.correctCount', 2)
        ->assertJsonPath('data.perStep.1.outcome', MissionStepOutcome::Wrong->value)
        ->assertJsonPath('data.feedback', '2 of 3 found. Step 2 is still open.');

    // The reveal names something genuinely still missing, not the step's own
    // positional target — position means nothing in an unordered mission.
    expect($response->json('data.perStep.1.revealedStructureId'))
        ->toBe((string) $this->ventricle->getKey());
});
