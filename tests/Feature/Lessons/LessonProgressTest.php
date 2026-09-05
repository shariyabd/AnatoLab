<?php

declare(strict_types=1);

use App\Enums\LessonProgressStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\User;
use App\Services\Lessons\LessonService;
use Illuminate\Support\Carbon;

/*
| Progress writes (docs/handovers/06-lessons.md, Tests).
|
| The rule under test throughout: a progress row is keyed on the authenticated
| student and on nothing the client can send. There is no request field naming
| a user, which is why "a student cannot complete another student's lesson" is
| a property of the service signature rather than of a policy.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->organ = Organ::factory()->published()->create();
    $this->lesson = Lesson::factory()->published()->withSteps(4)->for($this->organ)->create([
        'slug' => 'blood-circulation',
    ]);

    $this->actingAs($this->student);
});

it('requires a login to record progress', function (): void {
    auth()->logout();

    $this->postJson('/api/v1/lessons/blood-circulation/complete')->assertUnauthorized();
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 1])->assertUnauthorized();
});

it('records the step a student has reached as a percentage of the lesson', function (): void {
    // Four steps, so reaching the second one is halfway.
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 1])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.progressPercent', 50)
        ->assertJsonPath('data.completedAt', null);

    $this->assertDatabaseHas('lesson_progress', [
        'user_id' => $this->student->getKey(),
        'lesson_id' => $this->lesson->getKey(),
        'progress_percent' => 50,
    ]);
});

it('derives the percentage server-side and ignores one sent by the client', function (): void {
    // The client sends the step it reached, never the percentage. This is the
    // same rule as "never grade client-side" (docs/architecture.md §5.4 rule 2).
    $this->postJson('/api/v1/lessons/blood-circulation/progress', [
        'stepIndex' => 0,
        'progressPercent' => 100,
        'status' => 'completed',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.progressPercent', 25)
        ->assertJsonPath('data.status', 'in_progress');
});

it('clamps a step index past the end of the lesson', function (): void {
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 999])
        ->assertSuccessful()
        ->assertJsonPath('data.progressPercent', 100)
        // 100% of the steps seen is still not the same as finishing: only
        // /complete sets that, and only it writes completed_at.
        ->assertJsonPath('data.status', 'in_progress');
});

it('rejects a missing or negative step index', function (): void {
    $this->postJson('/api/v1/lessons/blood-circulation/progress', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stepIndex');

    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stepIndex');
});

it('never moves progress backwards', function (): void {
    // Re-reading step one of a lesson you are most of the way through must not
    // undo the rest of it.
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 3])->assertSuccessful();

    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 0])
        ->assertOk()
        ->assertJsonPath('data.progressPercent', 100);
});

it('completes a lesson', function (): void {
    $this->postJson('/api/v1/lessons/blood-circulation/complete')
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.progressPercent', 100)
        ->assertJsonPath('data.completedAt', fn (?string $at): bool => $at !== null);

    $this->assertDatabaseHas('lesson_progress', [
        'user_id' => $this->student->getKey(),
        'lesson_id' => $this->lesson->getKey(),
        'status' => LessonProgressStatus::Completed->value,
        'progress_percent' => 100,
    ]);
});

it('completes idempotently, keeping the original timestamp', function (): void {
    Carbon::setTestNow('2026-09-06 09:00:00');
    $first = $this->postJson('/api/v1/lessons/blood-circulation/complete')->assertSuccessful()->json('data.completedAt');

    Carbon::setTestNow('2026-09-06 17:30:00');
    $second = $this->postJson('/api/v1/lessons/blood-circulation/complete')->assertSuccessful()->json('data.completedAt');

    // Not tidiness: F10 ages mastery off completed_at, so letting a re-submit
    // refresh it would let a student inflate their own recency by clicking twice.
    expect($second)->toBe($first);
    expect(LessonProgress::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('does not reopen a completed lesson when the student re-reads a step', function (): void {
    $this->postJson('/api/v1/lessons/blood-circulation/complete')->assertSuccessful();

    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 0])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.progressPercent', 100);
});

it('scopes progress to the authenticated student and to nobody else', function (): void {
    $otherStudent = User::factory()->create();

    $this->postJson('/api/v1/lessons/blood-circulation/complete')->assertSuccessful();

    // One row, and it belongs to the student who made the request.
    expect(LessonProgress::query()->count())->toBe(1);
    $this->assertDatabaseMissing('lesson_progress', ['user_id' => $otherStudent->getKey()]);
});

it('cannot be told whose progress to write', function (): void {
    // There is no request field naming a user, so a client that invents one is
    // ignored rather than obeyed (App\Http\Requests\Lessons\LessonRequest).
    $victim = User::factory()->create();

    $this->postJson('/api/v1/lessons/blood-circulation/complete', [
        'user_id' => $victim->getKey(),
        'userId' => $victim->getKey(),
    ])->assertSuccessful();

    $this->assertDatabaseMissing('lesson_progress', ['user_id' => $victim->getKey()]);
    $this->assertDatabaseHas('lesson_progress', ['user_id' => $this->student->getKey()]);
});

it('never returns another student\'s progress with the lesson', function (): void {
    $otherStudent = User::factory()->create();
    LessonProgress::factory()->completed()->for($otherStudent)->for($this->lesson)->create();

    $this->getJson('/api/v1/lessons/blood-circulation')
        ->assertOk()
        ->assertJsonPath('data.progress', null);

    $this->getJson('/api/v1/lessons')
        ->assertOk()
        ->assertJsonPath('data.0.progress', null);
});

it('returns this student\'s own progress with the lesson', function (): void {
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 1])->assertSuccessful();

    $this->getJson('/api/v1/lessons/blood-circulation')
        ->assertOk()
        ->assertJsonPath('data.progress.progressPercent', 50);
});

it('404s rather than recording progress against an unpublished lesson', function (): void {
    Lesson::factory()->for($this->organ)->create(['slug' => 'unfinished']);

    $this->postJson('/api/v1/lessons/unfinished/complete')->assertNotFound();

    expect(LessonProgress::query()->count())->toBe(0);
});

it('reports zero rather than dividing by zero for a lesson with no steps', function (): void {
    $empty = Lesson::factory()->published()->for($this->organ)->create([
        'slug' => 'empty',
        'content' => ['steps' => []],
    ]);

    expect(app(LessonService::class)->countSteps($empty))->toBe(0);

    $this->postJson('/api/v1/lessons/empty/progress', ['stepIndex' => 0])
        ->assertSuccessful()
        ->assertJsonPath('data.progressPercent', 0);
});

it('answers 201 the first time a row is created and 200 when it is updated', function (): void {
    // Laravel derives this from `wasRecentlyCreated`, and it is the correct
    // REST answer (docs/engineering.md §5). Written down because a client that
    // tests for `=== 200` rather than `response.ok` would break on the first
    // step of every lesson.
    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 0])
        ->assertStatus(201);

    $this->postJson('/api/v1/lessons/blood-circulation/progress', ['stepIndex' => 1])
        ->assertStatus(200);
});
