<?php

declare(strict_types=1);

use App\Enums\QuestionType;
use App\Http\Resources\Admin\AdminMissionResource;
use App\Http\Resources\Admin\AdminQuestionResource;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Http\Request;

/*
| Handover 13 is the only place an answer key or a mission target sequence is
| legitimately exposed, and this is where that exception is pinned down.
|
| docs/handovers/parallel-execution-plan.md C7: per-lane green does not prove
| the merged answer-key boundary holds. F07 strips correctness, F09 strips the
| target sequence, and this lane deliberately does not — so the test that
| matters is not "is the key absent" but "**is it absent for everyone the policy
| does not name**".
|
| The Resources are exercised directly, with a request carrying each kind of
| user, so the assertion is about the Resource's own policy check rather than
| about the route that happened to reach it. A Resource that emitted the key
| whenever it was rendered would pass an HTTP-only test and fail this one.
*/

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->student = User::factory()->create();
    $this->organ = Organ::factory()->published()->create();
});

function requestAs(?User $user): Request
{
    $request = Request::create('/admin/questions', 'GET');
    $request->setUserResolver(static fn (): ?User => $user);

    return $request;
}

it('gives an admin the answer key', function (): void {
    $question = Question::factory()->for($this->organ)->create(['type' => QuestionType::Mcq]);
    QuestionOption::factory()->for($question)->create(['is_correct' => true]);

    $payload = (new AdminQuestionResource($question->load('options')))
        ->toArray(requestAs($this->admin));

    expect($payload)->toHaveKey('options')
        ->and($payload)->toHaveKey('correctStructureId');
});

it('omits the answer key for a student, rather than nulling it', function (): void {
    $question = Question::factory()->for($this->organ)->create(['type' => QuestionType::Mcq]);
    QuestionOption::factory()->for($question)->create(['is_correct' => true]);

    $payload = (new AdminQuestionResource($question->load('options')))
        ->toArray(requestAs($this->student));

    // Absent, not null: an explicitly null `correctOptionId` still tells a
    // reader the field exists and invites a client to depend on it.
    expect($payload)->not->toHaveKey('options')
        ->and($payload)->not->toHaveKey('correctStructureId')
        ->and($payload)->not->toHaveKey('correctStructureName')
        ->and($payload)->toCarryNoAnswerKey();
});

it('omits the answer key for an unauthenticated request', function (): void {
    $question = Question::factory()->for($this->organ)->create();

    expect((new AdminQuestionResource($question))->toArray(requestAs(null)))
        ->toCarryNoAnswerKey();
});

it('gives an admin the mission target sequence', function (): void {
    $mission = Mission::factory()->for($this->organ)->create();

    expect((new AdminMissionResource($mission))->toArray(requestAs($this->admin)))
        ->toHaveKey('configuration');
});

it('omits the mission target sequence for a student', function (): void {
    $mission = Mission::factory()->for($this->organ)->create();

    $payload = (new AdminMissionResource($mission))->toArray(requestAs($this->student));

    expect($payload)->not->toHaveKey('configuration')
        // The step count is safe and useful; the steps themselves are not.
        ->and($payload)->toHaveKey('stepCount');
});

it('keeps the student-facing quiz free of the key even after an admin views it', function (): void {
    // The merged-boundary check C7 asks for: F07's payload must not acquire
    // correctness data because F13 now renders it somewhere else.
    $question = Question::factory()->for($this->organ)->published()->create([
        'type' => QuestionType::Mcq,
    ]);
    QuestionOption::factory()->for($question)->create(['is_correct' => true]);
    QuestionOption::factory()->for($question)->create(['is_correct' => false]);

    $this->actingAs($this->admin)->get('/admin/questions')->assertOk();

    $props = $this->actingAs($this->student)
        ->get("/quizzes/{$this->organ->slug}")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props)->toCarryNoAnswerKey();
});

it('renders no answer key into the admin page props a student could reach', function (): void {
    // Belt and braces: the review page is admin-only, but the assertion is that
    // the *payload* is safe when the viewer is not an admin, so a future route
    // change cannot leak it.
    $question = Question::factory()->for($this->organ)->create();

    expect((new AdminQuestionResource($question))->toArray(requestAs($this->student)))
        ->toCarryNoAnswerKey();
});
