<?php

declare(strict_types=1);

use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
| The dashboard page (PRD §18).
|
| Handover 01 registered `/dashboard` and left its controller empty for this
| lane; the progress screen lives there rather than at a second URL, so there
| is one destination and one navigation entry.
|
| The payload travels through the same ProgressSummaryResource the API uses —
| one shaping path, one set of tests — so what is asserted here is the page
| wiring, not the shape a second time.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();

    $this->system = BodySystem::factory()->create(['slug' => 'cardiovascular', 'name' => 'Cardiovascular']);
    $this->organ = Organ::factory()->published()->for($this->system)->create(['slug' => 'heart', 'name' => 'Heart']);
    $this->structure = AnatomicalStructure::factory()->published()->for($this->organ)->create();
});

it('requires authentication', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the progress summary as a page prop', function (): void {
    LearningMastery::factory()
        ->for($this->student)
        ->forTopic(TopicType::System, (int) $this->system->getKey())
        ->scoring(72.5)
        ->create(['attempts' => 8, 'correct_attempts' => 6]);

    $this->actingAs($this->student)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            // A plain array, not a Resource wrapped in `data` — Inertia would
            // otherwise hand the page {data: {...}} (DashboardController).
            ->where('progress.overallScore', 72.5)
            ->where('progress.systems.0.slug', 'cardiovascular')
            ->has('progress.gamification')
            ->has('progress.recentActivity')
        );
});

it('renders an honest empty state for a student who has done nothing', function (): void {
    $this->actingAs($this->student)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('progress.overallScore', 0)
            ->where('progress.lessonsCompleted', 0)
            ->where('progress.strongest', null)
            ->where('progress.needsPractice', null)
            ->where('progress.recentActivity', [])
            ->where('progress.gamification.xp', 0)
            ->where('progress.gamification.level', 1)
        );
});

it('shows one student their own progress and never another', function (): void {
    $other = User::factory()->create();

    LearningMastery::factory()
        ->for($other)
        ->forTopic(TopicType::System, (int) $this->system->getKey())
        ->scoring(95.0)
        ->create(['attempts' => 50, 'correct_attempts' => 49]);

    $this->actingAs($this->student)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('progress.overallScore', 0)
            ->where('progress.systems.0.score', 0)
        );
});

it('carries no answer key into the page', function (): void {
    $question = Question::factory()->published()->spatial($this->structure)->create();

    $attempt = Attempt::factory()->for($this->student)->for($question)->create([
        'selected_structure_id' => $this->structure->getKey(),
    ]);

    $attempt->is_correct = true;
    $attempt->save();

    $response = $this->actingAs($this->student)->get('/dashboard')->assertOk();

    // The page describes graded answers, so it is the most likely place for
    // one to leak (invariant 4).
    expect($response->getContent())->toCarryNoAnswerKey();
});

it('carries a recommendation with a readable reason', function (): void {
    Question::factory()->published()->spatial($this->structure)->create();

    $this->actingAs($this->student)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('progress.recommendation.organSlug', 'heart')
            ->where('progress.recommendation.activity', 'quiz')
            ->where('progress.recommendation.href', '/quizzes/heart')
            // The whole sentence, pinned: it is a server-side template and
            // never LLM-generated (docs/architecture.md §10), so it is a fixed
            // string that a test can state.
            ->where(
                'progress.recommendation.reason',
                'You have not answered anything on the Heart yet — this is the quickest way to '
                .'find out where you stand.',
            )
        );
});
