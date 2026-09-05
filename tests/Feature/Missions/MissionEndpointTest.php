<?php

declare(strict_types=1);

use App\Enums\MissionStatus;
use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\User;

/*
| GET /api/v1/missions and GET /api/v1/missions/{mission}
| (docs/architecture.md §7).
|
| One theme runs through the 404s: an unknown slug, a draft mission, a mission
| on a draft organ and a mission whose targets are unpublished are the same
| response. Distinguishing them would confirm which drafts exist.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);

    $this->atrium = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
    ]);
    $this->ventricle = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
    ]);
});

it('requires a login', function (): void {
    // A mission payload embeds an OrganDto, model URL included, and those must
    // not be reachable without a login until the licence gate closes
    // (docs/licence-log.md §3, routes/features/missions.php).
    $this->getJson('/api/v1/missions')->assertUnauthorized();
    $this->getJson('/api/v1/missions/anything')->assertUnauthorized();
    $this->postJson('/api/v1/missions/anything/attempt', ['steps' => []])->assertUnauthorized();
});

it('serves a published mission with its organ as a plain OrganDto', function (): void {
    Mission::factory()
        ->published()
        ->tracing([$this->atrium, $this->ventricle])
        ->create(['slug' => 'trace-the-blood', 'title' => 'Trace the Blood', 'difficulty' => 2]);

    $this->actingAs($this->student)
        ->getJson('/api/v1/missions/trace-the-blood')
        ->assertOk()
        ->assertJsonPath('data.slug', 'trace-the-blood')
        ->assertJsonPath('data.title', 'Trace the Blood')
        ->assertJsonPath('data.type', 'trace_pathway')
        ->assertJsonPath('data.ordered', true)
        ->assertJsonPath('data.difficulty', 2)
        ->assertJsonPath('data.stepCount', 2)
        ->assertJsonPath('data.maxScore', 20)
        ->assertJsonPath('data.scoring.correct', 10)
        ->assertJsonPath('data.scoring.afterHint', 6)
        // The organ arrives in exactly the shape resources/js/anatomy/types.ts
        // describes, so the page hands it to the viewer with no transform step.
        ->assertJsonPath('data.organ.slug', 'heart')
        ->assertJsonStructure([
            'data' => ['organ' => ['id', 'slug', 'name', 'modelUrl', 'modelFormat', 'accentColor', 'structures']],
        ]);
});

it('lists only missions a student can actually run', function (): void {
    Mission::factory()->published()->tracing([$this->atrium])->create(['slug' => 'runnable']);
    Mission::factory()->tracing([$this->atrium])->create(['slug' => 'still-a-draft']);

    // Published, but its one step points at a structure that is not.
    $unpublished = AnatomicalStructure::factory()->for($this->organ)->create([
        'slug' => 'hidden-structure',
        'is_published' => false,
    ]);
    Mission::factory()->published()->tracing([$unpublished])->create(['slug' => 'unreachable-target']);

    // Published, on an organ that is not.
    $draftOrgan = Organ::factory()->create(['slug' => 'liver']);
    $onDraftOrgan = AnatomicalStructure::factory()->published()->for($draftOrgan)->create();
    Mission::factory()->published()->tracing([$onDraftOrgan])->create(['slug' => 'on-a-draft-organ']);

    $response = $this->actingAs($this->student)->getJson('/api/v1/missions')->assertOk();

    expect(array_column($response->json('data'), 'slug'))->toBe(['runnable']);

    // The nested organ summary is resolved, not left as a Resource object — the
    // failure Web\MissionController::index() documents from the Inertia side.
    $response->assertJsonPath('data.0.organ.slug', 'heart')
        ->assertJsonPath('data.0.organ.name', 'Heart')
        // No structures on a card: it advertises a mission, and a picker that
        // ships every structure of every organ fetches a page to draw a list.
        ->assertJsonMissingPath('data.0.organ.structures');
});

it('404s on an unknown slug, a draft, a draft organ and an unreachable target alike', function (): void {
    Mission::factory()->tracing([$this->atrium])->create(['slug' => 'still-a-draft']);

    $unpublished = AnatomicalStructure::factory()->for($this->organ)->create([
        'slug' => 'hidden-structure',
        'is_published' => false,
    ]);
    Mission::factory()->published()->tracing([$unpublished])->create(['slug' => 'unreachable-target']);

    $draftOrgan = Organ::factory()->create(['slug' => 'liver']);
    $onDraftOrgan = AnatomicalStructure::factory()->published()->for($draftOrgan)->create();
    Mission::factory()->published()->tracing([$onDraftOrgan])->create(['slug' => 'on-a-draft-organ']);

    foreach (['no-such-mission', 'still-a-draft', 'unreachable-target', 'on-a-draft-organ'] as $slug) {
        $this->actingAs($this->student)
            ->getJson("/api/v1/missions/{$slug}")
            ->assertNotFound();
    }
});

it('404s on a mission whose configuration has no usable steps', function (): void {
    // Serving it would be a mission a student opens and cannot start. The same
    // call AssessmentService makes about a spatial question with no resolvable
    // answer.
    Mission::factory()->published()->create([
        'slug' => 'empty-mission',
        'organ_id' => $this->organ->getKey(),
        'configuration' => ['steps' => [['prompt' => 'A step with no target.']]],
        'status' => MissionStatus::Published,
    ]);

    $this->actingAs($this->student)
        ->getJson('/api/v1/missions/empty-mission')
        ->assertNotFound();
});

it('rejects a submission that is not a list of steps', function (): void {
    Mission::factory()->published()->tracing([$this->atrium])->create(['slug' => 'trace-the-blood']);

    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('steps');

    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [
            'steps' => [['selectedStructureId' => 'not-an-id']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('steps.0.selectedStructureId');
});

it('caps a step timer at an hour and a run at four', function (): void {
    // Timing feeds mastery's recency and speed signals; an unbounded number
    // from a client is a number that can be made to mean anything
    // (RecordMissionAttemptRequest).
    Mission::factory()->published()->tracing([$this->atrium])->create(['slug' => 'trace-the-blood']);

    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [
            'steps' => [['selectedStructureId' => $this->atrium->getKey(), 'timeSpentMs' => 3_600_001]],
            'durationMs' => 14_400_001,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['steps.0.timeSpentMs', 'durationMs']);
});
