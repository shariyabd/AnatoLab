<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\User;

/*
| The mission pages' Inertia props (handover 09).
|
| `mission.organ` is an OrganDto, unwrapped, because the page hands it straight
| to useAnatomyViewer and the frozen contract in resources/js/anatomy/types.ts
| says what that object looks like. A `data` wrapper here is a contract break
| TypeScript cannot see (docs/feature-plan.md §7.8).
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $this->atrium = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-atrium',
    ]);
    $this->ventricle = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
    ]);
});

it('sends a guest to log in rather than to a mission', function (): void {
    auth()->logout();

    $this->get('/missions')->assertRedirect('/login');
    $this->get('/missions/trace-the-blood')->assertRedirect('/login');
});

it('lists only missions a student can run', function (): void {
    Mission::factory()
        ->published()
        ->tracing([$this->atrium, $this->ventricle])
        ->create(['slug' => 'trace-the-blood', 'title' => 'Trace the Blood']);

    Mission::factory()->tracing([$this->atrium])->create(['slug' => 'still-a-draft']);

    $this->get('/missions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Missions/Index')
            ->has('missions', 1)
            ->where('missions.0.slug', 'trace-the-blood')
            ->where('missions.0.stepCount', 2)
            ->where('missions.0.organ.slug', 'heart')
        );
});

it('renders an empty state rather than failing when nothing is published', function (): void {
    $this->get('/missions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Missions/Index')->has('missions', 0));
});

it('renders a run with the organ unwrapped and the steps stripped', function (): void {
    Mission::factory()
        ->published()
        ->tracing([$this->atrium, $this->ventricle])
        ->create(['slug' => 'trace-the-blood', 'title' => 'Trace the Blood']);

    $this->get('/missions/trace-the-blood')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Missions/Show')
            ->where('mission.slug', 'trace-the-blood')
            ->where('mission.title', 'Trace the Blood')
            ->where('mission.ordered', true)
            ->has('mission.steps', 2)
            ->has('mission.steps.0', fn ($step) => $step
                ->where('index', 0)
                ->has('prompt')
                ->has('hint')
            )
            // Unwrapped, and the same shape Explore hands the viewer.
            ->has('mission.organ.structures', 2)
            ->where('mission.organ.structures.0.id', fn (string $id): bool => $id !== '')
        );
});

it('404s a mission that is not published', function (): void {
    Mission::factory()->tracing([$this->atrium])->create(['slug' => 'still-a-draft']);

    $this->get('/missions/still-a-draft')->assertNotFound();
});

it('adds one navigation entry pointing at a route that resolves', function (): void {
    // config/navigation.php is append-only and shared by seven lanes
    // (docs/feature-plan.md §7.4); this asserts this lane's line and nothing else.
    $entry = collect(config('navigation.main'))->firstWhere('key', 'missions');

    expect($entry)->not->toBeNull()
        ->and($entry['route'])->toBe('missions.index')
        ->and(Route::has('missions.index'))->toBeTrue();
});
