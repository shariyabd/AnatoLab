<?php

declare(strict_types=1);

use App\Enums\LearningEventType;
use App\Enums\QuestionStatus;
use App\Enums\TopicType;
use App\Models\LearningEvent;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Services\Progress\AnalyticsService;
use Carbon\CarbonImmutable;

/*
| The admin analytics view (PRD §19, §29).
|
| Read-only and aggregate. Handover 13 owns no tables, so everything here comes
| from rows F10 wrote.
*/

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->analytics = app(AnalyticsService::class);
    $this->until = CarbonImmutable::parse('2026-09-30 12:00:00');
    $this->since = $this->until->subDays(29)->startOfDay();
});

it('counts events by type within the window only', function (): void {
    $student = User::factory()->create();

    LearningEvent::factory()->for($student)->count(3)->create([
        'event_type' => LearningEventType::OrganViewed,
        'occurred_at' => $this->until->subDays(2),
    ]);

    LearningEvent::factory()->for($student)->create([
        'event_type' => LearningEventType::LessonCompleted,
        'occurred_at' => $this->until->subDays(1),
    ]);

    // Outside the window.
    LearningEvent::factory()->for($student)->create([
        'event_type' => LearningEventType::OrganViewed,
        'occurred_at' => $this->since->subDays(5),
    ]);

    $overview = $this->analytics->overview($this->since, $this->until);

    expect($overview->eventCounts)->toBe(['organ_viewed' => 3, 'lesson_completed' => 1])
        ->and($overview->totalEvents)->toBe(4);
});

it('omits event types that did not happen', function (): void {
    LearningEvent::factory()->for(User::factory())->create([
        'event_type' => LearningEventType::OrganViewed,
        'occurred_at' => $this->until->subDay(),
    ]);

    // A table of thirteen rows of which twelve are zero buries the one number
    // worth reading.
    expect(array_keys($this->analytics->overview($this->since, $this->until)->eventCounts))
        ->toBe(['organ_viewed']);
});

it('counts each learner once however many events they generated', function (): void {
    $busy = User::factory()->create();
    $quiet = User::factory()->create();

    LearningEvent::factory()->for($busy)->count(10)->create(['occurred_at' => $this->until->subDay()]);
    LearningEvent::factory()->for($quiet)->create(['occurred_at' => $this->until->subDay()]);

    expect($this->analytics->overview($this->since, $this->until)->activeLearners)->toBe(2);
});

it('zero-fills every day in the window', function (): void {
    LearningEvent::factory()->for(User::factory())->create(['occurred_at' => $this->until->subDays(3)]);

    $activity = $this->analytics->overview($this->since, $this->until)->dailyActivity;

    // A sparkline with missing days lies about the shape of the trend.
    expect($activity)->toHaveCount(30)
        ->and(collect($activity)->sum('events'))->toBe(1);
});

it('averages mastery per organ and reports the learner count with it', function (): void {
    $organ = Organ::factory()->create(['name' => 'Heart']);

    LearningMastery::factory()->for(User::factory())->create([
        'topic_type' => TopicType::Organ,
        'topic_id' => $organ->getKey(),
        'mastery_score' => 0.8,
    ]);

    LearningMastery::factory()->for(User::factory())->create([
        'topic_type' => TopicType::Organ,
        'topic_id' => $organ->getKey(),
        'mastery_score' => 0.6,
    ]);

    $mastery = $this->analytics->overview($this->since, $this->until)->masteryByOrgan;

    // 0.9 across two students and 0.9 across two hundred are different facts.
    expect($mastery)->toBe([['organ' => 'Heart', 'mastery' => 0.7, 'learners' => 2]]);
});

it('skips a mastery row whose organ no longer exists', function (): void {
    // `topic_id` carries no foreign key by design; the log outlives its subject.
    LearningMastery::factory()->for(User::factory())->create([
        'topic_type' => TopicType::Organ,
        'topic_id' => 999_999,
        'mastery_score' => 0.5,
    ]);

    expect($this->analytics->overview($this->since, $this->until)->masteryByOrgan)->toBe([]);
});

it('surfaces the review backlog alongside the activity', function (): void {
    $organ = Organ::factory()->create();

    Question::factory()->for($organ)->count(2)->create([
        'status' => QuestionStatus::Review,
        'generated_by_ai' => true,
    ]);

    expect($this->analytics->overview($this->since, $this->until)->questionsAwaitingReview)->toBe(2);
});

it('renders the analytics page for an admin', function (): void {
    $props = $this->actingAs($this->admin)
        ->get('/admin/analytics')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['days'])->toBe(30)
        ->and($props['overview'])->toHaveKeys([
            'since', 'until', 'activeLearners', 'totalEvents',
            'eventCounts', 'dailyActivity', 'masteryByOrgan', 'questionsAwaitingReview',
        ]);
});

it('caps the reporting window rather than accepting any number of days', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/analytics?days=5000')
        ->assertSessionHasErrors('days');
});

it('identifies no individual student', function (): void {
    // Per-student breakdowns are teacher functionality, deferred to Phase 2
    // (PRD §19).
    $student = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

    LearningEvent::factory()->for($student)->create(['occurred_at' => $this->until->subDay()]);

    $props = $this->actingAs($this->admin)->get('/admin/analytics')->viewData('page')['props'];
    $encoded = json_encode($props, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('Ada Lovelace')
        ->and($encoded)->not->toContain('ada@example.test');
});
