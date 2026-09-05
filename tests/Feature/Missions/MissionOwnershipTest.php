<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\Organ;
use App\Models\User;
use App\Services\Assessment\MissionService;

/*
| Mission runs are scoped to the authenticated student
| (docs/handovers/09-missions.md, Tests).
|
| Ownership is assigned from the passed-in User and never from a request field,
| which is why `user_id` is absent from `$fillable` on both MissionAttempt and
| Attempt (docs/engineering.md §10).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->other = User::factory()->create();

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->atrium = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
    ]);

    $this->mission = Mission::factory()
        ->published()
        ->tracing([$this->atrium])
        ->create(['slug' => 'trace-the-blood']);
});

it('files the run against the authenticated student', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [
            'steps' => [['selectedStructureId' => $this->atrium->getKey()]],
        ])
        ->assertOk();

    expect(MissionAttempt::query()->sole()->user_id)->toBe($this->student->getKey())
        ->and(Attempt::query()->sole()->user_id)->toBe($this->student->getKey());
});

it('ignores a user id smuggled into the payload', function (): void {
    // `user_id` is not fillable on either model, so a crafted body cannot file
    // a run under someone else's account. The field is not even validated —
    // there is nothing for it to mean.
    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [
            'user_id' => $this->other->getKey(),
            'userId' => $this->other->getKey(),
            'steps' => [['selectedStructureId' => $this->atrium->getKey()]],
        ])
        ->assertOk();

    expect(MissionAttempt::query()->sole()->user_id)->toBe($this->student->getKey());
});

it('ignores a score or completion flag smuggled into the payload', function (): void {
    // Neither is fillable either: they are the verdict, and the only code
    // allowed to set them is the scorer (App\Models\MissionAttempt).
    $this->actingAs($this->student)
        ->postJson('/api/v1/missions/trace-the-blood/attempt', [
            'score' => 9_999,
            'completed' => true,
            'steps' => [['selectedStructureId' => null]],
        ])
        ->assertOk()
        ->assertJsonPath('data.score', 0)
        ->assertJsonPath('data.completed', false);

    expect(MissionAttempt::query()->sole()->score)->toBe(0);
});

it('shows a student only their own history', function (): void {
    MissionAttempt::factory()->for($this->other)->for($this->mission)->create();
    MissionAttempt::factory()->for($this->student)->for($this->mission)->count(2)->create();

    $history = app(MissionService::class)->historyFor($this->student, $this->mission);

    expect($history)->toHaveCount(2)
        ->and($history->pluck('user_id')->unique()->all())->toBe([$this->student->getKey()]);
});
