<?php

declare(strict_types=1);

use App\Enums\DifficultyPreference;
use App\Models\AnatomicalStructure;
use App\Models\BodySystem;
use App\Models\Lesson;
use App\Models\Organ;
use App\Models\User;

/*
| The lesson API (docs/handovers/06-lessons.md).
|
| The payload is camelCase and unwrapped from a `data` envelope only at the
| collection level, matching the anatomy endpoints these sit alongside.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);
});

it('sends a guest to log in rather than to a model URL', function (): void {
    auth()->logout();

    $this->getJson('/api/v1/lessons')->assertUnauthorized();
});

it('lists published lessons', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create(['title' => 'Published']);
    Lesson::factory()->for($organ)->create(['title' => 'Draft']);

    $this->getJson('/api/v1/lessons')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Published');
});

it('withholds a lesson whose organ is still a draft', function (): void {
    // Otherwise a published lesson becomes a side door to an unpublished
    // organ's model URL, which is the leak Handover 05's 404 closes.
    $draftOrgan = Organ::factory()->create();
    Lesson::factory()->published()->for($draftOrgan)->create();

    $this->getJson('/api/v1/lessons')->assertOk()->assertJsonCount(0, 'data');
});

it('filters by organ', function (): void {
    $heart = Organ::factory()->published()->create(['slug' => 'heart']);
    $lungs = Organ::factory()->published()->create(['slug' => 'lungs']);
    Lesson::factory()->published()->for($heart)->create(['title' => 'Heart lesson']);
    Lesson::factory()->published()->for($lungs)->create(['title' => 'Lung lesson']);

    $this->getJson('/api/v1/lessons?organ=heart')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Heart lesson');
});

it('filters by body system', function (): void {
    $cardiovascular = BodySystem::factory()->create(['slug' => 'cardiovascular']);
    $respiratory = BodySystem::factory()->create(['slug' => 'respiratory']);

    $heart = Organ::factory()->published()->for($cardiovascular, 'bodySystem')->create();
    $lungs = Organ::factory()->published()->for($respiratory, 'bodySystem')->create();

    Lesson::factory()->published()->for($heart)->create(['title' => 'Heart lesson']);
    Lesson::factory()->published()->for($lungs)->create(['title' => 'Lung lesson']);

    $this->getJson('/api/v1/lessons?system=respiratory')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Lung lesson');
});

it('filters by difficulty', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create(['difficulty' => DifficultyPreference::Beginner]);
    Lesson::factory()->published()->for($organ)->create(['difficulty' => DifficultyPreference::Advanced]);

    $this->getJson('/api/v1/lessons?difficulty=advanced')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.difficulty', 'advanced');
});

it('rejects a difficulty that is not one of the three', function (): void {
    // A 422 rather than an empty list: a filter the client got wrong should
    // say so, not look like a library with nothing in it.
    $this->getJson('/api/v1/lessons?difficulty=impossible')
        ->assertStatus(422)
        ->assertJsonValidationErrors('difficulty');
});

it('omits lesson content from the library payload', function (): void {
    // Ten lessons of steps to draw ten cards is the N+1's better-disguised
    // cousin (App\Http\Resources\Lessons\LessonSummaryResource).
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create();

    $this->getJson('/api/v1/lessons')
        ->assertOk()
        ->assertJsonMissingPath('data.0.steps')
        ->assertJsonPath('data.0.stepCount', 3);
});

it('shows one lesson with its steps and its organ', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->count(2)->for($organ)->create();
    Lesson::factory()->published()->for($organ)->create(['slug' => 'blood-circulation']);

    $this->getJson('/api/v1/lessons/blood-circulation')
        ->assertOk()
        ->assertJsonPath('data.slug', 'blood-circulation')
        ->assertJsonCount(3, 'data.steps')
        ->assertJsonPath('data.organ.slug', 'heart')
        ->assertJsonCount(2, 'data.organ.structures');
});

it('numbers every step so the client sends back an index the server authored', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->withSteps(4)->for($organ)->create(['slug' => 'indexed']);

    $this->getJson('/api/v1/lessons/indexed')
        ->assertOk()
        ->assertJsonPath('data.steps.0.index', 0)
        ->assertJsonPath('data.steps.3.index', 3)
        ->assertJsonPath('data.stepCount', 4);
});

it('drops a step whose type it does not recognise', function (): void {
    // `content` is a JSON column an admin tool will eventually write. A step
    // with no component renders nothing, silently, mid-lesson; dropping it here
    // at least keeps the step count honest.
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create([
        'slug' => 'partly-broken',
        'content' => ['steps' => [
            ['type' => 'objective', 'title' => 'Fine', 'payload' => ['body' => 'x', 'outcomes' => []]],
            ['type' => 'hologram', 'title' => 'Not a step type', 'payload' => []],
        ]],
    ]);

    $this->getJson('/api/v1/lessons/partly-broken')
        ->assertOk()
        ->assertJsonCount(1, 'data.steps')
        ->assertJsonPath('data.stepCount', 1);
});

it('404s on a lesson that is not published', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->for($organ)->create(['slug' => 'unfinished']);

    $this->getJson('/api/v1/lessons/unfinished')->assertNotFound();
});

it('404s on a slug that does not exist', function (): void {
    $this->getJson('/api/v1/lessons/no-such-lesson')->assertNotFound();
});

it('carries no answer key', function (): void {
    // A knowledge_check step is a seat for one of F07's questions. It holds a
    // prompt and a reference and must never grow an answer (invariant 4).
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create([
        'slug' => 'with-a-check',
        'content' => ['steps' => [[
            'type' => 'knowledge_check',
            'title' => 'Check yourself',
            'payload' => ['prompt' => 'Which chamber feeds the aorta?', 'reference' => 'demo.aorta'],
        ]]],
    ]);

    expect($this->getJson('/api/v1/lessons/with-a-check')->assertOk()->json())->toCarryNoAnswerKey();
    expect($this->getJson('/api/v1/lessons')->assertOk()->json())->toCarryNoAnswerKey();
});
