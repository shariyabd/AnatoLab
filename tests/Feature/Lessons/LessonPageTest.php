<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| The lesson Inertia pages (handover 06).
|
| The `lesson.organ` prop is an OrganDto, unwrapped, because Lessons/Show.vue
| hands it straight to ViewerStage — handover 05's mounting pattern. A `data`
| wrapper here is a contract break that TypeScript cannot see
| (docs/feature-plan.md §7.8).
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);
});

it('sends a guest to log in', function (): void {
    auth()->logout();

    $this->get('/lessons')->assertRedirect('/login');
});

it('renders the library', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->count(2)->for($organ)->create();
    Lesson::factory()->for($organ)->create();

    $this->get('/lessons')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Lessons/Index')
            ->has('lessons', 2)
            ->has('filterOptions.organs', 1)
            ->has('filterOptions.systems', 1)
            ->has('filterOptions.difficulties', 3)
            ->where('filters.organ', null)
        );
});

it('offers no filter for an organ with nothing to study', function (): void {
    // An empty result from a filter the page itself suggested reads as a bug.
    $withLessons = Organ::factory()->published()->create(['slug' => 'heart']);
    Organ::factory()->published()->create(['slug' => 'lungs']);
    Lesson::factory()->published()->for($withLessons)->create();

    $this->get('/lessons')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('filterOptions.organs', 1)
            ->where('filterOptions.organs.0.slug', 'heart')
        );
});

it('carries the active filters back to the page', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    Lesson::factory()->published()->for($organ)->create();

    $this->get('/lessons?organ=heart&difficulty=beginner')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.organ', 'heart')
            ->where('filters.difficulty', 'beginner')
            ->has('lessons', 1)
        );
});

it('renders the library when nothing has been published yet', function (): void {
    // The navigation links here unconditionally. A 404 on an empty database is
    // a broken nav entry, not an honest empty state.
    $this->get('/lessons')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('lessons', 0)->has('filterOptions.organs', 0));
});

it('hands the viewer an unwrapped OrganDto inside the lesson', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);
    AnatomicalStructure::factory()->published()->for($organ)->create(['name' => 'Aorta']);
    Lesson::factory()->published()->for($organ)->create(['slug' => 'blood-circulation']);

    $this->get('/lessons/blood-circulation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->missing('lesson.data')
            ->has('lesson', fn ($lesson) => $lesson
                ->hasAll(['id', 'slug', 'title', 'objective', 'steps', 'stepCount', 'organ', 'progress'])
                ->has('organ', fn ($dto) => $dto
                    ->hasAll(['id', 'slug', 'name', 'modelUrl', 'modelFormat', 'accentColor', 'structures'])
                    ->has('structures', 1, fn ($structure) => $structure
                        ->hasAll(['id', 'slug', 'name', 'anchorPosition', 'markerColor'])
                        ->etc()
                    )
                    ->etc()
                )
                ->etc()
            )
        );
});

it('renders the student\'s own progress into the page', function (): void {
    $organ = Organ::factory()->published()->create();
    $lesson = Lesson::factory()->published()->withSteps(4)->for($organ)->create(['slug' => 'resumable']);
    LessonProgress::factory()->for($this->student)->for($lesson)->create(['progress_percent' => 50]);

    // This is acceptance criterion 2: the page can resume because the row is
    // in the first paint, not fetched afterwards.
    $this->get('/lessons/resumable')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('lesson.progress.progressPercent', 50));
});

it('404s on an unpublished lesson and on a slug that does not exist', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->for($organ)->create(['slug' => 'unfinished']);

    $this->get('/lessons/unfinished')->assertNotFound();
    $this->get('/lessons/no-such-lesson')->assertNotFound();
});

it('carries no answer key', function (): void {
    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->for($organ)->create([
        'slug' => 'with-a-check',
        'content' => ['steps' => [[
            'type' => 'knowledge_check',
            'title' => 'Check yourself',
            'payload' => ['prompt' => 'Which chamber feeds the aorta?', 'reference' => 'demo.aorta'],
        ]]],
    ]);

    $response = $this->get('/lessons/with-a-check')->assertOk();

    expect($response->viewData('page')['props'])->toCarryNoAnswerKey();
});

it('does not scale queries with the size of the library', function (): void {
    $count = function (): int {
        Cache::flush();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/lessons')->assertOk();

        return $queries;
    };

    $organ = Organ::factory()->published()->create();
    Lesson::factory()->published()->count(2)->for($organ)->create();

    $withTwo = $count();

    Lesson::factory()->published()->count(8)->for($organ)->create();

    // Each card renders its organ, its body system and this student's progress.
    // All three are loaded in bulk (LessonService::progressForLessons).
    expect($count())->toBe($withTwo);
});
