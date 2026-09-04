# Handover 03 — Anatomy Domain & API

**Feature:** F03 · **Lane:** Anatomy · **Wave:** 2 · **Branch:** `feat/f03-anatomy-domain-api`

> Read first: `docs/architecture.md` §6, §7 · `docs/project-context.md` §2.3, §5.5

## Objective

Own the anatomy data model and serve it. This publishes the single most-consumed contract in
the project — F05, F07, F08, F12, and F13 all resolve structures through you.

## Scope

- Migrations, models, factories for `body_systems`, `organs`, `anatomical_structures`,
  `structure_relations`
- `AnatomyService`, `Api/V1/AnatomyController`, `OrganResource`, `StructureResource`
- Anatomy cache layer
- Seed content for the 3 MVP organs

## Out of scope

The 3D viewer (F04), the Explore page (F05), lessons, questions, AI. You serve data; you do
not render it.

## Dependencies / prerequisites

**F01 merged.** You need the route loader, seeder registry, `config/anatomy.php`, and the
`types.ts` DTO shapes you must mirror. F02's manifest supplies `model_path` — until it lands,
seed placeholder paths and swap them in one commit.

## Existing code to reuse

The upstream `app/lib/anatomy-data.ts` — **the data, not the code**. It contains 9 organs and
37 structures with stable slugs, Terminologia Anatomica terms, and authored pivot-space
coordinates. Migrate the values into your seeder. Its English prose
(`app/i18n/organs/en.ts`) is usable seed copy but is **AI-generated and unverified** —
fact-check before it becomes canonical (`docs/project-context.md` §5.4).

## Ownership boundaries

**You own** the four tables above, their models/factories/seeders, `AnatomyService`, the
anatomy controller and Resources, and `routes/features/anatomy.php`.

**You must not** touch `resources/js/anatomy/**` (F04), any assessment/lesson/AI table, or
`resources/js/anatomy/types.ts` (frozen — you *mirror* it, you do not change it).

## Required implementation

### Structure identity — the critical decision

The PRD's `model_object_name: Heart_LeftVentricle` (§7) **cannot be implemented.** Every
upstream GLB is a single mesh with one node named `tripo_node_<uuid>`; there is no
per-structure geometry (`docs/project-context.md` §2.2). Identity is therefore:

```
slug              left-ventricle            stable, kebab-case, unique per organ
ta_term           Ventriculus sinister      canonical anatomical identity, locale-independent
anchor_position   [0.70, -0.75, 0.65]       JSON, in FIT_SIZE = 3.8 pivot space  ← the working mechanism
model_object_name NULL                      reserved for per-structure meshes    ← nullable, unused
```

`ta_term` is the anchor RAG filters and the question bank key off. `model_object_name` stays
nullable so that if F02 lands per-structure models, the viewer gains mesh picking **with no
schema or API change**.

### Content

Seed **3 MVP organs: heart, lungs, brain.** Upstream ships 6/5/4 structures respectively —
too thin for a credible quiz. **Expand each to ~8** using the authoring tool (F04 ships the
viewer mode; you author the coordinates). Budget real time for this; it is content work, not
code.

Per structure: name, scientific name, description, function, location, difficulty (1–5),
related structures, marker colour, `is_published`.

### API

```
GET /api/v1/anatomy/organs           list + thumbnails
GET /api/v1/anatomy/organs/{organ}   OrganDto: model_path, accent, structures[]
GET /api/v1/anatomy/structures/{id}  full metadata + related structures
```

`OrganResource` must mirror `types.ts` exactly. Cache per `docs/architecture.md` §11
(`anatomy:organ:{slug}`, 24 h, cleared by model observers).

## DB changes

The four tables in `docs/architecture.md` §6. Real FK constraints, index on
`(organ_id, slug)` unique, `is_published` on structures so unverified content cannot reach
students.

## Tests

- Feature test per endpoint: happy path, 404, cache hit
- `anchor_position` round-trips as a 3-float array
- Structure resolution by slug **and** by TA term
- Seeder produces 3 organs with ≥ 8 published structures each
- No N+1 when listing organs with structures

## Acceptance criteria

1. `GET /organs/{organ}` returns a payload F04 can load without transformation.
2. Three organs, ~8 structures each, all with TA terms and anchor positions.
3. Cached response under 100 ms.
4. `model_object_name` is nullable and unused.

## Constraints and guardrails

- Eloquent only. No `DB::table`/`DB::raw` (`docs/engineering.md` §3).
- Eager load structures with organs — always.
- Do not change `types.ts`. If the shape is wrong, raise it; changing it breaks F04 in flight.
- Content accuracy is your call to escalate: upstream prose and Tripo models are both
  unverified. Do not silently promote either to canonical.

## Definition of Done

`docs/engineering.md` §11. Plus: post the final `OrganDto` JSON example in the PR — F04, F05,
and F07 code against it.

## Commit boundary

`feat(anatomy): schema and models` → `feat(anatomy): service and API resources` →
`feat(anatomy): seed heart, lungs, brain` → `test(anatomy): endpoint coverage`.
**Land the migration first** so downstream features can start.
