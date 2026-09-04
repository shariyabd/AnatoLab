# Handover 13 — Admin & Content Management

**Feature:** F13 · **Lane:** Admin · **Wave:** 6 · **Branch:** `feat/f13-admin-content`

> Read first: PRD §19, §24, §41 · `docs/architecture.md` §14 · `docs/project-context.md` §2.4

## Objective

Give an admin the tools to manage every content type — including a 3D hotspot authoring tool
that replaces hand-editing coordinates, and a review queue so AI-generated questions cannot
reach students unreviewed.

## Scope

- Admin CRUD: organs, structures, lessons, questions, missions, knowledge documents
- AI question review queue (`status = review` → `published`)
- **Hotspot authoring UI** wrapping the viewer's author mode
- Basic usage analytics view

## Out of scope

Creating or altering any domain table — you administer tables other features own. Teacher and
class management (Phase 2). Document *processing* (F11's jobs; you call the service).

## Dependencies / prerequisites

**F03, F06, F07, F09, F11 merged** — you administer all of them. F04 supplies
`setMode('author')` and the `author:point` event.

## Existing code to reuse

The upstream authoring mode (`docs/project-context.md` §2.4): `?authoring=1` sets a crosshair
cursor, raycasts the mesh on click, and reports the hit in pivot space. F04 ports this as
`setMode('author')` + `author:point`.

**What changes:** upstream gated it behind a query string and output a copyable code literal.
Yours is gated behind admin authorization and **writes to the database**.

## Ownership boundaries

**You own** `app/Http/Controllers/Admin/**`, admin Inertia pages, `routes/features/admin.php`,
admin policies.

**You must not** create or alter tables, or modify another feature's service. If an admin
action needs a service method that does not exist, ask that feature's owner. Use existing
services; do not reach past them into models.

## Required implementation

### Authorization — the rule

Every admin route sits behind the `admin` middleware **and** a policy check.
**Middleware alone is not authorization** (`docs/engineering.md` §10).

### Hotspot authoring

The highest-value tool here. Admin opens an organ → clicks a point on the model → the viewer
emits `author:point` with pivot-space coordinates → the admin names the structure, supplies
its TA term and metadata → saved to `anatomical_structures.anchor_position`.

This is how F03's content grows from 4–6 structures per organ to ~8, and how new organs are
added later without a developer.

**Coordinates are only meaningful in `FIT_SIZE = 3.8` space.** Show the admin which model
version a coordinate was authored against, and warn if the model changes.

### Review queue

Questions with `status = 'review'` and `generated_by_ai = true` are listed for approval.
Publishing sets `status = 'published'`. Nothing else can publish a question. This is the
control PRD §24 requires before AI content becomes canonical curriculum.

### Content management

Standard CRUD through each feature's existing service. Publish/unpublish for organs,
structures, lessons, questions, missions. Knowledge document upload calls F11's
`KnowledgeService` and shows ingestion status (pending → processing → indexed | failed).

### Analytics view

Read `learning_events` and `learning_mastery` (F10). Aggregate counts and outcome trends —
**read-only**, no new tables.

## Tests

- Every admin route: a student gets 403; an admin gets 200
- Policy enforced independently of middleware (test the policy directly)
- Authoring: an `author:point` payload persists to `anchor_position` as a 3-float array
- A `review` question is never returned by the student-facing quiz API
- Publishing changes status and makes the question servable
- Upload validation: MIME, extension, size; a rejected file is not stored

## Acceptance criteria

1. An admin adds a structure by clicking the model, and it appears in the viewer.
2. An admin publishes a lesson and it becomes visible to students.
3. An admin approves an AI-generated question and it enters the pool.
4. A knowledge document uploads and reaches `indexed`.
5. No admin capability is reachable by a student, verified by test.

## Constraints and guardrails

- Never bypass a feature's service to write its tables directly.
- Uploads: outside the web root, generated names, queued processing.
- Do not build teacher/class management — Phase 2.

## Definition of Done

`docs/engineering.md` §11, with particular attention to authorization coverage.

## Commit boundary

`feat(admin): layout, policies and authorization` → `feat(admin): organ and structure CRUD` →
`feat(admin): hotspot authoring tool` → `feat(admin): lesson, question and mission CRUD` →
`feat(admin): AI question review queue` → `feat(admin): knowledge documents and analytics` →
`test(admin): authorization coverage`.
