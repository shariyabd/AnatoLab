<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
| The hotspot authoring tool: an `author:point` payload becomes an
| `anchor_position` (docs/handovers/13-admin-content.md, Tests).
|
| Every coordinate here is in FIT_SIZE = 3.8 pivot space. That is not decoration
| — a value outside it is rejected, because a marker authored in an
| un-normalised world space would point at nothing (invariant 5).
*/

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('public');

    $this->admin = User::factory()->admin()->create();
    $this->organ = Organ::factory()->published()->create(['model_path' => 'models/heart.glb']);
});

it('persists an authored point as a three-float array', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/organs/{$this->organ->slug}/structures", [
            'slug' => 'left-ventricle',
            'name' => 'Left ventricle',
            'ta_term' => 'Ventriculus sinister',
            'difficulty' => 2,
            'anchor_position' => [0.7, -0.75, 0.65],
        ])
        ->assertRedirect();

    $structure = AnatomicalStructure::query()->where('slug', 'left-ventricle')->sole();

    expect($structure->anchor_position)->toBe([0.7, -0.75, 0.65])
        ->and($structure->organ_id)->toBe($this->organ->getKey())
        // Unpublished by default: authored-but-unverified content must not
        // reach a student by omission.
        ->and($structure->is_published)->toBeFalse();
});

it('files the structure under the organ in the URL, not one named in the payload', function (): void {
    $other = Organ::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/organs/{$this->organ->slug}/structures", [
            'slug' => 'apex',
            'name' => 'Apex',
            'difficulty' => 1,
            'anchor_position' => [0.1, 0.2, 0.3],
            // Ignored — the service assigns organ_id from the route's Organ.
            'organ_id' => $other->getKey(),
        ])
        ->assertRedirect();

    expect(AnatomicalStructure::query()->where('slug', 'apex')->sole()->organ_id)
        ->toBe($this->organ->getKey());
});

it('moves an existing anchor and returns the updated structure', function (): void {
    $structure = AnatomicalStructure::factory()->for($this->organ)->create([
        'anchor_position' => [0.0, 0.0, 0.0],
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/admin/structures/{$structure->id}/anchor", [
            'anchor_position' => [1.2, -0.4, 0.9],
        ])
        ->assertOk()
        ->assertJsonPath('structure.anchorPosition', [1.2, -0.4, 0.9]);

    expect($structure->refresh()->anchor_position)->toBe([1.2, -0.4, 0.9]);
});

it('rejects a coordinate authored outside FIT_SIZE pivot space', function (): void {
    // An un-normalised world coordinate. Accepting it would put a marker
    // nowhere near the organ, and nothing downstream would notice.
    $structure = AnatomicalStructure::factory()->for($this->organ)->create();

    $this->actingAs($this->admin)
        ->patchJson("/admin/structures/{$structure->id}/anchor", [
            'anchor_position' => [148.2, -93.0, 220.5],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('anchor_position.0');
});

it('rejects an anchor that is not exactly three numbers', function (): void {
    $structure = AnatomicalStructure::factory()->for($this->organ)->create();

    $this->actingAs($this->admin)
        ->patchJson("/admin/structures/{$structure->id}/anchor", ['anchor_position' => [1.0, 2.0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('anchor_position');
});

it('records which model version an anchor was authored against', function (): void {
    Storage::disk('public')->put('models/heart.glb', 'glb-bytes-v1');

    $this->actingAs($this->admin)
        ->post("/admin/organs/{$this->organ->slug}/structures", [
            'slug' => 'aorta',
            'name' => 'Aorta',
            'difficulty' => 1,
            'anchor_position' => [0.5, 0.5, 0.5],
        ]);

    $structure = AnatomicalStructure::query()->where('slug', 'aorta')->sole();
    $authored = $structure->metadata['authored'] ?? null;

    expect($authored)->toBeArray()
        ->and($authored['model_path'])->toBe('models/heart.glb')
        ->and($authored['fit_size'])->toBe(3.8)
        ->and($authored['model_fingerprint'])->not->toBeNull();
});

it('warns when the model has changed since the anchor was authored', function (): void {
    $anatomy = app(AnatomyService::class);

    Storage::disk('public')->put('models/heart.glb', 'glb-bytes-v1');

    $structure = AnatomicalStructure::factory()->for($this->organ)->create();
    $anatomy->setAnchorPosition($structure, [0.5, 0.5, 0.5]);

    expect($anatomy->anchorMatchesCurrentModel($structure->refresh()))->toBeTrue();

    // Re-encode the model. Every anchor authored against the old file may now
    // point at the wrong anatomy, and only the fingerprint says so.
    Storage::disk('public')->put('models/heart.glb', 'glb-bytes-v2-reencoded-and-longer');

    expect($anatomy->anchorMatchesCurrentModel($structure->refresh()))->toBeFalse();
});

it('treats an anchor with no authoring record as unverified', function (): void {
    // Everything seeded before this tool existed. Unknown is not verified.
    $structure = AnatomicalStructure::factory()->for($this->organ)->create(['metadata' => null]);

    expect(app(AnatomyService::class)->anchorMatchesCurrentModel($structure))->toBeFalse();
});

it('shows the authoring page with the pivot space and the current model', function (): void {
    AnatomicalStructure::factory()->for($this->organ)->create();

    $props = $this->actingAs($this->admin)
        ->get("/admin/organs/{$this->organ->slug}/author")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['authoring']['fitSize'])->toBe(3.8)
        ->and($props['authoring']['currentModel']['model_path'])->toBe('models/heart.glb')
        ->and($props['organ']['slug'])->toBe($this->organ->slug);
});

it('shows draft structures on the authoring canvas', function (): void {
    // A draft structure the admin cannot see is one they cannot move.
    AnatomicalStructure::factory()->for($this->organ)->create([
        'slug' => 'hidden',
        'is_published' => false,
    ]);

    $props = $this->actingAs($this->admin)
        ->get("/admin/organs/{$this->organ->slug}/author")
        ->viewData('page')['props'];

    expect(collect($props['structures'])->pluck('slug'))->toContain('hidden');
});

it('refuses a duplicate slug within the same organ but allows it across organs', function (): void {
    AnatomicalStructure::factory()->for($this->organ)->create(['slug' => 'apex']);

    $this->actingAs($this->admin)
        ->post("/admin/organs/{$this->organ->slug}/structures", [
            'slug' => 'apex',
            'name' => 'Apex again',
            'difficulty' => 1,
            'anchor_position' => [0.1, 0.1, 0.1],
        ])
        ->assertSessionHasErrors('slug');

    // `apex` means one thing in the heart and another in the lungs.
    $lungs = Organ::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/organs/{$lungs->slug}/structures", [
            'slug' => 'apex',
            'name' => 'Apex of the lung',
            'difficulty' => 1,
            'anchor_position' => [0.1, 0.1, 0.1],
        ])
        ->assertSessionHasNoErrors();
});

it('publishes a structure so it reaches the viewer', function (): void {
    // Acceptance criterion 1: an admin adds a structure by clicking the model,
    // and it appears in the viewer.
    $structure = AnatomicalStructure::factory()->for($this->organ)->create([
        'is_published' => false,
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/structures/{$structure->id}/status", ['is_published' => true])
        ->assertRedirect();

    $organ = app(AnatomyService::class)->findPublishedOrganBySlug($this->organ->slug);

    expect($organ?->publishedStructures->modelKeys())->toContain($structure->getKey());
});
