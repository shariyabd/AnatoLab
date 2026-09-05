<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\User;
use App\Services\Lessons\LessonService;
use Illuminate\Support\Facades\Cache;

/*
| `lesson:{slug}` for one hour (docs/architecture.md §11), and the one thing
| that must never be in it: another student's progress.
*/

beforeEach(function (): void {
    Cache::flush();
    $this->lessons = app(LessonService::class);
    $this->organ = Organ::factory()->published()->create();
});

it('caches a lesson under lesson:{slug}', function (): void {
    $lesson = Lesson::factory()->published()->for($this->organ)->create(['slug' => 'blood-circulation']);

    expect(Cache::get('lesson:blood-circulation'))->toBeNull();

    $this->lessons->findPublishedBySlug('blood-circulation');

    expect(Cache::get('lesson:blood-circulation'))->toBeInstanceOf(Lesson::class)
        ->and(Cache::get('lesson:blood-circulation')->getKey())->toBe($lesson->getKey());
});

it('does not cache a miss', function (): void {
    // Caching null would let a 404 issued a second before publication survive
    // for the whole hour.
    expect($this->lessons->findPublishedBySlug('not-yet'))->toBeNull()
        ->and(Cache::has('lesson:not-yet'))->toBeFalse();
});

it('clears the cache when the lesson is edited', function (): void {
    $lesson = Lesson::factory()->published()->for($this->organ)->create(['slug' => 'valves']);
    $this->lessons->findPublishedBySlug('valves');

    $lesson->update(['title' => 'A better title']);

    expect(Cache::has('lesson:valves'))->toBeFalse();
    expect($this->lessons->findPublishedBySlug('valves')->title)->toBe('A better title');
});

it('clears the entry under the old slug when the slug changes', function (): void {
    $lesson = Lesson::factory()->published()->for($this->organ)->create(['slug' => 'old-slug']);
    $this->lessons->findPublishedBySlug('old-slug');

    $lesson->update(['slug' => 'new-slug']);

    expect(Cache::has('lesson:old-slug'))->toBeFalse();
    expect($this->lessons->findPublishedBySlug('old-slug'))->toBeNull();
});

it('clears the cache when the lesson is deleted', function (): void {
    $lesson = Lesson::factory()->published()->for($this->organ)->create(['slug' => 'doomed']);
    $this->lessons->findPublishedBySlug('doomed');

    $lesson->delete();

    expect(Cache::has('lesson:doomed'))->toBeFalse();
});

it('never caches one student\'s progress where another can read it', function (): void {
    // The cached object is the lesson, shared by everyone. Progress is looked
    // up per request against the passed-in User (docs/architecture.md §11:
    // personalised data is never cached across users).
    $lesson = Lesson::factory()->published()->withSteps(4)->for($this->organ)->create(['slug' => 'shared']);

    $first = User::factory()->create();
    $second = User::factory()->create();

    LessonProgress::factory()->completed()->for($first)->for($lesson)->create();

    $this->actingAs($first)->getJson('/api/v1/lessons/shared')
        ->assertOk()
        ->assertJsonPath('data.progress.status', 'completed');

    $this->actingAs($second)->getJson('/api/v1/lessons/shared')
        ->assertOk()
        ->assertJsonPath('data.progress', null);
});
