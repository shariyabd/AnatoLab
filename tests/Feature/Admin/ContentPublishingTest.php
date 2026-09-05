<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Enums\MissionStatus;
use App\Enums\OrganStatus;
use App\Models\AnatomicalStructure;
use App\Models\BodySystem;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\User;
use App\Services\Assessment\MissionService;
use App\Services\Lessons\LessonService;
use Illuminate\Support\Facades\Cache;

/*
| CRUD and publication for organs, lessons and missions.
|
| The property under test throughout is that publishing is a *separate act*:
| saving an edit never changes a status, and publishing goes through the
| owning service so its cache invalidation and validity checks run.
*/

beforeEach(function (): void {
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
});

it('creates an organ as a draft even if the form asks otherwise', function (): void {
    $system = BodySystem::factory()->create();

    $this->actingAs($this->admin)->post('/admin/organs', [
        'body_system_id' => $system->getKey(),
        'slug' => 'pancreas',
        'name' => 'Pancreas',
        'model_path' => 'models/pancreas.glb',
        'model_format' => 'glb',
        'accent_color' => '#e11d48',
    ])->assertRedirect();

    expect(Organ::query()->where('slug', 'pancreas')->sole()->status)->toBe(OrganStatus::Draft);
});

it('does not change status when an organ edit is saved', function (): void {
    $organ = Organ::factory()->published()->create();

    $this->actingAs($this->admin)->put("/admin/organs/{$organ->slug}", [
        'body_system_id' => $organ->body_system_id,
        'slug' => $organ->slug,
        'name' => 'Renamed',
        'model_path' => $organ->model_path,
        'model_format' => $organ->model_format->value,
        'accent_color' => $organ->accent_color,
    ])->assertRedirect();

    expect($organ->refresh()->name)->toBe('Renamed')
        ->and($organ->status)->toBe(OrganStatus::Published);
});

it('makes a published organ visible and clears the cache that hid it', function (): void {
    $organ = Organ::factory()->create(['status' => OrganStatus::Draft]);
    $anatomy = app(App\Services\Anatomy\AnatomyService::class);

    // Warm the cache while it is a draft, so a stale entry would be visible.
    expect($anatomy->findPublishedOrganBySlug($organ->slug))->toBeNull();

    $this->actingAs($this->admin)
        ->patch("/admin/organs/{$organ->slug}/status", ['status' => 'published'])
        ->assertRedirect();

    expect($anatomy->findPublishedOrganBySlug($organ->slug))->not->toBeNull();
});

it('publishes a lesson so a student can see it', function (): void {
    // Acceptance criterion 2.
    $organ = Organ::factory()->published()->create();
    $lesson = Lesson::factory()->for($organ)->create(['status' => LessonStatus::Draft]);

    $lessons = app(LessonService::class);

    expect($lessons->findPublishedBySlug($lesson->slug))->toBeNull();

    $this->actingAs($this->admin)
        ->patch("/admin/lessons/{$lesson->slug}/status", ['status' => 'published'])
        ->assertRedirect();

    expect($lessons->findPublishedBySlug($lesson->slug))->not->toBeNull();
});

it('warns when a published lesson sits on a draft organ', function (): void {
    // It can be published and still be invisible: the library requires both.
    $organ = Organ::factory()->create(['status' => OrganStatus::Draft]);
    $lesson = Lesson::factory()->for($organ)->create(['status' => LessonStatus::Draft]);

    $this->actingAs($this->admin)
        ->patch("/admin/lessons/{$lesson->slug}/status", ['status' => 'published'])
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'organ is still a draft'));
});

it('refuses to publish a mission whose steps do not resolve', function (): void {
    $organ = Organ::factory()->published()->create();

    $mission = Mission::factory()->for($organ)->create([
        'status' => MissionStatus::Draft,
        'configuration' => ['steps' => [
            ['structure_id' => 'not-a-real-structure', 'prompt' => 'Find it'],
        ]],
    ]);

    // A mission naming a structure the organ does not publish dead-ends on its
    // first step.
    $this->actingAs($this->admin)
        ->patch("/admin/missions/{$mission->slug}/status", ['status' => 'published'])
        ->assertSessionHasErrors('configuration');

    expect($mission->refresh()->status)->toBe(MissionStatus::Draft);
});

it('publishes a mission whose steps resolve against published structures', function (): void {
    $organ = Organ::factory()->published()->create();

    AnatomicalStructure::factory()->for($organ)->create([
        'slug' => 'left-atrium',
        'is_published' => true,
    ]);

    $mission = Mission::factory()->for($organ)->create([
        'status' => MissionStatus::Draft,
        'configuration' => ['steps' => [
            ['structure_id' => 'left-atrium', 'prompt' => 'Start at the left atrium'],
        ]],
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/missions/{$mission->slug}/status", ['status' => 'published'])
        ->assertRedirect();

    expect($mission->refresh()->status)->toBe(MissionStatus::Published)
        ->and(app(MissionService::class)->findPublishedBySlug($mission->slug))->not->toBeNull();
});

it('validates a lesson\'s step shape rather than accepting any array', function (): void {
    $organ = Organ::factory()->create();

    // LessonService counts content.steps to compute a progress percentage; a
    // malformed shape is a wrong number rather than an error anyone notices.
    $this->actingAs($this->admin)->post('/admin/lessons', [
        'organ_id' => $organ->getKey(),
        'slug' => 'broken',
        'title' => 'Broken',
        'objective' => 'Nothing',
        'difficulty' => 'beginner',
        'estimated_minutes' => 10,
        'content' => ['steps' => [['type' => 'not-a-step-type', 'title' => 'x']]],
    ])->assertSessionHasErrors('content.steps.0.type');
});

it('rejects a slug that is not url safe', function (): void {
    $system = BodySystem::factory()->create();

    $this->actingAs($this->admin)->post('/admin/organs', [
        'body_system_id' => $system->getKey(),
        'slug' => 'Not A Slug',
        'name' => 'Bad',
        'model_path' => 'models/x.glb',
        'model_format' => 'glb',
        'accent_color' => '#ffffff',
    ])->assertSessionHasErrors('slug');
});
