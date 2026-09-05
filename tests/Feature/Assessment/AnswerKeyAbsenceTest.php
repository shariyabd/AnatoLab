<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;

/*
|-------------------------------------------------------------------------------
| THE NON-NEGOTIABLE ONE
|-------------------------------------------------------------------------------
|
| Invariant 4: the client never receives an answer key. It is the single rule a
| student can exploit from the browser's network tab, so every payload this
| lane emits that carries a question is asserted here — one test per endpoint,
| plus the Inertia page props, which are a payload too and are the one people
| forget (docs/engineering.md §9, docs/handovers/07-assessment-engine.md).
|
| `toCarryNoAnswerKey` lives in tests/Pest.php so every lane asserts it the
| same way and no one has to remember the field list.
|
| The attempt *response* is deliberately excluded: docs/architecture.md §9
| specifies that it returns the verdict and the correct structure, because the
| viewer flashes the right marker green on a miss. It is the reply to an answer
| already graded and written, not a question payload — and the last test below
| pins the difference by proving the reveal is not reachable without recording
| an attempt first.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $this->answer = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left Ventricle',
    ]);

    $this->spatial = Question::factory()->published()->spatial($this->answer)->create([
        'explanation' => 'The left ventricle drives the systemic circulation.',
    ]);

    $this->mcq = Question::factory()->published()->for($this->organ)->create([
        'explanation' => 'Blood returns from the lungs into the left atrium.',
    ]);

    QuestionOption::factory()->for($this->mcq)->create(['label' => 'Right atrium', 'value' => 'ra']);
    QuestionOption::factory()->correct()->for($this->mcq)->create([
        'label' => 'Left atrium',
        'value' => 'la',
    ]);

    $this->shortAnswer = Question::factory()
        ->published()
        ->for($this->organ)
        ->shortAnswer(['left ventricle'])
        ->create();
});

it('GET /api/v1/quizzes/{quiz} carries no answer key', function (): void {
    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect($response->json())->toCarryNoAnswerKey();
});

it('GET /quizzes/{quiz} page props carry no answer key', function (): void {
    // The page ships the whole quiz as an Inertia prop rather than fetching it,
    // so the props are a payload with exactly the same obligation as the JSON
    // endpoint — and they are embedded in the HTML, where "view source" is the
    // network tab.
    $response = $this->get('/quizzes/heart')->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props)->toCarryNoAnswerKey();
});

it('GET /quizzes page props carry no answer key', function (): void {
    $response = $this->get('/quizzes')->assertOk();

    expect($response->viewData('page')['props'])->toCarryNoAnswerKey();
});

it('omits the explanation until an answer has been recorded', function (): void {
    // The explanation names the right structure in prose. It is an answer key
    // that happens to be readable.
    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect(json_encode($response->json()))
        ->not->toContain('drives the systemic circulation')
        ->not->toContain('returns from the lungs');
});

it('omits the correct structure id from every question in the payload', function (): void {
    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    /** @var array<int, array<string, mixed>> $questions */
    $questions = $response->json('data.questions');

    foreach ($questions as $question) {
        expect(array_keys($question))
            ->toBe(['id', 'type', 'question', 'difficulty', 'hint', 'options']);
    }
});

it('omits the rubric that grades a short answer', function (): void {
    $response = $this->getJson('/api/v1/quizzes/heart')->assertOk();

    expect(json_encode($response->json()))
        ->not->toContain('rubric')
        ->not->toContain('metadata');
});

it('cannot be made to reveal the answer without recording an attempt', function (): void {
    // The reveal is only safe because getting it costs a recorded wrong
    // attempt. If a probe were ever free, the response would become an answer
    // key with extra steps.
    $before = App\Models\Attempt::query()->count();

    $this->postJson('/api/v1/quizzes/heart/attempt', [
        'questionId' => $this->spatial->getKey(),
        'selectedStructureId' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.isCorrect', false)
        ->assertJsonPath('data.correctStructureId', (string) $this->answer->getKey());

    expect(App\Models\Attempt::query()->count())->toBe($before + 1);
});
