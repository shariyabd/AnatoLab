<?php

declare(strict_types=1);

use App\Jobs\RecalculateMastery;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Services\Assessment\AssessmentService;
use App\Services\Assessment\AttemptData;
use App\Services\Progress\MasteryService;

/*
| Ownership is scoped in the service from the passed-in User and never trusted
| from a request parameter (docs/engineering.md §10, invariant 1). That is not
| ceremony: Handover 09 scores mission steps through this same service, and
| Handover 10 re-reads attempts from a queued job where there is no request at
| all.
*/

beforeEach(function (): void {
    $this->service = app(AssessmentService::class);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $this->answer = AnatomicalStructure::factory()->published()->for($this->organ)->create();
    $this->question = Question::factory()->published()->spatial($this->answer)->create();
});

it('grades and files an attempt with no authenticated user at all', function (): void {
    // No actingAs. If the service reached for auth() this would fail, which is
    // exactly the property that makes it queueable.
    expect(auth()->check())->toBeFalse();

    $student = User::factory()->create();

    $result = $this->service->recordAttempt(
        $student,
        $this->question,
        new AttemptData(
            questionId: (int) $this->question->getKey(),
            selectedStructureId: (int) $this->answer->getKey(),
            timeSpentMs: 2_500,
        ),
    );

    expect($result->isCorrect)->toBeTrue()
        ->and(Attempt::query()->sole()->user_id)->toBe($student->getKey());
});

it('files the attempt against the user it was handed, not the logged-in one', function (): void {
    $loggedIn = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($loggedIn);

    $this->service->recordAttempt(
        $other,
        $this->question,
        new AttemptData(questionId: (int) $this->question->getKey()),
    );

    expect(Attempt::query()->sole()->user_id)->toBe($other->getKey());
});

it('scopes a read to one student', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Attempt::factory()->count(2)->for($mine)->for($this->question)->create();
    Attempt::factory()->for($theirs)->for($this->question)->create();

    expect(Attempt::query()->forUser($mine)->count())->toBe(2)
        ->and(Attempt::query()->forUser($theirs)->count())->toBe(1);
});

it('keeps the mastery job safe to run inline from an attempt', function (): void {
    // Was "a no-op until handover 10 implements it". Handover 10 has: the body
    // now recomputes mastery (docs/handovers/parallel-execution-plan.md D4).
    // What this lane still needs is unchanged — the dispatch signature it calls
    // from AssessmentService, and a job that survives being run inline by the
    // `sync` queue this suite uses, including for a user id that no longer
    // resolves. The mastery arithmetic itself is tested in tests/Unit/Progress
    // and tests/Feature/Progress.
    $job = new RecalculateMastery(userId: 1, attemptId: 1);

    $job->handle(app(MasteryService::class));

    expect($job->userId)->toBe(1)->and($job->attemptId)->toBe(1);
});
