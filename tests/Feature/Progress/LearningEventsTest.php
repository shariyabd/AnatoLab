<?php

declare(strict_types=1);

use App\Enums\LearningEventType;
use App\Jobs\RecordLearningEvents;
use App\Models\Attempt;
use App\Models\LearningEvent;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/*
| POST /api/v1/events, and the server-side emitters (PRD §29, arch §13).
|
| The rule this endpoint exists to enforce is which events a browser may
| assert. Four viewer interactions, and nothing that feeds a score: if a client
| could post `question_answered`, a student could manufacture the evidence
| their own XP and badges are computed from.
*/

function batch(array $events): array
{
    return ['events' => $events];
}

function viewerEvent(string $type = 'organ_viewed', array $overrides = []): array
{
    return [
        'type' => $type,
        'occurredAt' => now()->toIso8601String(),
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->student = User::factory()->create();

    RateLimiter::clear('learning-events');
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/events', batch([viewerEvent()]))->assertUnauthorized();
});

it('accepts a batch and queues the write', function (): void {
    Queue::fake();

    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([
            viewerEvent('organ_viewed', ['contextType' => 'organ', 'contextId' => 3]),
            viewerEvent('layer_changed', ['payload' => ['layer' => 'wireframe']]),
        ]))
        // 202, not 200: the batch is queued, not written. The browser flushes
        // this on page unload and cannot wait for a database round trip.
        ->assertAccepted()
        ->assertJson(['accepted' => 2]);

    Queue::assertPushed(RecordLearningEvents::class);
});

it('writes the batch to the append-only log', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([
            viewerEvent('structure_selected', ['contextType' => 'structure', 'contextId' => 7]),
        ]))
        ->assertAccepted();

    $event = LearningEvent::query()->forUser($this->student)->sole();

    expect($event->event_type)->toBe(LearningEventType::StructureSelected)
        ->and($event->context_type)->toBe('structure')
        ->and($event->context_id)->toBe(7);
});

it('files events against the authenticated student and nobody else', function (): void {
    $other = User::factory()->create();

    // There is no user field in the payload to override, and none is accepted.
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([
            viewerEvent('organ_viewed', ['contextType' => 'organ', 'contextId' => 1]),
        ]))
        ->assertAccepted();

    expect(LearningEvent::query()->forUser($other)->count())->toBe(0)
        ->and(LearningEvent::query()->forUser($this->student)->count())->toBe(1);
});

it('rejects an event the server records for itself', function (string $type): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([viewerEvent($type)]))
        ->assertJsonValidationErrors('events.0.type');

    expect(LearningEvent::query()->count())->toBe(0);
})->with([
    'question_answered',
    'lesson_completed',
    'mission_completed',
    'hint_requested',
    'ai_question_asked',
]);

it('rejects a malformed batch', function (array $payload): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', $payload)
        ->assertStatus(422);

    expect(LearningEvent::query()->count())->toBe(0);
})->with([
    'no events key' => [[]],
    'empty batch' => [['events' => []]],
    'events is not an array' => [['events' => 'organ_viewed']],
    'unknown type' => [fn (): array => batch([viewerEvent('rm_-rf')])],
    'missing timestamp' => [fn (): array => batch([['type' => 'organ_viewed']])],
    'unknown context type' => [
        fn (): array => batch([viewerEvent('organ_viewed', ['contextType' => 'users'])]),
    ],
    'non-integer context id' => [
        fn (): array => batch([
            viewerEvent('organ_viewed', ['contextType' => 'organ', 'contextId' => 'one']),
        ]),
    ],
    'nested payload' => [
        fn (): array => batch([viewerEvent('layer_changed', ['payload' => ['a' => ['b' => 'c']]])]),
    ],
]);

it('rejects a batch larger than the server accepts', function (): void {
    $events = array_fill(0, 51, viewerEvent());

    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch($events))
        ->assertJsonValidationErrors('events');
});

it('rejects a replayed batch of stale events', function (): void {
    // Batches flush on an interval and on page unload, so a legitimate event is
    // minutes old. A week-old one is a replay, and replays corrupt a streak.
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([
            viewerEvent('organ_viewed', ['occurredAt' => now()->subWeek()->toIso8601String()]),
        ]))
        ->assertJsonValidationErrors('events.0.occurredAt');
});

it('rejects an event dated in the future', function (): void {
    $this->actingAs($this->student)
        ->postJson('/api/v1/events', batch([
            viewerEvent('organ_viewed', ['occurredAt' => now()->addHour()->toIso8601String()]),
        ]))
        ->assertJsonValidationErrors('events.0.occurredAt');
});

it('is rate limited', function (): void {
    $this->actingAs($this->student);

    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/events', batch([viewerEvent()]))->assertAccepted();
    }

    // The 31st in a minute. The endpoint is cheap per request but unbounded
    // per student without this (App\Providers\ProgressServiceProvider).
    $this->postJson('/api/v1/events', batch([viewerEvent()]))->assertStatus(429);
});

/*
| Server-side emitters. Both are observers registered in ProgressServiceProvider
| so that neither Handover 07's service nor Handover 06's is edited by this lane
| (docs/handovers/parallel-execution-plan.md, batch D).
*/

it('records an answered question where it happens', function (): void {
    $question = Question::factory()->published()->create();

    $attempt = Attempt::factory()->for($this->student)->for($question)->create(['hint_used' => true]);

    $types = LearningEvent::query()
        ->forUser($this->student)
        ->pluck('event_type')
        ->map(static fn (LearningEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain('question_answered')
        // A separate row rather than a flag: PRD §30 counts hint usage as its
        // own metric, and counting rows beats filtering a JSON payload.
        ->toContain('hint_requested');

    expect((int) $attempt->question_id)->toBe((int) $question->getKey());
});

it('does not name the outcome with the answer key field names', function (): void {
    $question = Question::factory()->published()->create();

    Attempt::factory()->for($this->student)->for($question)->create();

    $event = LearningEvent::query()
        ->forUser($this->student)
        ->ofType(LearningEventType::QuestionAnswered)
        ->sole();

    // `outcome: incorrect` rather than a boolean named after the column, so the
    // strings /boundary-audit greps for stay out of anything that could be
    // serialised toward the client (invariant 4).
    expect($event->payload)->toHaveKey('outcome')
        ->and($event->payload['outcome'])->toBe('incorrect')
        ->and($event->payload)->not->toHaveKey('is_correct')
        ->and($event->payload)->not->toHaveKey('isCorrect');
});

it('records a lesson being started and completed', function (): void {
    $lesson = Lesson::factory()->published()->create();

    $progress = LessonProgress::query()->create([
        'user_id' => $this->student->getKey(),
        'lesson_id' => $lesson->getKey(),
        'status' => 'in_progress',
        'progress_percent' => 20,
    ]);

    $progress->update(['progress_percent' => 60]);

    // Saved twice more, but `lesson_started` is written once — on creation —
    // and `lesson_completed` only when `completed_at` actually changes.
    $progress->update(['status' => 'completed', 'progress_percent' => 100, 'completed_at' => now()]);
    $progress->update(['progress_percent' => 100]);

    $types = LearningEvent::query()->forUser($this->student)->pluck('event_type')
        ->map(static fn (LearningEventType $type): string => $type->value)
        ->all();

    expect(array_count_values($types))
        ->toBe(['lesson_started' => 1, 'lesson_completed' => 1]);
});

it('keeps the log append-only', function (): void {
    $event = LearningEvent::factory()->for($this->student)->create();

    // No `updated_at` column exists, and the model has timestamps off. A row
    // here is a statement about a moment, not a record to correct.
    expect($event->timestamps)->toBeFalse()
        ->and(Schema::hasColumn('learning_events', 'updated_at'))->toBeFalse();
});
