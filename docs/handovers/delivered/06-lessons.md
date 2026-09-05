# Handover 06 — Lessons & Content Delivery (delivered)

**Branch** `feat/f06-lessons` · **Contract** [`06-lessons.md`](../06-lessons.md) · **Status** delivered

## What shipped

Structured lessons built from an ordered JSON step list, rendered by a data-driven step
renderer, with per-student progress written server-side from a step index. The lesson page
embeds the viewer using handover 05's mounting pattern unchanged.

- Lesson library at `/lessons`, filterable by organ, body system and difficulty
- Lesson page at `/lessons/{slug}`: one step at a time, resume where you left off, complete
- Six step components — objective, exploration, explanation, activity, knowledge check, reflection
- Progress and completion API; completion is idempotent and keeps the original `completed_at`
- `lesson:{slug}` cached for 1 h, invalidated by `LessonObserver` on save and delete
- Seven seeded lessons across heart, lungs and brain, including `blood-circulation` (14's demo)

## Public surface

### Routes (`routes/features/lessons.php`)

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/lessons` | `lessons.index` | `Web\LessonController@index` |
| GET | `/lessons/{lesson}` | `lessons.show` | `Web\LessonController@show` |
| GET | `/api/v1/lessons` | `api.v1.lessons.index` | `Api\V1\LessonController@index` |
| GET | `/api/v1/lessons/{lesson}` | `api.v1.lessons.show` | `Api\V1\LessonController@show` |
| POST | `/api/v1/lessons/{lesson}/progress` | `api.v1.lessons.progress` | `Api\V1\LessonController@progress` |
| POST | `/api/v1/lessons/{lesson}/complete` | `api.v1.lessons.complete` | `Api\V1\LessonController@complete` |

All `auth`; the API group adds `throttle:api`. `{lesson}` is a slug constrained to `[a-z0-9-]+`.
The `progress` endpoint is a fourth beyond the contract's three — see Key decisions.

### Services / DTOs / enums

| Class | Responsibility |
|---|---|
| `Services\Lessons\LessonService` | Published lesson reads, the `lesson:{slug}` cache, and one student's progress rows |
| `Services\Lessons\LessonFilters` | The three optional list filters as one typed value |
| `Enums\LessonStatus` | `draft` \| `published` |
| `Enums\LessonProgressStatus` | `in_progress` \| `completed` (no `not_started` — no row says it) |
| `Enums\LessonStepType` | `objective`, `exploration`, `explanation`, `activity`, `knowledge_check`, `reflection` |
| `Observers\LessonObserver` | Clears `lesson:{slug}` on `saved` and `deleted` |

`LessonService` public methods: `listPublished`, `filterOptions`, `findPublishedBySlug`,
`countSteps`, `progressFor`, `progressForLessons`, `recordStepProgress`, `complete`,
`forgetLesson`. (`paginateAll`, `findAnyBySlug`, `create`, `update`, `setStatus`, `delete` were
added later by **13** and are marked as such in the file.)

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `lessons` | `organ_id`, `slug`, `title`, `description`, `objective`, `difficulty`, `estimated_minutes`, `content` (JSON), `status` | `slug` unique; `organ_id` FK `restrictOnDelete`; index `(status, organ_id)`, `(status, difficulty)` |
| `lesson_progress` | `user_id`, `lesson_id`, `status`, `progress_percent` (unsigned tinyint, 0–100), `completed_at` | unique `(user_id, lesson_id)`; both FKs cascade on delete; index `(user_id, updated_at)` |

No `body_system_id` on `lessons` — the system is reached via `organ.body_system_id`.

### Client contract (`resources/js/types/lessons.ts`)

`LessonDto` (with `steps`, `organ: OrganDto`, `progress`), `LessonCard`, `LessonProgressDto`
(`status`, `progressPercent`, `completedAt`), and one payload interface per step type
(`ObjectivePayload`, `ExplorationPayload`, `ExplanationPayload`, `ActivityPayload`,
`KnowledgeCheckPayload`, `ReflectionPayload`). Every step carries a server-authored `index`.
`KnowledgeCheckPayload` is `{ prompt, reference }` — a reference, never an answer.

## Key decisions

- **`content` is a JSON column, not a `lesson_steps` table**, because nothing queries, filters or
  joins a step: a step is only ever read as part of the lesson that owns it, and its shape differs
  per type.
- **`LessonResource::steps()` reads steps back through `LessonStepType` and drops anything
  unrecognised**, re-numbering `index` contiguously — a type with no component would otherwise
  render nothing silently in the middle of a lesson.
- **A fourth endpoint, `POST .../progress`, was added.** `progress_percent` is only meaningful if
  something records the steps in between, which acceptance criterion 2 (progress survives a
  refresh) needs.
- **The client sends a step index, never a percentage.** `LessonService::percentForStep()` derives
  the percentage from the lesson's own step count, so a client cannot claim 100% on step one.
- **`difficulty` reuses `DifficultyPreference`'s vocabulary** rather than a near-identical
  `LessonDifficulty`, because 10 matches lessons against `users.difficulty_preference` and two
  vocabularies would need a mapping table between three identical values.
- **The lesson library query is not cached** — three optional filters make eight key shapes before
  values, and the query is one indexed read; only `lesson:{slug}` is cached.
- **A published lesson on a draft organ is withheld** (`whereRelation('organ', 'status', Published)`),
  because it would put a draft organ's model URL in front of a student.

## Invariants honoured

- **1 — services never read the request.** `LessonService` takes `User $user` on every write.
  `LessonProgressTest` → *"cannot be told whose progress to write"* and *"scopes progress to the
  authenticated student and to nobody else"*.
- **4 — no answer key.** A `knowledge_check` step payload carries `prompt` + `reference` only.
  `LessonEndpointsTest` → *"carries no answer key"*, `LessonPageTest` → *"carries no answer key"*,
  `LessonSeederTest` → *"never puts an answer in a knowledge-check step"*.
- **6 — Eloquent only, `declare(strict_types=1)` throughout.** No raw SQL in the lane.
- **7 — thin controllers.** Both controllers validate through a `Lessons\*Request`, call
  `LessonService`, return a Resource; `$request->all()` never leaves a FormRequest.
- **8 — templates display only.** `StepRenderer.vue` looks the type up in a `Record<LessonStepType,
  Component>` map; `useLessonProgress.ts` is the page's only route to the server.
  `StepRenderer.test.ts` → "the step table".
- Viewer disposal / mounting (05's pattern): `Pages/Lessons/Show.test.ts` → "the viewer mount".

## Tests

| File / dir | Proves |
|---|---|
| `tests/Feature/Lessons/LessonEndpointsTest.php` | Auth gate; published-only listing incl. draft-organ withholding; the three filters and their validation; library omits `content`; show returns steps + organ; server-authored step indices; unknown step types dropped; 404s; no answer key |
| `tests/Feature/Lessons/LessonProgressTest.php` (16 tests) | Percentage derived server-side and a client-sent one ignored; clamping and negative-index rejection; progress never moves backwards; idempotent completion keeping the original timestamp; a completed lesson is not reopened; per-student scoping in both directions; 404 rather than progress on an unpublished lesson; zero steps → 0%; 201 on create / 200 on update |
| `tests/Feature/Lessons/LessonPageTest.php` | Library and show Inertia props, active filters echoed back, unwrapped `OrganDto` inside the lesson, own progress rendered, 404s, no answer key, query count flat as the library grows |
| `tests/Feature/Lessons/LessonCacheTest.php` | `lesson:{slug}` written; misses not cached; cleared on edit, on slug change (old key too) and on delete; progress never cached where another student can read it |
| `tests/Feature/Lessons/LessonSeederTest.php` | 5–10 published lessons; `blood-circulation` exists with the full PRD §8 shape and the named blood-flow path; all three MVP organs covered; every structure slug resolves on its own organ; no answers in knowledge checks; idempotent |
| `resources/js/Components/Lessons/StepRenderer.test.ts` | Type→component table, viewer-driving steps, the knowledge-check seat |
| `resources/js/Pages/Lessons/Show.test.ts` | Viewer mounted once and disposed, the lesson reads with no 3D, progress posting |

## Known gaps / follow-ups

- **Knowledge-check steps are a seat, not a question.** The payload carries a `reference` string;
  binding it to a `questions` row is **07**'s (`questions.lesson_id` is the nullable, unconstrained
  column that exists for it). Nothing in this lane grades anything.
- **Mastery is not computed here.** `lesson_progress` rows are the output; **10** reads them and
  ages mastery against `completed_at`, which is why completion never refreshes that timestamp.
- Seven lessons seeded (`blood-circulation`, `chambers-and-valves`, `heart-wall-and-pressure`,
  `airway-to-the-lungs`, `lobes-of-the-lungs`, `lobes-of-the-cerebrum`,
  `brainstem-and-cerebellum`) — inside the contract's 5–10, but only two per lung/brain organ.
- Admin CRUD on lessons was deliberately out of scope and arrived later, in **13**, appended to
  `LessonService` under its own banner comment.
