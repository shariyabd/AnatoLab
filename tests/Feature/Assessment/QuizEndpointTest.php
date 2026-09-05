<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;

/*
| GET /api/v1/quizzes/{quiz}.
|
| `{quiz}` is an organ slug — there is no `quizzes` table
| (App\Services\Assessment\Quiz). These cover what is served and, just as
| importantly, what is not: a question in `review`, a draft organ, and a
| spatial question whose answer has no marker on the model.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('requires a login', function (): void {
    auth()->logout();

    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    Question::factory()->published()->for($organ)->create();

    $this->getJson('/api/v1/quizzes/heart')->assertUnauthorized();
});

it('serves the organ and its published questions', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create();
    $question = Question::factory()->published()->spatial($structure)->create([
        'question' => 'Find the left ventricle.',
    ]);

    $this->getJson('/api/v1/quizzes/heart')
        ->assertOk()
        ->assertJsonPath('data.slug', 'heart')
        ->assertJsonPath('data.title', 'Heart quiz')
        ->assertJsonPath('data.questionCount', 1)
        ->assertJsonPath('data.organ.slug', 'heart')
        ->assertJsonPath('data.questions.0.id', (string) $question->getKey())
        ->assertJsonPath('data.questions.0.type', 'spatial')
        ->assertJsonPath('data.questions.0.question', 'Find the left ventricle.');
});

it('embeds the organ in the shape the viewer already consumes', function (): void {
    // The quiz page hands `organ` straight to useAnatomyViewer, so it has to be
    // the OrganDto that resources/js/anatomy/types.ts describes — not a
    // quiz-shaped variant of it (docs/feature-plan.md §7.8).
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $structure = AnatomicalStructure::factory()->published()->for($organ)->create([
        'anchor_position' => [0.7, -0.75, 0.65],
    ]);
    Question::factory()->published()->spatial($structure)->create();

    $this->getJson('/api/v1/quizzes/heart')
        ->assertOk()
        ->assertJsonPath('data.organ.structures.0.id', (string) $structure->getKey())
        ->assertJsonPath('data.organ.structures.0.anchorPosition', [0.7, -0.75, 0.65])
        ->assertJsonPath('data.organ.modelFormat', 'glb');
});

it('never serves a question awaiting review', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $published = Question::factory()->published()->for($organ)->create();
    Question::factory()->inReview()->for($organ)->create();
    Question::factory()->for($organ)->create();

    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect($response->json('data.questions'))->toHaveCount(1)
        ->and($response->json('data.questions.0.id'))->toBe((string) $published->getKey());
});

it('omits a spatial question whose answer is not published', function (): void {
    // Unpublished structures are absent from the organ payload, so this
    // question would have no marker to click — a question no one can answer.
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $hidden = AnatomicalStructure::factory()->for($organ)->create();
    $visible = AnatomicalStructure::factory()->published()->for($organ)->create();

    Question::factory()->published()->spatial($hidden)->create();
    $answerable = Question::factory()->published()->spatial($visible)->create();

    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect($response->json('data.questions'))->toHaveCount(1)
        ->and($response->json('data.questions.0.id'))->toBe((string) $answerable->getKey());
});

it('serves MCQ options without any flag distinguishing them', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    $question = Question::factory()->published()->for($organ)->create();
    QuestionOption::factory()->for($question)->create(['label' => 'Right atrium', 'value' => 'ra']);
    QuestionOption::factory()->correct()->for($question)->create([
        'label' => 'Left atrium',
        'value' => 'la',
    ]);

    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect($response->json('data.questions.0.options'))->toHaveCount(2)
        ->and($response->json('data.questions.0.options.0'))
        ->toHaveKeys(['id', 'label', 'value'])
        ->and(array_keys((array) $response->json('data.questions.0.options.1')))
        ->toBe(['id', 'label', 'value']);
});

it('serves an authored hint but never the rubric that grades the answer', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    Question::factory()
        ->published()
        ->for($organ)
        ->shortAnswer(['left ventricle'])
        ->withHint('It has the thickest wall.')
        ->create();

    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect($response->json('data.questions.0.hint'))->toBe('It has the thickest wall.')
        ->and($response->json('data.questions.0'))->not->toHaveKey('metadata')
        ->and(json_encode($response->json()))->not->toContain('rubric')
        ->and(json_encode($response->json()))->not->toContain('left ventricle');
});

it('404s for an organ with no published questions', function (): void {
    Organ::factory()->published()->create(['slug' => 'heart']);

    $this->getJson('/api/v1/quizzes/heart')->assertNotFound();
});

it('404s for a draft organ rather than revealing it exists', function (): void {
    $organ = Organ::factory()->create(['slug' => 'heart']);
    Question::factory()->published()->for($organ)->create();

    $this->getJson('/api/v1/quizzes/heart')->assertNotFound();
});

it('404s for an unknown slug', function (): void {
    $this->getJson('/api/v1/quizzes/pancreas')->assertNotFound();
});
