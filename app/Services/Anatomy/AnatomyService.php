<?php

declare(strict_types=1);

namespace App\Services\Anatomy;

use App\Enums\OrganStatus;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Organ and structure reads, cached, plus structure resolution.
 *
 * Takes no request, no session, and no authenticated user: nothing it returns
 * is personalised, so every caller — controller, queued job, AI context
 * builder — gets the same object graph (invariant 1).
 *
 * It caches the *model graph*, not rendered JSON. Shaping stays the API
 * Resource's job and only the Resource's job (docs/architecture.md §7), so the
 * camelCase mirror of types.ts lives in one place instead of two.
 */
final class AnatomyService
{
    /**
     * 24 hours (docs/architecture.md §11).
     *
     * A constant rather than a config key: config/anatomy.php is owned by
     * Handover 01, and this TTL is a property of this service's invalidation
     * strategy. Every write path calls forget(), so the TTL is only a backstop
     * against a cache that outlived a deployment.
     */
    private const TTL_SECONDS = 86_400;

    private const KEY_ORGAN_LIST = 'anatomy:organs';

    private const KEY_UPCOMING_LIST = 'anatomy:organs:upcoming';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Published organs for the picker, without their structures.
     *
     * The list renders thumbnails and names; loading 25 structures to draw
     * three cards is the N+1's better-disguised cousin.
     *
     * @return Collection<int, Organ>
     */
    public function listPublishedOrgans(): Collection
    {
        $cached = $this->cache->get(self::KEY_ORGAN_LIST);

        if ($cached instanceof Collection) {
            return $cached;
        }

        /** @var Collection<int, Organ> $organs */
        $organs = Organ::query()
            ->published()
            ->with('bodySystem')
            ->withCount('publishedStructures')
            ->orderBy('name')
            ->get();

        $this->cache->put(self::KEY_ORGAN_LIST, $organs, self::TTL_SECONDS);

        return $organs;
    }

    /**
     * The coverage roadmap: organs that exist in the taxonomy with no model yet.
     *
     * Handover 16 seeds all eleven systems and the full organ taxonomy as draft
     * rows so coverage is visible and honest before an asset is encoded. This
     * is the read that makes them visible — deliberately, and only as far as a
     * name and a system. `UpcomingOrganResource` is what enforces that; this
     * method's job is to load the rows without their structures, because a
     * taxonomy row has none by construction (docs/organ-taxonomy.md §1).
     *
     * Draft-by-status, not draft-by-emptiness: an organ an admin has taken back
     * to draft to fix belongs here too, and its structures still must not ship.
     *
     * @return Collection<int, Organ>
     */
    public function listUpcomingOrgans(): Collection
    {
        $cached = $this->cache->get(self::KEY_UPCOMING_LIST);

        if ($cached instanceof Collection) {
            return $cached;
        }

        /** @var Collection<int, Organ> $organs */
        $organs = Organ::query()
            ->where('status', OrganStatus::Draft)
            ->with('bodySystem')
            ->orderBy('name')
            ->get();

        $this->cache->put(self::KEY_UPCOMING_LIST, $organs, self::TTL_SECONDS);

        return $organs;
    }

    /**
     * One taxonomy row by slug, for the deep link that must not 404.
     *
     * Resolved out of the list rather than with its own query and its own cache
     * key: the page that renders this already loads the list, so the second
     * lookup is free, and there is one fewer key for `forgetOrgan` to remember.
     */
    public function findUpcomingOrganBySlug(string $slug): ?Organ
    {
        return $this->listUpcomingOrgans()->firstWhere('slug', $slug);
    }

    /**
     * The viewer's hot path: one organ with everything labelled on it.
     *
     * Returns null for an unknown or unpublished slug so the controller can
     * 404 without distinguishing the two — a draft organ's existence is not
     * something an unauthenticated URL guess should confirm.
     *
     * A miss is deliberately not cached. Caching null would let a 404 issued
     * one second before an organ is published survive for 24 hours.
     */
    public function findPublishedOrganBySlug(string $slug): ?Organ
    {
        $key = self::organKey($slug);
        $cached = $this->cache->get($key);

        if ($cached instanceof Organ) {
            return $cached;
        }

        $organ = Organ::query()
            ->published()
            ->where('slug', $slug)
            ->with('publishedStructures')
            ->first();

        if ($organ instanceof Organ) {
            $this->cache->put($key, $organ, self::TTL_SECONDS);
        }

        return $organ;
    }

    /**
     * One published structure with its organ and its related structures.
     */
    public function findPublishedStructure(int $id): ?AnatomicalStructure
    {
        $key = self::structureKey($id);
        $cached = $this->cache->get($key);

        if ($cached instanceof AnatomicalStructure) {
            return $cached;
        }

        $structure = AnatomicalStructure::query()
            ->published()
            ->whereKey($id)
            ->with(['organ', 'publishedRelatedStructures'])
            ->first();

        if ($structure instanceof AnatomicalStructure) {
            $this->cache->put($key, $structure, self::TTL_SECONDS);
        }

        return $structure;
    }

    /**
     * Resolve a structure by its slug within an organ.
     *
     * Slugs are unique per organ, not globally — `apex` means one thing in the
     * heart and another in the lungs — so the organ is a required argument
     * rather than an optional filter.
     */
    public function findStructureBySlug(Organ $organ, string $slug): ?AnatomicalStructure
    {
        return AnatomicalStructure::query()
            ->where('organ_id', $organ->getKey())
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Resolve a structure by its Terminologia Anatomica term.
     *
     * This is the locale-independent identity the question bank, the RAG
     * filter, and the AI context all key off (docs/project-context.md §2.3).
     *
     * Matched with LIKE rather than `=` because a TA term arriving from a
     * model response or a curriculum document does not reliably preserve
     * capitalisation, and LIKE is case-insensitive on both MySQL and SQLite.
     * Wildcards are escaped so a caller-supplied `%` matches a literal one.
     */
    public function findStructureByTaTerm(string $taTerm): ?AnatomicalStructure
    {
        $normalised = trim($taTerm);

        if ($normalised === '') {
            return null;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $normalised);

        return AnatomicalStructure::query()
            ->where('ta_term', 'like', $escaped)
            ->first();
    }

    /**
     * Drop everything derived from one organ.
     *
     * Keys are enumerable, so this uses explicit forgets rather than cache
     * tags: the database and array stores this project runs in development and
     * CI do not support tagging, and a Cache::tags() call would throw there
     * while passing on Redis.
     */
    public function forgetOrgan(Organ $organ): void
    {
        $this->cache->forget(self::KEY_ORGAN_LIST);

        // Publishing moves a row from one list to the other, so both are stale
        // after any organ write — not just the one the row is currently in.
        $this->cache->forget(self::KEY_UPCOMING_LIST);

        $this->cache->forget(self::organKey($organ->slug));

        // The slug may have just changed. The entry cached under the old one
        // would otherwise serve a stale payload until the TTL expired.
        $originalSlug = $organ->getOriginal('slug');

        if (is_string($originalSlug) && $originalSlug !== $organ->slug) {
            $this->cache->forget(self::organKey($originalSlug));
        }
    }

    public function forgetStructure(AnatomicalStructure $structure): void
    {
        $this->cache->forget(self::structureKey((int) $structure->getKey()));
        $this->cache->forget(self::KEY_ORGAN_LIST);

        $organ = Organ::query()->find($structure->organ_id);

        if ($organ instanceof Organ) {
            $this->cache->forget(self::organKey($organ->slug));
        }
    }

    private static function organKey(string $slug): string
    {
        return 'anatomy:organ:'.$slug;
    }

    private static function structureKey(int $id): string
    {
        return 'anatomy:structure:'.$id;
    }

    /*
    |---------------------------------------------------------------------------
    | Administration (Handover 13)
    |---------------------------------------------------------------------------
    |
    | Everything above serves students and is therefore published-only. An
    | admin needs the drafts too, and needs to write. Both live here rather
    | than in the admin controller because this service owns the anatomy
    | tables: a controller writing them directly would put the FIT_SIZE
    | contract and the cache-invalidation rules in a second place
    | (docs/handovers/13-admin-content.md, invariant 7).
    |
    | Writes deliberately do not clear the cache by hand. OrganObserver and
    | AnatomicalStructureObserver fire on save and delete and call the forget
    | methods above, which is what keeps a write path nobody anticipated from
    | serving a stale organ (docs/architecture.md §11).
    */

    /**
     * Every organ, drafts included, for the admin listing.
     *
     * Paginated: `organs` is small today and this is the page that grows with
     * the content library (docs/engineering.md §10).
     *
     * Not cached. The admin list must show a draft the same second it is
     * created, and `anatomy:organs` deliberately holds published organs only —
     * reusing that key here would leak drafts into the student picker.
     *
     * @return LengthAwarePaginator<int, Organ>
     */
    public function paginateAllOrgans(int $perPage = 25): LengthAwarePaginator
    {
        return Organ::query()
            ->with('bodySystem')
            ->withCount('structures')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * One organ by slug regardless of status, with its structures.
     *
     * The admin counterpart of findPublishedOrganBySlug(). Separate rather
     * than a `$includeDrafts` flag on that one: a boolean parameter deciding
     * whether unpublished content is visible is exactly the kind of call site
     * that gets an unintended `true` (docs/engineering.md §2).
     */
    public function findAnyOrganBySlug(string $slug): ?Organ
    {
        return Organ::query()
            ->where('slug', $slug)
            ->with(['bodySystem', 'structures'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOrgan(array $attributes): Organ
    {
        $organ = new Organ($attributes);
        $organ->save();

        return $organ;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrgan(Organ $organ, array $attributes): Organ
    {
        $organ->fill($attributes);
        $organ->save();

        return $organ;
    }

    /**
     * Publish or unpublish an organ.
     *
     * One method taking the target state rather than publish()/unpublish():
     * the two would be the same three lines, and a single method means the
     * observer's invalidation cannot be wired to one and forgotten on the
     * other.
     */
    public function setOrganStatus(Organ $organ, OrganStatus $status): Organ
    {
        $organ->status = $status;
        $organ->save();

        return $organ;
    }

    public function deleteOrgan(Organ $organ): void
    {
        $organ->delete();
    }

    /**
     * Structures for one organ, drafts included.
     *
     * @return Collection<int, AnatomicalStructure>
     */
    public function listAllStructures(Organ $organ): Collection
    {
        /** @var Collection<int, AnatomicalStructure> $structures */
        $structures = $organ->structures()->orderBy('name')->get();

        return $structures;
    }

    public function findAnyStructure(int $id): ?AnatomicalStructure
    {
        return AnatomicalStructure::query()->with('organ')->find($id);
    }

    /**
     * Create a structure, recording what its anchor was authored against.
     *
     * Provenance is written here rather than left to a follow-up call, so
     * every structure this service creates carries it from birth. The
     * alternative — create, then setAnchorPosition() — saves twice and leaves
     * a window in which the row exists with an anchor and no record of the
     * model it came from.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createStructure(Organ $organ, array $attributes): AnatomicalStructure
    {
        $structure = new AnatomicalStructure($attributes);

        // Assigned from the passed-in Organ, never from the attribute array:
        // a payload naming another organ_id would file a structure under an
        // organ the admin did not open (docs/engineering.md §10).
        $structure->organ_id = (int) $organ->getKey();

        $metadata = $structure->metadata ?? [];
        $metadata['authored'] = $this->authoringProvenance($organ);
        $structure->metadata = $metadata;

        $structure->save();

        return $structure;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateStructure(AnatomicalStructure $structure, array $attributes): AnatomicalStructure
    {
        $structure->fill($attributes);
        $structure->save();

        return $structure;
    }

    public function setStructurePublished(AnatomicalStructure $structure, bool $published): AnatomicalStructure
    {
        $structure->is_published = $published;
        $structure->save();

        return $structure;
    }

    public function deleteStructure(AnatomicalStructure $structure): void
    {
        $structure->delete();
    }

    /**
     * Write an anchor authored by clicking the model.
     *
     * This is the hotspot authoring tool's one write. The coordinate arrives
     * from the viewer's `author:point` event and is **only meaningful in
     * FIT_SIZE pivot space** — the same space every other anchor in the table
     * was authored in (invariant 5).
     *
     * Which is why provenance is recorded alongside it. A coordinate is
     * authored against a specific model file; re-encode or replace that file
     * and the anchor may now point at the wrong anatomy, with nothing in the
     * data to say so. `metadata.authored` is what lets the admin UI warn
     * ("this anchor was authored against a different model") instead of
     * silently rendering a marker in the wrong place
     * (docs/handovers/13-admin-content.md).
     *
     * It goes in the existing `metadata` JSON column: Handover 13 owns no
     * tables and adds none.
     *
     * @param  array{0: float, 1: float, 2: float}  $anchor
     */
    public function setAnchorPosition(AnatomicalStructure $structure, array $anchor): AnatomicalStructure
    {
        $structure->anchor_position = $anchor;

        $metadata = $structure->metadata ?? [];
        $metadata['authored'] = $this->authoringProvenance($structure);
        $structure->metadata = $metadata;

        $structure->save();

        return $structure;
    }

    /**
     * What an anchor written right now would be authored against.
     *
     * `fit_size` is stored explicitly rather than assumed. It is frozen at 3.8
     * and a change is a plan change, but if one ever happens the stored value
     * is the only evidence of which anchors predate it — config alone cannot
     * tell you, because the original authoring scale is not recoverable.
     *
     * The fingerprint is the model file's size and modification time rather
     * than a content hash: an organ model is up to 2 MB and this is computed
     * on every authoring page load, where hashing the file would be a
     * noticeable stall for a signal that only needs to detect "the file
     * changed". A missing file yields null — the admin should be told the
     * model is absent, not shown a fabricated version string.
     *
     * @return array<string, mixed>
     */
    public function authoringProvenance(AnatomicalStructure|Organ $subject): array
    {
        $organ = $subject instanceof Organ ? $subject : $subject->organ;

        return [
            'model_path' => $organ->model_path,
            'model_fingerprint' => $this->modelFingerprint($organ),
            'fit_size' => (float) config('anatomy.fit_size'),
            'authored_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * True when this structure's anchor was authored against the organ model
     * that is on disk now.
     *
     * Unknown provenance counts as stale: every anchor seeded or imported
     * before the authoring tool existed has none, and telling an admin
     * "verified against the current model" on the strength of a missing record
     * is the one answer this must never give.
     */
    public function anchorMatchesCurrentModel(AnatomicalStructure $structure): bool
    {
        $authored = $structure->metadata['authored'] ?? null;

        if (! is_array($authored)) {
            return false;
        }

        $fingerprint = $this->modelFingerprint($structure->organ);

        return $fingerprint !== null
            && $authored['model_fingerprint'] === $fingerprint
            && $authored['model_path'] === $structure->organ->model_path;
    }

    /**
     * A cheap identity for the organ's model file, or null if it is missing.
     */
    private function modelFingerprint(Organ $organ): ?string
    {
        $disk = Storage::disk((string) config('anatomy.model_disk'));

        if (! $disk->exists($organ->model_path)) {
            return null;
        }

        return $disk->size($organ->model_path).'-'.$disk->lastModified($organ->model_path);
    }
}
