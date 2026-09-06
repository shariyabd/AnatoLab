<?php

declare(strict_types=1);

use App\Enums\OrganStatus;
use App\Models\AnatomicalStructure;
use App\Models\BodySystem;
use App\Models\Organ;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\BodyTaxonomySeeder;

/*
| The coverage roadmap is a first-class artefact, the same way the demo dataset
| is (docs/engineering.md §6). These assert the acceptance criteria in
| docs/handovers/16-full-body-coverage.md that F16 can actually satisfy: the
| eleven systems, the full taxonomy as unpublished rows, no placeholder
| structures, and no draft leaking to a student. The criteria it cannot satisfy
| are recorded as todos at the bottom rather than quietly dropped.
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
    $this->seed(BodyTaxonomySeeder::class);
});

it('seeds all eleven body systems', function (): void {
    expect(BodySystem::query()->pluck('slug')->sort()->values()->all())
        ->toBe([
            'cardiovascular', 'digestive', 'endocrine', 'integumentary',
            'lymphatic', 'musculoskeletal', 'nervous', 'reproductive',
            'respiratory', 'sensory', 'urinary',
        ]);
});

it('gives every body system at least one organ', function (): void {
    $empty = BodySystem::query()
        ->withCount('organs')
        ->get()
        ->filter(fn (BodySystem $system): bool => $system->organs_count === 0)
        ->pluck('slug')
        ->all();

    expect($empty)->toBe([], 'A system with no organs is a heading with nothing under it.');
});

it('seeds the full taxonomy, nine published and the rest draft', function (): void {
    expect(Organ::query()->count())->toBe(44)
        ->and(Organ::query()->where('status', OrganStatus::Published)->count())->toBe(9)
        ->and(Organ::query()->where('status', OrganStatus::Draft)->count())->toBe(35);
});

it('resolves the four entries that are not single organs', function (): void {
    // docs/organ-taxonomy.md §3. Vessels and lymph nodes become network organs;
    // bone and muscle become regional models; skin appendages are already
    // structures on the skin organ and get no organ row of their own.
    expect(Organ::query()->where('slug', 'vascular-system')->exists())->toBeTrue()
        ->and(Organ::query()->where('slug', 'lymphatic-system')->exists())->toBeTrue()
        ->and(Organ::query()->whereIn('slug', ['skull', 'rib-cage', 'knee-joint'])->count())->toBe(3)
        ->and(Organ::query()->where('slug', 'skeleton')->exists())->toBeFalse();

    /** @var Organ $skin */
    $skin = Organ::query()->where('slug', 'skin')->sole();

    expect($skin->structures()->whereIn('slug', ['follicle', 'sweat-gland', 'sebaceous-gland'])->count())
        ->toBe(3);
});

it('does not duplicate anything already taught as a structure', function (): void {
    // Trachea, gallbladder and the two intestines are structures on published
    // organs. An organ row as well would be a second teachable entity with the
    // same name and a second mastery topic (docs/organ-taxonomy.md §4).
    expect(Organ::query()->whereIn('slug', [
        'trachea', 'gallbladder', 'small-intestine', 'large-intestine',
    ])->count())->toBe(0);
});

it('seeds no placeholder structures on a taxonomy row', function (): void {
    $drafts = Organ::query()->where('status', OrganStatus::Draft)->pluck('id')->all();

    expect(AnatomicalStructure::query()->whereIn('organ_id', $drafts)->count())
        ->toBe(0, 'Handover 16: do not seed placeholder structures to satisfy a count.');
});

it('points every taxonomy row at a placeholder model path, not a manifest row', function (): void {
    Organ::query()->where('status', OrganStatus::Draft)->get()->each(
        fn (Organ $organ) => expect($organ->model_path)->toBe("models/pending/{$organ->slug}.glb")
            ->and($organ->thumbnail_path)->toBeNull()
    );
});

it('keeps every draft out of the student-facing reads', function (): void {
    /** @var AnatomyService $anatomy */
    $anatomy = app(AnatomyService::class);

    expect($anatomy->listPublishedOrgans())->toHaveCount(9)
        ->and($anatomy->findPublishedOrganBySlug('stomach'))->toBeNull()
        ->and($anatomy->findPublishedOrganBySlug('uterus'))->toBeNull();
});

it('gives every published organ at least six published structures', function (): void {
    // The per-organ definition of done, handover 16. Six is the floor for a
    // credible quiz; the nine published organs are all at eight.
    Organ::query()->published()->withCount('publishedStructures')->get()->each(
        fn (Organ $organ) => expect($organ->published_structures_count)
            ->toBeGreaterThanOrEqual(6, "{$organ->slug} has too few published structures to publish.")
    );
});

it('gives every published organ a manifest row carrying an asset-register id', function (): void {
    // Handover 16: "Every published organ has an asset-register row." The
    // scripts already check manifest -> register; nothing checked
    // database -> manifest, which is the half that can silently publish an
    // organ whose model was never measured or licensed.
    $manifest = json_decode(
        (string) file_get_contents(base_path('public/models/manifest.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<int, array<string, mixed>> $models */
    $models = $manifest['models'];
    $byPath = [];

    foreach ($models as $model) {
        $byPath[(string) $model['modelPath']] = (string) $model['registerId'];
    }

    $register = (string) file_get_contents(base_path('docs/asset-register.md'));

    Organ::query()->published()->get()->each(function (Organ $organ) use ($byPath, $register): void {
        $registerId = $byPath[$organ->model_path] ?? null;

        expect($registerId)->not->toBeNull("{$organ->slug} is published with no manifest row for {$organ->model_path}.")
            ->and($register)->toContain((string) $registerId);
    });
});

it('is idempotent and never demotes a published organ', function (): void {
    $before = [
        BodySystem::query()->count(),
        Organ::query()->count(),
        Organ::query()->where('status', OrganStatus::Published)->count(),
        AnatomicalStructure::query()->count(),
    ];

    $this->seed(BodyTaxonomySeeder::class);
    $this->seed(BodyTaxonomySeeder::class);

    expect([
        BodySystem::query()->count(),
        Organ::query()->count(),
        Organ::query()->where('status', OrganStatus::Published)->count(),
        AnatomicalStructure::query()->count(),
    ])->toBe($before);
});

it('leaves a taxonomy row alone once it has been published with a real model', function (): void {
    // The reason this seeder is firstOrCreate and not updateOrCreate: re-seeding
    // must not walk a published organ back to a draft pointing at a placeholder.
    Organ::query()->where('slug', 'stomach')->update([
        'status' => OrganStatus::Published->value,
        'model_path' => 'models/stomach.glb',
    ]);

    $this->seed(BodyTaxonomySeeder::class);

    /** @var Organ $stomach */
    $stomach = Organ::query()->where('slug', 'stomach')->sole();

    expect($stomach->status)->toBe(OrganStatus::Published)
        ->and($stomach->model_path)->toBe('models/stomach.glb');
});

it('fails loudly when the set AnatomySeeder owns has drifted', function (): void {
    Organ::query()->where('slug', 'eyeball')->delete();

    expect(fn () => $this->seed(BodyTaxonomySeeder::class))
        ->toThrow(RuntimeException::class, 'eyeball');
});

/*
| The taxonomy on screen. Handover 16 wants the roadmap visible and inert, and
| specifically "never a 404" — asserted against the real seeded taxonomy rather
| than a factory, because the thing that can break is a slug in the taxonomy
| that the route does not recognise.
*/

it('renders an inert coming-soon state for a taxonomy row rather than a 404', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/explore/stomach')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Explore')
            ->where('organ', null)
            ->where('comingSoon.slug', 'stomach')
            ->where('comingSoon.name', 'Stomach')
            ->where('comingSoon.bodySystem.slug', 'digestive')
        );
});

it('answers every seeded taxonomy slug without a 404', function (): void {
    $this->actingAs(User::factory()->create());

    // The whole taxonomy, not a sample. A slug the seeder writes and the route
    // rejects is a dead link in the roadmap, and one dead link is enough to
    // make the roadmap untrustworthy.
    Organ::query()->where('status', OrganStatus::Draft)->pluck('slug')->each(
        fn (string $slug) => $this->get("/explore/{$slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('comingSoon.slug', $slug))
    );
});

it('still 404s on a slug that is in no taxonomy at all', function (): void {
    $this->actingAs(User::factory()->create());

    // "Never a 404" is about the taxonomy, not about every string. A route that
    // renders a page for any slug makes a typo look like content.
    $this->get('/explore/gizzard')->assertNotFound();
});

it('offers the roadmap on the library page without a model URL anywhere in it', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('organs', 9)
            ->has('upcoming', 35)
            ->missing('upcoming.0.modelUrl')
            ->missing('upcoming.0.thumbnailUrl')
        );
});

it('never puts a pending model path in a page payload', function (): void {
    $this->actingAs(User::factory()->create());

    // The placeholder path is the tell that no asset exists. If it ever reaches
    // the client, something is serialising a draft organ through a Resource
    // built for a published one.
    $this->get('/explore')->assertOk()->assertDontSee('models/pending', escape: false);
    $this->get('/explore/uterus')->assertOk()->assertDontSee('models/pending', escape: false);
});

/*
| Blocked, not forgotten. The reason is recorded in docs/organ-taxonomy.md §7
| rather than in a test that quietly asserts the broken behaviour instead.
*/

it('publishes tier 2 to the per-organ definition of done')
    ->todo(note: 'Blocked on asset sourcing: docs/asset-sources.md §3.1 records that no source has '
        .'been adopted, docs/asset-register.md §6 is unsigned, and docs/licence-log.md §4 is not '
        .'yet taken. Also needs organs.tagline and organs.key_facts, which F03 owns and which do '
        .'not exist.');
