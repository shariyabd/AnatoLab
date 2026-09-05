<?php

declare(strict_types=1);

use App\Jobs\RecalculateMastery;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\Organ;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
| Acceptance criterion 3: mission steps feed mastery through Handover 07's
| `attempts` table.
|
| This is the constraint the whole lane was built around
| (docs/handovers/parallel-execution-plan.md D5). A mission that scored itself
| into its own table would give Handover 10 two inputs to reconcile instead of
| one, and would have needed a schema change to Handover 07's tables to do it.
| Neither happened, and these tests are what keeps it that way.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->atrium = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
    ]);
    $this->ventricle = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
    ]);
    $this->aorta = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'aorta',
    ]);

    Mission::factory()
        ->published()
        ->tracing([$this->atrium, $this->ventricle, $this->aorta])
        ->create(['slug' => 'trace-the-blood']);
});

it('writes one attempts row per step, each carrying the mission attempt id', function (): void {
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => $this->atrium->getKey(), 'timeSpentMs' => 2_100],
            ['selectedStructureId' => $this->aorta->getKey(), 'hintUsed' => true, 'timeSpentMs' => 8_400],
            ['selectedStructureId' => $this->aorta->getKey(), 'timeSpentMs' => 3_300],
        ],
    ])->assertOk();

    $run = MissionAttempt::query()->sole();
    $attempts = Attempt::query()->orderBy('id')->get();

    expect($attempts)->toHaveCount(3);

    foreach ($attempts as $attempt) {
        expect($attempt->mission_attempt_id)->toBe($run->getKey())
            // A mission step has no question row; that is what the nullable
            // column is for (the `attempts` migration says so from its side).
            ->and($attempt->question_id)->toBeNull()
            ->and($attempt->user_id)->toBe($this->student->getKey());
    }

    expect($attempts[0]->is_correct)->toBeTrue()
        ->and($attempts[0]->selected_structure_id)->toBe($this->atrium->getKey())
        ->and($attempts[0]->time_spent_ms)->toBe(2_100)
        ->and($attempts[1]->is_correct)->toBeFalse()
        ->and($attempts[1]->hint_used)->toBeTrue()
        ->and($attempts[2]->is_correct)->toBeTrue();
});

it('queues one mastery recalculation per graded step', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => $this->atrium->getKey()],
            ['selectedStructureId' => $this->ventricle->getKey()],
            ['selectedStructureId' => $this->aorta->getKey()],
        ],
    ])->assertOk();

    // Per step rather than per run: the job takes the id of the attempt that
    // made mastery stale, and a run's steps touch several structures
    // (AssessmentService::recordMissionStep).
    Queue::assertPushed(RecalculateMastery::class, 3);
});

it('records the run and its steps together, or not at all', function (): void {
    // One transaction. A `mission_attempts` row with no `attempts` behind it
    // would count towards mastery as zero work done and could not be explained
    // afterwards (MissionService::score).
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [['selectedStructureId' => $this->atrium->getKey()]],
    ])->assertOk();

    $run = MissionAttempt::query()->sole();

    expect($run->stepAttempts()->count())->toBe(3)
        ->and(Attempt::query()->whereNull('mission_attempt_id')->count())->toBe(0);
});

it('keeps the full target sequence in the server-side result record', function (): void {
    // Richer than the response on purpose: Handover 13 needs to see why
    // students keep failing a mission, and Handover 10 needs to explain a
    // score, without re-deriving either from `attempts`. It is never
    // serialised to a student.
    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [
            ['selectedStructureId' => $this->atrium->getKey()],
            ['selectedStructureId' => $this->ventricle->getKey()],
            ['selectedStructureId' => $this->aorta->getKey()],
        ],
    ])->assertOk();

    $result = MissionAttempt::query()->sole()->result;

    expect($result['type'])->toBe('trace_pathway')
        ->and(array_column($result['steps'], 'target'))
        ->toBe(['left-atrium', 'left-ventricle', 'aorta']);
});

it('does not disturb quiz attempts, which carry no mission attempt id', function (): void {
    // The two sources share a table and stay distinguishable by which nullable
    // column is populated — the arrangement Handover 07 shipped the column for.
    $existing = Attempt::factory()->for($this->student)->create();

    $this->postJson('/api/v1/missions/trace-the-blood/attempt', [
        'steps' => [['selectedStructureId' => $this->atrium->getKey()]],
    ])->assertOk();

    expect($existing->refresh()->mission_attempt_id)->toBeNull()
        ->and(Attempt::query()->whereNotNull('mission_attempt_id')->count())->toBe(3);
});
