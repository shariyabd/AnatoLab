# Handover 03 — Anatomy Domain & API (delivered)

**Branch** `feat/f03-anatomy-domain-api` · **Contract** [`03-anatomy-domain-api.md`](../03-anatomy-domain-api.md) · **Status** delivered

## What shipped

The four anatomy tables, their models and factories, a cached read service, three JSON
endpoints, and the seeded content for heart, lungs and brain. This is the contract
Handovers 04, 05, 07, 08, 09, 12 and 13 all resolve structures through, and it mirrors the
frozen `resources/js/anatomy/types.ts` exactly — a test reads that file and fails on drift.

- List published organs with thumbnails, body system, and a published-structure count
- Fetch one organ with its published structures — the payload Handover 04 loads untransformed
- Fetch one structure with full metadata, its organ, and its typed related structures
- Resolve a structure by slug within an organ, or by Terminologia Anatomica term
- 24-hour caching keyed `anatomy:organs`, `anatomy:organ:{slug}`, `anatomy:structure:{id}`,
  invalidated by model observers on every write path
- An admin read/write surface on the same service, for Handover 13

## Public surface

### Routes (`routes/features/anatomy.php`)

Prefix `api/v1/anatomy`, name prefix `api.v1.anatomy.`, middleware `['auth', 'throttle:api']`.

| Method | URI | Name | Action |
|---|---|---|---|
| GET | `/api/v1/anatomy/organs` | `api.v1.anatomy.organs.index` | `AnatomyController@index` |
| GET | `/api/v1/anatomy/organs/{organ}` | `api.v1.anatomy.organs.show` | `AnatomyController@show` |
| GET | `/api/v1/anatomy/structures/{structure}` | `api.v1.anatomy.structures.show` | `AnatomyController@structure` (`whereNumber`) |

### Services / enums / observers

| Class | Responsibility |
|---|---|
| `App\Services\Anatomy\AnatomyService` | Cached organ/structure reads, slug and TA-term resolution, cache invalidation, and the Handover 13 admin writes |
| `App\Http\Resources\Anatomy\OrganResource` | Mirrors `OrganDto`; resolves `modelUrl` from the disk-relative `model_path` |
| `App\Http\Resources\Anatomy\OrganSummaryResource` | Picker card — deliberately *not* `OrganDto`; no model URL, no structures |
| `App\Http\Resources\Anatomy\StructureResource` | Mirrors `StructureDto` — eleven keys, exactly |
| `App\Http\Resources\Anatomy\StructureDetailResource` | Superset of `StructureDto` plus `location`, `metadata`, `organ`, `relatedStructures` |
| `App\Enums\OrganStatus` | `draft` \| `published` |
| `App\Enums\ModelFormat` | `glb` \| `gltf` — mirrors the `OrganDto.modelFormat` literal union |
| `App\Enums\StructureRelationType` | `adjacent` \| `part_of` \| `flows_into` \| `counterpart`, each with a `label()` |
| `OrganObserver`, `AnatomicalStructureObserver`, `StructureRelationObserver` | Attached by `#[ObservedBy]`; clear the cache on `saved` and `deleted` |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `body_systems` | `slug` (unique), `name`, `description` | — |
| `organs` | `body_system_id`, `slug` (unique), `name`, `scientific_name`, `description`, `model_path` (NOT NULL), `model_format`, `thumbnail_path`, `accent_color(9)`, `status` | FK → `body_systems` restrict-on-delete; index on `status` |
| `anatomical_structures` | `organ_id`, `slug`, `ta_term` (nullable), `name`, `scientific_name`, `description`, `function`, `location`, `difficulty`, `anchor_position` (json), `model_object_name` (nullable), `marker_color(9)`, `metadata` (json), `is_published` (default false) | FK → `organs` cascade; unique `(organ_id, slug)`; index `ta_term`; index `(organ_id, is_published)` |
| `structure_relations` | `structure_id`, `related_structure_id`, `relation_type` | Both FKs cascade; unique `(structure_id, related_structure_id, relation_type)` |

### Client contract

`OrganResource` → `id, slug, name, scientificName, description, modelUrl, modelFormat,
accentColor, structures[]`. `StructureResource` → `id, slug, name, taTerm, scientificName,
description, function, difficulty, anchorPosition, modelObjectName, markerColor`. `id` is
stringified on both, because `StructureId` is `string` on the viewer side and opaque there.

## Key decisions

- **Structure identity is `slug` + `ta_term` + `anchor_position`, not a mesh name** — every
  audited GLB is one node named `tripo_node_<uuid>`, so there is no per-structure geometry to
  select. `model_object_name` is nullable and null on every seeded row so per-structure models
  can later land "with no schema and no API change."
- **The service caches the model graph, not rendered JSON**, so shaping stays the Resource's
  job and the camelCase mirror of `types.ts` lives in one place instead of two.
- **A cache miss is not cached.** Caching null "would let a 404 issued one second before an
  organ is published survive for 24 hours."
- **Route model binding is deliberately not used** — binding would query the row a second time
  and bypass the cache the endpoint's latency budget depends on.
- **Invalidation is by explicit `forget()`, not cache tags**, because the array and database
  stores used in development and CI would throw on `Cache::tags()` while passing on Redis.
  `forgetOrgan` also forgets the *original* slug when it has just changed.
- **`publishedStructures` / `publishedRelatedStructures` are separate relations, not call-site
  filters**, so no lane can ship unverified content by eager-loading the unfiltered one.
- **TA terms match with `LIKE`, with wildcards escaped** — a term arriving from a model
  response or a curriculum document does not reliably preserve capitalisation.
- **The endpoints are authenticated, not public**, because until the licence gate closes the
  model URLs in the payload must not be reachable without a login.

## Invariants honoured

- **1 — services read no request/auth/session.** `AnatomyService` takes only a
  `CacheRepository`; every method's inputs are typed scalars or models. Exercised by
  `StructureResolutionTest` and `AnatomyCacheTest`, which call it with no HTTP request.
- **6 — Eloquent only, `declare(strict_types=1)` everywhere.** No `DB::table`/`DB::raw` in any
  delivered file; `DB` appears in tests only, to count queries.
- **7 — thin controllers.** `AnatomyController` has three methods, each of which calls the
  service and returns a Resource; there is no FormRequest because the surface is read-only.
- **8 — no answer key.** `StructureEndpointTest` → *"carries no answer key"* asserts the
  structure payload leaks nothing correctness-shaped.
- **N+1.** `OrganEndpointsTest` → *"lists organs without N+1 queries"* counts queries around the
  list endpoint; the service eager-loads `bodySystem` and `withCount('publishedStructures')`.

## Tests

| File | What it proves |
|---|---|
| `tests/Feature/Anatomy/OrganEndpointsTest.php` (9) | Published-only listing and counts, organ payload with published structures, 404 for unknown and for draft organs, `modelObjectName` null, no N+1, auth required |
| `tests/Feature/Anatomy/StructureEndpointTest.php` (7) | Full metadata, related structures with their relation verb, unpublished relations omitted, 404 for unpublished / unknown / non-numeric ids, no answer key |
| `tests/Feature/Anatomy/AnatomyCacheTest.php` (9) | Repeat reads hit the cache, the documented key names, misses are not cached, every observer path invalidates (including a changed slug), and a cached organ resolves inside the 100 ms budget |
| `tests/Feature/Anatomy/ContractParityTest.php` (4) | Reads `types.ts` and asserts the Resources emit exactly the keys `StructureDto` and `OrganDto` declare, that ids are strings, and that `modelFormat` stays inside the viewer's union |
| `tests/Feature/Anatomy/AnchorPositionTest.php` (4) | `anchor_position` round-trips as three floats in order through the database and the endpoint, and every seeded anchor sits inside the `FIT_SIZE` cube |
| `tests/Feature/Anatomy/StructureResolutionTest.php` (6) | Slug resolution is organ-scoped, TA-term resolution survives case and whitespace, a caller-supplied `%` is a literal, empty input returns null |
| `tests/Feature/Anatomy/AnatomySeederTest.php` (8) | Three published organs, ≥ 8 published structures each, every structure has a TA term and an anchor, `model_object_name` null throughout, relations seeded, idempotent, model paths are placeholders |

## Known gaps / follow-ups

- **`model_path` values are placeholders.** `models/heart.glb`, `models/lungs.glb`,
  `models/brain.glb` do not exist — Handover 02's manifest is `pending-licence` with an empty
  model set. The swap is one commit touching only those three values.
- **Anchor coordinates are authored, not measured.** The seeder says so plainly: the models
  they describe are not in the repository, so they are plausible placements awaiting a pass
  through Handover 04's author mode.
- **Relations are seeded in one direction only.** The seeder writes 28 edges (heart 10, lungs 9,
  brain 9) and the `relatedStructures` relation only follows `structure_id` → `related_structure_id`.
  So `apex → left-ventricle (part_of)` makes the ventricle visible from the apex but not the
  apex from the ventricle. The migration docblock claims "the seeder writes both directions
  where the relationship is genuinely symmetric" — **it does not.** Either the docblock or the
  seeder needs correcting before Handover 08 renders these as prose.
- **26 structures, not 27.** Heart 9, lungs 9, brain 8 — the contract asked for "~8" each, so
  this meets it, but the brain is the thinnest.
- **Content accuracy is unresolved.** The English prose is written here rather than migrated
  from the upstream AI-generated copy, but it has not been fact-checked by a subject expert;
  Handover 02 flagged the same risk for the models themselves.
- **`AnatomyService` already carries Handover 13's admin surface** — `paginateAllOrgans`,
  `findAnyOrganBySlug`, the create/update/delete/publish methods, `setAnchorPosition` and the
  `metadata.authored` provenance helpers. That is beyond this contract's scope; it is a
  deliberate seam for **13**, but 13's owner inherits it rather than designing it.
- **`findStructureBySlug` / `findStructureByTaTerm` have no HTTP surface.** They are service-only,
  for consumption by **07**, **08** and **11**.
