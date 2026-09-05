# Handover 13 — Admin & Content Management (delivered)

**Branch** `feat/f13-admin-content` · **Contract** [`13-admin-content.md`](../13-admin-content.md) · **Status** delivered

## What shipped

The whole `/admin` area, built entirely on other lanes' services and tables — this handover
creates and alters **no schema**. An admin can author hotspots by clicking the model, run every
content type through draft/published, approve AI-generated questions, upload knowledge
documents into 11's ingest pipeline, and read 10's analytics.

- Hotspot authoring: click the model → `author:point` → a named, TA-termed structure row, with
  the model version each anchor was authored against and a warning when the model has changed
- CRUD + publish/unpublish for organs, structures, lessons, questions, missions
- The AI review queue — `status = review` + `generated_by_ai` — and the only path to `published`
- Knowledge upload (file or pasted text) calling 11's `KnowledgeService`, with ingest status
- Read-only analytics over `learning_events` / `learning_mastery`, plus the review backlog
- The single sanctioned exposure of answer keys and mission target sequences, gated by policy

## Public surface

### Routes (`routes/features/admin.php`)

All inside `->middleware(['auth', 'admin'])->prefix('admin')->name('admin.')`. Order matters:
`EnsureUserIsAdmin` aborts **404** (not 403) so a prober learns nothing, and running it before
`auth` would 404 a guest who should be redirected to login.

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/admin` | `admin.index` | `AdminDashboardController` (invokable) |
| GET | `/admin/analytics` | `admin.analytics` | `AnalyticsAdminController` (invokable) |
| GET/POST | `/admin/organs`, `/organs/create` | `admin.organs.{index,create,store}` | `OrganAdminController` |
| GET/PUT/PATCH/DELETE | `/admin/organs/{organ}` (+`/edit`, `/status`) | `admin.organs.{edit,update,status,destroy}` | `OrganAdminController` |
| GET | `/admin/organs/{organ}/author` | `admin.authoring.show` | `HotspotAuthoringController@show` |
| POST | `/admin/organs/{organ}/structures` | `admin.structures.store` | `HotspotAuthoringController@store` |
| PUT/PATCH/DELETE | `/admin/structures/{structure}` (+`/anchor`, `/status`) | `admin.structures.{update,anchor,status,destroy}` | `HotspotAuthoringController` |
| — | `/admin/lessons…`, `/admin/missions…` | `admin.{lessons,missions}.{index,create,store,edit,update,status,destroy}` | `LessonAdminController`, `MissionAdminController` |
| GET | `/admin/questions/review` | `admin.questions.review` | `QuestionAdminController@review` |
| POST | `/admin/questions/{question}/publish` | `admin.questions.publish` | `QuestionAdminController@publish` |
| — | `/admin/questions…` | `admin.questions.{index,create,store,edit,update,status,destroy}` | `QuestionAdminController` |
| GET/POST/DELETE | `/admin/knowledge`, `/knowledge/{document}` (+`/reingest`) | `admin.knowledge.{index,store,reingest,destroy}` | `KnowledgeAdminController` |

Organs, lessons and missions bind by slug (`[a-z0-9-]+`); structures, questions and documents
bind by id (`[0-9]+`) — a structure slug is unique only within its organ. `questions/review` is
declared before `questions/{question}` so the literal segment is not swallowed.

### Authorization — how it is actually enforced

Two independent checks, and neither can borrow the other's coverage:

| Layer | Where | What it answers |
|---|---|---|
| `admin` middleware (`EnsureUserIsAdmin`, from 01) | the route group | "may you be in this area" — 404 otherwise |
| Policy | `Gate::authorize()` in the controller for **reads**; the FormRequest's own `authorize()` for **writes**; the Resource's own `$user->can()` for the **answer-key fields** | "may you do this to this record / see this field" |

`AdminRequest` (the base class) deliberately does **not** override `authorize()` to return
`true`, so a request class cannot be reused on a route whose authorization it never considered.
`AdminServiceProvider` maps all six policies explicitly rather than relying on Laravel 11's
convention discovery — "an explicit map turns [a rename] into a class that does not exist, which
is a fatal error at boot rather than a permission bug found in production."

| Policy | Abilities |
|---|---|
| `OrganPolicy` | viewAny, view, create, update, publish, delete |
| `AnatomicalStructurePolicy` | viewAny, view, create, update, **author**, publish, delete |
| `LessonPolicy` | viewAny, view, create, update, publish, delete |
| `QuestionPolicy` | viewAny, view, **viewAnswerKey**, create, update, **review**, publish, delete |
| `MissionPolicy` | viewAny, view, **viewTargetSequence**, create, update, publish, delete |
| `KnowledgeDocumentPolicy` | viewAny, view, create, update, **reingest**, delete |

**The test that exercises the policy independently of the middleware** is
`tests/Unit/Admin/PolicyTest.php` — no HTTP at all: "grants every admin ability to an admin",
"denies every admin ability to a student", "never shows a question answer key to a student",
"never shows a mission target sequence to a student", and "covers every public ability each
admin policy declares" (a reflection sweep, so a new ability cannot ship untested).

### Client contract / schema

Six `App\Http\Resources\Admin\*` Resources, mirrored in `resources/js/types/admin.ts` — where
the answer key on `AdminQuestion` and the target sequence on `AdminMission` are **optional
fields**. That is the contract: the Resource omits them when the policy denies, so a page must
handle absence rather than assume the admin area implies them.

**No schema.** This handover owns no tables and adds no migration; it administers 03, 06, 07, 09,
10 and 11's. The one new registration is `AdminServiceProvider`, appended to
`bootstrap/providers.php` (binds nothing, so its position is irrelevant).

## Key decisions

- **Middleware guards the area, a policy guards the record.** "The middleware is attached to a
  route group, so a route added to the wrong group loses the check silently; the policy is
  attached to the *resource*, so [it] cannot be written from a controller that forgot to
  authorize — `authorize()` throws rather than defaulting to allow."
- **The answer key is exposed in exactly one class, and that class asks the policy itself.**
  `AdminQuestionResource` calls `viewAnswerKey`; being rendered inside `/admin` is explicitly not
  sufficient. When denied, the key is **absent, not null** — "an explicitly null `correctOptionId`
  still tells a reader the field exists and invites a client to depend on it."
- **`viewAnswerKey` / `viewTargetSequence` are separate abilities**, not folded into `view()`, so
  the exception to invariant 4 is one grep away and a future listing has to name it to get it.
- **`review` is its own ability**, because approving generated content is the judgement PRD §24
  asks a human to make and it "should be visible in an authorization audit as its own line".
  `AssessmentService::publishQuestion()` is the only path to `published`, and an unpublishable
  question raises a `ValidationException` the reviewer can fix rather than a 500.
- **Authoring is nested under the organ** because a coordinate is meaningless without one —
  `FIT_SIZE` pivot space is per-model (invariant 5). The page ships `authoring.fitSize` and
  `authoring.currentModel`, and warns when a structure's recorded provenance no longer matches.
- **The authoring canvas is filled with `listAllStructures()`, not published ones** — authoring an
  unpublished marker is the point of the screen — and reuses `OrganResource` rather than a
  parallel shape the viewer would have to learn.
- **Uploads validate `extensions` *and* `mimetypes`** — "`extensions` trusts nothing the uploader
  named; `mimetypes` is what the bytes actually are" — with the allow-list and size cap read from
  `config/ai.php`, and file-or-text enforced as exactly one of the two.

## Invariants honoured

- **1 — no service reads the request.** `AdminRequest::administrator()` is the seam that turns the
  session into a typed `User`; `AnalyticsAdminController` resolves the clock and passes
  `CarbonImmutable` bounds in. `AnalyticsTest` ("caps the reporting window") covers the input side.
- **4 — the client never receives an answer key, except here, behind a policy.**
  `tests/Feature/Admin/AnswerKeyExposureTest.php` proves both directions: an admin gets the key
  and the mission target sequence, a student and a guest get **omission rather than null**, and
  "keeps the student-facing quiz free of the key even after an admin views it".
- **5 — `FIT_SIZE` 3.8.** `AnchorRules` bounds every coordinate to `fit_size × 1.5`;
  `HotspotAuthoringTest` ("rejects a coordinate authored outside FIT_SIZE pivot space").
- **6 — Eloquent only, `strict_types` everywhere.** No new tables, no raw SQL.
- **7 — thin controllers.** Every write goes through the owning lane's service
  (`AnatomyService`, `LessonService`, `AssessmentService`, `MissionService`, `KnowledgeService`);
  `$request->safe()` is used, never `$request->all()`.

## Tests

| File | What it proves |
|---|---|
| `tests/Unit/Admin/PolicyTest.php` | The policies, with no HTTP — including a reflection sweep asserting every public ability is covered |
| `tests/Feature/Admin/AuthorizationTest.php` | Every admin route hidden from a student and a guest, reachable by an admin, **and that the test's route table covers every registered admin route**; nav entry visibility; dashboard sections decided by policy, not by role |
| `tests/Feature/Admin/AnswerKeyExposureTest.php` | Invariant 4 per audience, plus no answer key in page props a student could reach |
| `tests/Feature/Admin/ReviewQueueTest.php` | A `review` question is never served to a student; only AI-generated questions are queued; publishing enters the pool; unpublishable questions refused; **publish is the only thing that can publish** |
| `tests/Feature/Admin/HotspotAuthoringTest.php` | `author:point` persists as a three-float array; organ taken from the URL not the payload; FIT_SIZE bounds; anchor provenance recorded, warned on model change, unverified when absent; draft structures visible; slug uniqueness within an organ |
| `tests/Feature/Admin/ContentPublishingTest.php` | Organs created as drafts regardless of the form; edits do not change status; publishing clears the cache; lesson/mission publish rules and slug validation |
| `tests/Feature/Admin/KnowledgeUploadTest.php` | Accepted uploads stored under a generated name and queued; rejected extension / MIME / size are **not stored**; text alternative; re-index bumps version; the private path never reaches a page prop |
| `tests/Feature/Admin/AnalyticsTest.php` | Window counting, zero-fill, per-learner dedupe, mastery averages, review backlog, window cap, and that no individual student is identified |

## Known gaps / follow-ups

- Analytics reads 10's `AnalyticsService`; this lane owns the controller and the page, not the
  aggregation. A change to the event schema lands in 10.
- No teacher/class management — Phase 2, per the contract's out-of-scope.
- Structure `delete` is available in the policy and the route; there is no soft-delete or undo, so
  a deleted hotspot takes its authored coordinates with it.
- The authoring screen depends on a model file being present to click on. While the licence gate
  is open (see 02, 14) `public/models/manifest.json` is `pending-licence` and no GLB ships, so
  authoring by clicking cannot actually be exercised in a deployed environment — the anchor
  endpoint and its validation are testable, the raycast is not.
