# Feature Plan

**Status:** Approved breakdown for the competition MVP
**Reads:** `PRD.md`, `docs/project-context.md` (audit), `docs/architecture.md` (target),
`docs/engineering.md` (rules)
**Codebase state at time of writing:** no application exists. Everything below is greenfield
except the 3D layer, which is adapted from the audited upstream repository.

**14 features, 7 waves.** Each is independently implementable, testable, reviewable, and
committable by one agent in one lane on one branch.

---

## 1. How the boundaries were drawn

A feature is a **vertical slice with one owner**: its own migrations, models, service,
API surface, UI, and tests. It is not a layer, not a file, and not a task.

Four rules produced this list:

1. **A boundary goes where a contract goes.** If two pieces of work can agree on an
   interface, an API Resource shape, or a table, they are separate features. If they
   would have to edit the same class to make progress, they are one.
2. **Own your tables.** The single biggest multi-agent hazard in Laravel is two agents
   writing migrations and models for overlapping tables. Every table has exactly one
   owning feature (§6). A foreign key into another feature's table *is* a dependency.
3. **Consolidate rather than split.** Progress, mastery, gamification, and analytics are
   one feature, not four — they share `learning_mastery`, the same job, and the same
   dashboard. Splitting them would create three agents editing one service.
4. **Parallelise features, not files.** Two agents never share a file. Where the framework
   forces a shared file (routes, seeders, service providers), §7 replaces it with a
   per-feature file plus a registry.

**What is *not* a feature:** anything cross-cutting that F01 establishes once (auth,
layout, error handling, test harness, CI), and anything deferred to Phase 2 (§9).

---

## 2. Feature list

| ID | Feature | Lane | Wave | Depends on |
|---|---|---|---|---|
| **F01** | Platform Foundation & Shared Contracts | Platform | 1 | — |
| **F02** | Anatomy Asset Pipeline & Licence Register | Content/Platform | 1 | — |
| **F03** | Anatomy Domain & API | Anatomy | 2 | F01 |
| **F04** | Viewer Core Library | Viewer | 2 | F01 |
| **F05** | Explore Experience | Anatomy + Viewer | 3 | F03, F04, F02 |
| **F06** | Lessons & Content Delivery | Learning | 4 | F05 |
| **F07** | Assessment Engine | Assessment | 4 | F04, F05 |
| **F08** | AI Tutor Core | AI | 4 | F01, F03 |
| **F09** | Missions | Assessment | 5 | F07 |
| **F10** | Progress, Mastery & Gamification | Progress | 5 | F06, F07 |
| **F11** | RAG & Knowledge Base | AI | 5 | F08 |
| **F12** | Simulations | Simulation | 6 | F04, F05, F08 |
| **F13** | Admin & Content Management | Admin | 6 | F03, F06, F07, F09, F11 |
| **F14** | Demo Journey & Competition Polish | All-hands | 7 | all |

---

## 3. Execution waves

```
WAVE 1   F01 Platform Foundation ──────────┐        F02 Asset Pipeline & Licence
         (blocks everything)               │        (zero code deps — start day one,
                                           │         it is the release gate)
                                           │
WAVE 2   ┌─────────────────────────────────┴──────────────┐
         F03 Anatomy Domain & API      F04 Viewer Core Library
         └──────────────────┬─────────────────────────────┘
                            │
WAVE 3              F05 Explore Experience
                    (first integration point)
                            │
WAVE 4   ┌──────────────────┼──────────────────┐
         F06 Lessons   F07 Assessment     F08 AI Tutor Core
         └──────┬───────────┴────────┬─────────┴──────┐
                │                    │                │
WAVE 5   F10 Progress &         F09 Missions      F11 RAG &
         Gamification                             Knowledge Base
                └────────────────────┬────────────────┘
                                     │
WAVE 6          F12 Simulations   ·   F13 Admin & Content
                                     │
WAVE 7          F14 Demo Journey & Competition Polish
```

**Peak parallelism is 3 agents** (waves 2, 4, 5). More than that and the integration
surface outgrows the review capacity — features start blocking on each other's contracts
faster than they finish.

**Wave 1 is the only true bottleneck.** F01 must land before anything else starts, because
it establishes the contracts, the schema conventions, and the verification pipeline that
every other feature is measured against. Budget it generously; rushing it costs more later
than it saves.

**F02 runs beside F01 with no code dependency**, and it must start immediately: if the
licence answer is "no grant" (`project-context.md` §3), the fallback is weeks of work
(reimplement the viewer, source BodyParts3D/Z-Anatomy models) and needs to be discovered in
week one, not week six. F02 is the release gate for F14.

---

## 4. Feature detail

Each entry states what the feature **owns** (files it may create and change), what it
**publishes** (the contract other features consume), what it must **not touch**, and its
**done** condition. Ownership is exclusive: if a file is not in your Owns list, you do not
edit it.

---

### F01 — Platform Foundation & Shared Contracts
**Lane:** Platform · **Wave:** 1 · **Depends:** — · **Blocks:** everything

**Owns**
- Laravel 11 skeleton, `composer.json`, `package.json`, `.env.example`
- Inertia 2 + Vue 3 + Vite + Tailwind 4 bootstrap; `resources/js/app.ts`, base layout, nav registry
- Auth: `users` migration/model (role, education_level, difficulty_preference, xp, level),
  register/login/logout, `admin` middleware, base policy wiring
- `app/Contracts/**` — `AIProviderInterface`, `VectorStoreInterface`, and the DTOs
  (`AIResponse`, `RetrievedChunk`, `LearningContext`, `SimulationState`)
- `resources/js/anatomy/types.ts` — `OrganDto`, `StructureDto`, `ViewerEvent` (types only)
- `config/anatomy.php` with `FIT_SIZE = 3.8`; `config/ai.php` skeleton
- Route loader (§7.1), `DatabaseSeeder` registry (§7.2), base `AppServiceProvider`
- Exception handler — no provider error ever reaches a student (`architecture.md` §14)
- Test harness: Pest, Vitest, Playwright, factories base, `NullProvider`, `NullVectorStore`
- Pint, PHPStan level 6, CI workflow, `/verify` pipeline

**Publishes:** the contracts and conventions in `architecture.md` §4. Once merged, `app/Contracts/**`,
`types.ts`, and `FIT_SIZE` are **frozen** — changes need human approval (`engineering.md` §7).

**Must not:** implement any domain behaviour. No organs, no lessons, no AI calls.

**Done:** `/verify` green on the empty app. A user registers, logs in, sees an empty
dashboard. The FIT_SIZE parity test (PHP config == TS constant) passes.

---

### F02 — Anatomy Asset Pipeline & Licence Register
**Lane:** Content/Platform · **Wave:** 1 · **Depends:** — · **Blocks:** F05 (real models), F14 (release)

**Owns**
- `docs/asset-register.md` — one row per model/texture/illustration: source, creator,
  licence, modification, redistribution, commercial use, attribution
- Licence correspondence with `thebuggeddev`, and the recorded decision
- `scripts/encode-model.*` — decimate → `EXT_meshopt_compression` → KTX2/Basis textures →
  verify normalisation to `FIT_SIZE = 3.8`
- `public/models/**` (or the S3 bucket) and the per-organ model manifest
- Deleting the 1.6 MB of unreferenced `public/draco/` + `public/basis/` payload

**Publishes:** licensed, budget-compliant models — **< 2 MB and < 150k triangles each**
(upstream is 2.0–5.8 MB, 308–387k tris) — plus the manifest F03 seeds `organs.model_path` from.

**Must not:** touch application code.

**Done:** every shipped model meets budget, is normalised, and has an asset-register row
with a licence that permits public deployment. The grant-vs-replace decision is recorded.
**If no grant arrives, this feature owns escalating that** — it changes F04's scope.

---

### F03 — Anatomy Domain & API
**Lane:** Anatomy · **Wave:** 2 · **Depends:** F01 · **Blocks:** F05, F07, F08, F12, F13

**Owns**
- Migrations/models/factories: `body_systems`, `organs`, `anatomical_structures`,
  `structure_relations`
- `AnatomyService`, `Api/V1/AnatomyController`, `OrganResource`, `StructureResource`
- Anatomy cache layer (`architecture.md` §11)
- Seeder: **3 MVP organs — heart (6 structures), lungs (5), brain (4)** — expanded to
  ~8 structures each using the authoring tool. Content is migrated from the upstream
  English prose and **fact-checked before it becomes canonical** (`project-context.md` §5.4)

**Publishes — the most-consumed contract in the project:**
```
GET /api/v1/anatomy/organs            → list + thumbnails
GET /api/v1/anatomy/organs/{organ}    → OrganDto: model_path, accent, structures[]
GET /api/v1/anatomy/structures/{id}   → full metadata + related structures
```
Structure identity is `slug` + `ta_term` + `anchor_position` (`[x,y,z]` in FIT_SIZE pivot
space). `model_object_name` stays **nullable and unused** — reserved for per-structure meshes.

**Must not:** touch `resources/js/anatomy/**`, assessment, AI, or lessons.

**Done:** three organs seeded and served; structure resolution by slug and TA term; cache
hit under 100 ms; feature tests on every endpoint.

---

### F04 — Viewer Core Library
**Lane:** Viewer · **Wave:** 2 · **Depends:** F01 (`types.ts`) · **Blocks:** F05, F07, F09, F12

**Owns**
- `resources/js/anatomy/**` — `AnatomyViewer.ts`, `HotspotLayer.ts`, `AssetManager.ts`,
  `dispose.ts`
- `resources/js/composables/useAnatomyViewer.ts` — the only Vue↔viewer bridge
- Vitest suite for the library

**Publishes:** the interface in `architecture.md` §5.2 — `loadOrgan`, `selectStructure`,
`getSelectedStructure`, `highlightStructure`, `focusStructure`, `isolateStructure`,
`flashStructure`, `resetView`, `zoom`, `setAutoRotate`, `setLayer`, `setCrossSection`,
`setMode`, `triggerAnimation`, `applySimulationState`, and the typed event emitter.

**Adapted from the audit** (`project-context.md` §5.1): reuse hotspot snapping, occlusion
fade, screen-space picking, the LRU asset manager, and the render-on-demand loop. Add: pan
(upstream disables it), programmatic selection, camera fly-to, `prefers-reduced-motion`,
a WebGL-unavailable event, and the typed emitter replacing the callback bag.

**Reduced semantics are the contract, not a bug** (`architecture.md` §5.3). The models are
single-mesh, so `isolateStructure` dims other markers and flies the camera rather than
hiding geometry; `setLayer('wireframe')` is wireframe; `triggerAnimation` is scripted camera
choreography because **no GLTF animation clips exist**. Emit `capability:degraded` — never
fail silently.

**Must not:** import Vue/Inertia, call `fetch`, or touch anything in `app/`.

**Done:** every interface method implemented; disposal test returns renderer/geometry/texture
counts to zero; structure-id round-trip test; degraded-capability events fire.

---

### F05 — Explore Experience
**Lane:** Anatomy + Viewer · **Wave:** 3 · **Depends:** F03, F04, F02 · **Blocks:** F06, F07, F12

The first integration point, and the first thing a judge sees.

**Owns:** `resources/js/Pages/Explore.vue`, organ library panel, structure callout, info
panel, tool rail, screen-reader structure index, `Web/ExploreController`.

**Publishes:** the page shell and the mounting pattern that F06/F07/F09/F12 embed the viewer
into. **Getting this pattern right matters more than the pixels** — four later features copy it.

**Must not:** own quiz, lesson, or AI logic. It renders them; it does not implement them.

**Done:** PRD §44 steps 2–5 — open an organ, rotate/zoom/pan, select a structure, read its
explanation. Text fallback works with WebGL disabled. Keyboard navigable.

---

### F06 — Lessons & Content Delivery
**Lane:** Learning · **Wave:** 4 · **Depends:** F05

**Owns:** `lessons`, `lesson_progress` migrations/models; `LessonService`; lesson API +
Inertia pages; step sequencing (objective → exploration → explanation → activity →
knowledge check → reflection); completion.
**Publishes:** `lesson_progress` rows for F10; the lesson context F08 reads.
**Done:** 5–10 lessons across the 3 MVP organs; a student completes one end to end.

---

### F07 — Assessment Engine
**Lane:** Assessment · **Wave:** 4 · **Depends:** F04, F05 · **Blocks:** F09, F10

The flagship differentiator (PRD §12).

**Owns:** `questions`, `question_options`, `attempts` migrations/models; `AssessmentService`;
`Api/V1/QuizController`; **answer-stripping API Resources**; MCQ + 3D spatial quiz UI;
feedback and explanation display.

**Publishes:** the `attempts` table and its shape — F09 and F10 both consume it. **Land this
migration in your first commit** so F10 can start against it.

**Critical rule:** the client never receives `is_correct`, `correct_structure_id`, or
`correct_option_id`. Validation is server-side, always. Reproduce the audited UX — a miss
flashes the correct structure green, feedback is positioned away from the dot it points at,
a wrong answer dwells longer.

**Done:** spatial and MCQ attempts validated server-side and persisted; **a feature test
asserts no answer key appears in any payload**; `/boundary-audit` clean.

---

### F08 — AI Tutor Core
**Lane:** AI · **Wave:** 4 · **Depends:** F01, F03 · **Blocks:** F11, F12

**Owns:** `conversations`, `conversation_messages`; `AITutorService`, `AIContextBuilder`,
`AIResponseValidator`, `PromptBuilder`; `app/Infrastructure/AI/**` (Anthropic default,
OpenAI, Null); `/api/v1/ai/tutor/{ask,explain,hint}`; tutor panel UI; rate limits and
throttling.

**Publishes:** `AIProviderInterface` bound and working — F11 uses it for embeddings.

**Ships useful without RAG.** Answers are grounded in curated structure metadata; F11 adds
retrieval and citations. Build the "no relevant sources — saying so" path now, not later.

**Done:** a contextual answer that knows the selected structure and the student's level;
validator enforces educational scope and refuses clinical framing; no test hits a real API.

---

### F09 — Missions
**Lane:** Assessment · **Wave:** 5 · **Depends:** F07, F04

**Owns:** `missions`, `mission_attempts`; `MissionService`; sequence/pathway validation;
mission UI and viewer choreography.
**Critical rule:** the client receives prompts and hints, **never the target sequence**.
`MissionService` scores per step (correct / correct-after-hint / wrong).
**Done:** "Trace the Blood" runs end to end with per-step feedback.

---

### F10 — Progress, Mastery & Gamification
**Lane:** Progress · **Wave:** 5 · **Depends:** F06, F07

Consolidated deliberately — mastery, recommendations, gamification, and analytics share one
table, one job, and one dashboard.

**Owns:** `learning_mastery`, `learning_events`, `achievements`, `user_achievements`;
`MasteryCalculator` (pure, table-driven tests); `ProgressService`; `RecommendationService`;
`RecalculateMastery` job; `POST /api/v1/events` batching; progress dashboard; XP/levels/badges.

**Formula is fixed in `architecture.md` §10** — Laplace-smoothed accuracy × recency decay ×
hint penalty × coverage. Deterministic and pure: **the LLM never computes a score**, and
recommendation reasons are templated from the mastery breakdown, not generated.

**Done:** mastery changes visibly after an attempt; per-system rollup; a recommendation with
a reason; recalculation happens in a queued job, never in the request.

---

### F11 — RAG & Knowledge Base
**Lane:** AI · **Wave:** 5 · **Depends:** F08

**Owns:** `knowledge_documents`, `knowledge_chunks`; `KnowledgeService`, `ChunkingService`,
`EmbeddingService`, `RetrievalService`; `app/Infrastructure/VectorStore/**` (MySql default,
Pinecone, Null); `ProcessKnowledgeDocument`, `GenerateEmbeddings`, `SyncVectorStore` jobs;
source attribution in tutor answers.

**`MySqlVectorStore` is the default** — filters in SQL, cosine in PHP. At MVP corpus size
this is sub-50 ms and needs no extension. Pinecone proves the seam; it is not the plan.
`search()` takes a **vector**, not a string (`architecture.md` §8.1).

**Done:** a document ingests through the queue; a tutor answer cites a real source; both
store implementations pass one shared contract test; nothing runs in a web request.

---

### F12 — Simulations
**Lane:** Simulation · **Wave:** 6 · **Depends:** F04, F05, F08

**Owns:** `simulations`, `simulation_sessions`; `SimulationService`; the deterministic state
engine; the `visual_directives` vocabulary; simulation UI.

**Deterministic state, AI explanation.** The engine is a JSON config and a `match` — no state
machine library. `visual_directives` is a **closed vocabulary chosen because a single-mesh
model can actually perform it**: `highlight`, `tint`, `pulse_rate`, `focus`, `cross_section`.

**Done:** "what happens if the mitral valve does not close" runs deterministically, drives the
viewer, and is explained — labelled educational, never diagnostic.

---

### F13 — Admin & Content Management
**Lane:** Admin · **Wave:** 6 · **Depends:** F03, F06, F07, F09, F11

**Owns:** admin CRUD for organs, structures, lessons, questions, missions, knowledge
documents; the **AI-generated question review queue** (`status = 'review'` cannot reach
students); the **hotspot authoring UI** wrapping the viewer's author mode — click the model,
capture the pivot-space coordinate, write it to the database (replacing the audited
clipboard-and-paste flow); basic usage analytics.

**Done:** an admin adds a structure by clicking the model; publishes a lesson; approves an
AI-generated question. Every admin route is behind middleware **and** a policy.

---

### F14 — Demo Journey & Competition Polish
**Lane:** All-hands · **Wave:** 7 · **Depends:** everything

**Owns:** onboarding, the demo dataset seeder, visual design pass, loading and error states,
accessibility audit (WCAG 2.2, `prefers-reduced-motion`, keyboard paths), performance
verification against `architecture.md` §15.1, and the **single Playwright test walking PRD §44**.

**Gate:** F02's licence decision must be resolved. Nothing ships publicly without it.

**Done:** the §44 journey passes on staging; a judge understands the product in 3–5 minutes
with no technical explanation.

---

## 5. Contract-first rule

**A feature's first commit is its contract.** Before implementation, publish the interface,
the API Resource shape, or the migration that downstream features consume, and announce it.

This is what makes waves overlap safely. F10 can start against F07's `attempts` migration
the day it lands, without waiting for the quiz UI. F11 can code against `AIProviderInterface`
before F08's tutor is finished.

**Corollary:** if you need something a parallel feature has not published yet, stub it behind
its interface and keep moving. Never reach into another lane's half-finished code, and never
block waiting for it.

---

## 6. Table ownership

Every table has exactly one owning feature. Creating, altering, or seeding a table you do not
own is a scope violation. A foreign key into another feature's table is a **dependency** —
declare it and order the wave accordingly.

| Feature | Tables |
|---|---|
| F01 | `users`, `sessions`, `jobs`, `cache`, `failed_jobs` |
| F03 | `body_systems`, `organs`, `anatomical_structures`, `structure_relations` |
| F06 | `lessons`, `lesson_progress` |
| F07 | `questions`, `question_options`, `attempts` |
| F08 | `conversations`, `conversation_messages` |
| F09 | `missions`, `mission_attempts` |
| F10 | `learning_mastery`, `learning_events`, `achievements`, `user_achievements` |
| F11 | `knowledge_documents`, `knowledge_chunks` |
| F12 | `simulations`, `simulation_sessions` |

---

## 7. Conflict hotspots and their rules

Laravel funnels many features into a few shared files. Each is replaced with a per-feature
file plus a registry, so two agents never edit one file.

### 7.1 Routes — **highest conflict risk**
`routes/web.php` and `routes/api.php` are **owned by F01 and never edited again.** Each
feature creates `routes/features/<feature>.php`; F01's loader globs the directory.

### 7.2 Seeders
`DatabaseSeeder.php` is an append-only registry owned by F01. Each feature writes its own
`<Feature>Seeder` class and appends exactly one `$this->call()` line.

### 7.3 Service providers
`AppServiceProvider` is F01's. Features needing bindings (F08 AI, F11 VectorStore) register
their own provider — `AiServiceProvider`, `RagServiceProvider`.

### 7.4 Navigation and layout
The base layout is F01's. Features add nav entries through `config/navigation.php`, never by
editing the layout component.

### 7.5 Frozen after F01
`app/Contracts/**`, `resources/js/anatomy/types.ts`, `config/anatomy.php` (`FIT_SIZE`).
Changing any of these breaks other agents' work in flight. Human approval required; every
consumer updated in the same commit.

### 7.6 Append-only
`.env.example` — add your keys at the end, never reorder.

### 7.7 Approval required
`composer.json`, `package.json` — a named reason, a licence check, and confirmation that
nothing installed already does the job (`engineering.md` §5).

### 7.8 The two-way contract
`resources/js/anatomy/types.ts` (F04) **mirrors** F03's API Resources. Changing either
without the other is a silent runtime break — PHP and Vue both fail quietly. Change both in
one commit, or neither.

---

## 8. Ownership and branching

- One feature, one lane, one branch: `feat/<feature-id>-<short-desc>` — e.g.
  `feat/f07-assessment-engine`. Never commit to `main`.
- The lane map in `engineering.md` §7 governs. If a task needs a file outside your Owns list,
  say so before you start — do not discover it halfway.
- A feature is done only when `/verify` and `/boundary-audit` pass and the §11 Definition of
  Done in `engineering.md` is fully met.
- **Rule 0 applies at feature boundaries too:** renaming anything another feature consumes
  means grepping `app/ resources/ routes/ database/ config/ tests/` and updating every hit.
  Named routes and `$request->input('...')` string keys cannot be found automatically —
  check them by hand and say which files you checked.

---

## 9. Deferred — no code, no schema, no config

Voice interaction, multi-language (the upstream 12-locale system is deliberately dropped —
`project-context.md` §5.2), teacher and class management, leaderboards, AR/VR, mobile app,
WebGPU, automated AI question generation beyond the review queue, and any additional vector
store beyond the one adapter proving the seam.

These are Phase 2+. Building any of them before F14 passes is scope creep.

---

## 10. Risks that change this plan

| Risk | Impact | Owner | Trigger |
|---|---|---|---|
| **No licence grant** (`project-context.md` §3) | F04 becomes reimplement-from-scratch; F02 sources BodyParts3D/Z-Anatomy | F02 | Week 1 — escalate immediately |
| Replacement models have per-structure meshes | **Good news.** F04's degraded semantics become full; no schema or API change needed | F04 | On F02 decision |
| 3–6 hotspots per organ too thin for a credible quiz | F03 authoring content grows; F07 slips | F03 | Wave 2 |
| Upstream prose is inaccurate | F03 content rework; F11 index poisoned | F03 | Wave 2, before RAG |
| LLM budget for the demo | F08/F11 scope | F08 | Wave 4 |

The architecture is interface-first precisely so the first two risks land as **scope changes
inside F02 and F04**, not as a re-architecture of the platform.
