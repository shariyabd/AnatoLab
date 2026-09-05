<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;

/*
| The quiz pages' Inertia props (handover 07).
|
| `quiz.organ` is an OrganDto, unwrapped, because the page hands it straight to
| useAnatomyViewer and the frozen contract in resources/js/anatomy/types.ts
| says what that object looks like. A `data` wrapper here is a contract break
| TypeScript cannot see (docs/feature-plan.md §7.8).
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('sends a guest to log in rather than to a quiz', function (): void {
    auth()->logout();

    $this->get('/quizzes')->assertRedirect('/login');
});

it('lists only organs that can currently ask a question', function (): void {
    $withQuestions = Organ::factory()->published()->create(['name' => 'Heart', 'slug' => 'heart']);
    Question::factory()->published()->for($withQuestions)->create();

    Organ::factory()->published()->create(['name' => 'Lungs', 'slug' => 'lungs']);

    $draft = Organ::factory()->create(['name' => 'Brain', 'slug' => 'brain']);
    Question::factory()->published()->for($draft)->create();

    $this->get('/quizzes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Quiz/Index')
            ->has('quizzes', 1)
            ->where('quizzes.0.slug', 'heart')
            ->where('quizzes.0.questionCount', 1)
        );
});

it('counts only the questions it would actually serve', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    Question::factory()->published()->for($organ)->create();
    Question::factory()->inReview()->for($organ)->create();
    Question::factory()->for($organ)->create();

    $this->get('/quizzes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('quizzes.0.questionCount', 1));
});

it('renders an empty state rather than failing when nothing is published', function (): void {
    $this->get('/quizzes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quiz/Index')->has('quizzes', 0));
});

it('renders a round with the organ and its questions unwrapped', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();
    Question::factory()->published()->spatial($structure)->create();

    $this->get('/quizzes/heart')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Quiz/Show')
            ->where('quiz.slug', 'heart')
            ->where('quiz.title', 'Heart quiz')
            ->has('quiz.questions', 1)
            // Unwrapped, and the same shape Explore hands the viewer.
            ->has('quiz.organ.structures', 1)
            ->where('quiz.organ.structures.0.id', (string) $structure->getKey())
        );
});

it('404s a round for an organ with nothing to ask', function (): void {
    Organ::factory()->published()->create(['slug' => 'heart']);

    $this->get('/quizzes/heart')->assertNotFound();
});

it('adds one navigation entry pointing at a route that resolves', function (): void {
    // config/navigation.php is append-only and shared by seven lanes
    // (docs/feature-plan.md §7.4); this asserts this lane's line and nothing else.
    $entries = collect(config('navigation.main'))->firstWhere('key', 'quizzes');

    expect($entries)->not->toBeNull()
        ->and($entries['route'])->toBe('quiz.index')
        ->and(Route::has('quiz.index'))->toBeTrue();
});
