# Target Architecture

**Status:** Approved target for the competition MVP
**Depends on:** `docs/project-context.md` (Phase 0 audit). Read that first — this
document assumes its findings, especially §2.2 (single-mesh models) and §3 (licensing).

---

## 1. Principles

1. **One Laravel application.** Not microservices, not a separate SPA backend, not an
   event bus. A monolith with clear internal seams is the right size for this product
   and this timeline.
2. **The 3D layer owns the scene; the backend owns the truth.** Laravel never touches
   Three.js state. Three.js never decides whether an answer is correct.
3. **Structure identity is the contract.** One stable structure id is used by the
   viewer, the question bank, the RAG filters, the analytics events, and the AI prompt.
   Everything else is negotiable.
4. **Abstract exactly two things: the AI provider and the vector store.** Both have a
   real, named second implementation. Nothing else gets an interface until it has one.
5. **Fat services, thin controllers, no Blade/Vue logic.** Controllers validate and
   delegate. Templates display.
6. **Deterministic where it can be, AI where it must be.** Scoring, mastery, and
   simulation state are plain code. The AI explains results; it never produces them.
7. **Build what the demo needs.** Every module below traces to a Definition-of-Done
   item in PRD §44. Anything that does not is deferred to §16.

---

## 2. Technology stack

```
Frontend        Vue 3 (Composition API, <script setup>) + Inertia.js 2 + Vite 5
3D              Three.js (pinned ^0.185) + GSAP — vanilla ES modules, no Vue wrapper lib
Styling         Tailwind CSS 4
Backend         Laravel 11 (PHP 8.3, declare(strict_types=1))
Database        MySQL 8.0
Cache/Queue     Redis (cache, sessions, queue) + Laravel Horizon
Vector store    MySQL (default) — Pinecone adapter behind the same interface
LLM             Anthropic Claude (default) — OpenAI adapter behind the same interface
Storage         Local disk in dev, S3-compatible in production (models, textures, uploads)
Testing         Pest (PHP), Vitest (viewer unit), Playwright (one smoke journey)
Static analysis PHPStan level 6, Laravel Pint
```

**Why Inertia + Vue rather than the PRD §20 "React / Next.js".** The PRD header
specifies "Laravel + MySQL + Inertia + Vue js"; §20's stack block is a leftover from
describing the *existing* repo. The header wins. Inertia removes an entire class of
work — no token auth, no CORS, no client-side router, no duplicated validation — and
the one genuinely stateful part of the UI (the viewer) is imperative vanilla Three.js
either way, so the framework choice barely touches it.

**Why MySQL as the default vector store.** The MVP corpus is a few thousand chunks.
After filtering by `organ_id` / `structure_id` / `education_level`, a similarity query
scans tens to low hundreds of rows — well inside a single fast PHP loop. Pinecone adds
an external dependency, a failure mode, and credentials for no measurable gain at this
size. It stays behind `VectorStoreInterface` so it can be demonstrated on request or
swapped if the corpus grows, satisfying PRD §43's "Pinecone (optional) / MySQL native".

---

## 3. System shape

```
                        Browser
   ┌───────────────────────┴───────────────────────┐
   │  Inertia + Vue 3                              │
   │    pages, panels, lesson/quiz/mission UI      │
   │                    │                          │
   │              viewer bridge  (typed, one file) │
   │                    │                          │
   │  AnatomyViewer  (vanilla Three.js, owns the   │
   │  canvas, scene, camera, hotspots, RAF loop)   │
   └───────────────────────┬───────────────────────┘
                           │  Inertia page props  +  /api/v1 JSON (session cookie)
                    ┌──────┴──────┐
                    │   Laravel   │
                    │  Http → Services → Models  │
                    └──────┬──────┘
        ┌──────────────────┼──────────────────┐
      MySQL              Redis            Queue (Horizon)
   organs, structures   cache, session    ProcessKnowledgeDocument
   lessons, questions   rate limits       GenerateEmbeddings
   attempts, mastery                      RecalculateMastery
   conversations                          SyncVectorStore
   knowledge_*
                                    ┌─────────────┐
                                    │  AI / RAG   │
                                    └──────┬──────┘
                              ┌────────────┴────────────┐
                     AIProviderInterface      VectorStoreInterface
                     └ Anthropic (default)    └ MySql (default)
                     └ OpenAI                 └ Pinecone
```

---

## 4. Backend module boundaries

### 4.1 Directory layout

Flattened from PRD §20's proposal. A separate `Domain/` layer alongside `Services/` and
`Models/` would be three folders holding one concept each; Eloquent models carry the
domain state and services carry the behaviour.

```
app/
├── Models/                  Eloquent models — state, relations, casts, scopes only
├── Services/
│   ├── Anatomy/             AnatomyService
│   ├── Learning/            LessonService, RecommendationService
│   ├── Assessment/          AssessmentService, MissionService
│   ├── Simulation/          SimulationService
│   ├── Progress/            ProgressService, MasteryCalculator
│   ├── AI/                  AITutorService, AIContextBuilder, AIResponseValidator, PromptBuilder
│   └── Rag/                 KnowledgeService, ChunkingService, EmbeddingService, RetrievalService
├── Contracts/
│   ├── AIProviderInterface.php
│   ├── VectorStoreInterface.php
│   └── (DTOs: AIResponse, RetrievedChunk, LearningContext, SimulationState)
├── Infrastructure/
│   ├── AI/                  AnthropicProvider, OpenAIProvider, NullProvider (tests)
│   └── VectorStore/         MySqlVectorStore, PineconeVectorStore, NullVectorStore
├── Http/
│   ├── Controllers/         Web/ (Inertia pages) · Api/V1/ (JSON) · Admin/
│   ├── Requests/            one FormRequest per write endpoint
│   ├── Resources/           API shapes — the only place a model becomes JSON
│   └── Middleware/
├── Jobs/                    queued work (see §11)
├── Policies/
└── Events/ + Listeners/     analytics events only (§13)

resources/js/
├── anatomy/                 THE 3D LAYER — framework-free, no Vue/Inertia imports
│   ├── AnatomyViewer.ts     scene, camera, controls, RAF loop, tools, input
│   ├── HotspotLayer.ts      markers, snapping, occlusion, screen-space picking
│   ├── AssetManager.ts      GLTF load, normalise, condition, LRU cache, prefetch
│   ├── dispose.ts
│   └── types.ts             OrganDto, StructureDto, ViewerEvent — mirrors API Resources
├── composables/useAnatomyViewer.ts   the ONLY bridge between Vue and the viewer
├── Pages/                   Inertia pages
└── Components/
```

### 4.2 Responsibilities and dependency rules

| Layer | May depend on | May **not** depend on |
|---|---|---|
| `Http/Controllers` | Services, Requests, Resources | Models directly for business logic; Infrastructure |
| `Services/*` | Models, Contracts, other Services, Jobs | Http, Infrastructure concretes, `request()`, `auth()` |
| `Models` | Other Models | Services, Http, Infrastructure |
| `Infrastructure/*` | Contracts, HTTP clients, config | Services, Models, Http |
| `resources/js/anatomy` | Three.js, GSAP | Vue, Inertia, axios, any app global |

Three rules carry most of the weight:

- **Services never read the request or the session.** The authenticated `User` and any
  input arrive as typed arguments. This is what makes services testable and queueable.
- **Services depend on `Contracts`, never on `Infrastructure`.** Binding happens once in
  `AppServiceProvider` from config. Swapping Pinecone for MySQL is a `.env` change.
- **`resources/js/anatomy` imports nothing from the app.** It is a library that happens
  to live in this repo. If it ever imports Vue, the seam is gone and the viewer becomes
  untestable and unswappable.

### 4.3 Service responsibilities

| Service | Owns |
|---|---|
| `AnatomyService` | Organ/structure reads, cached payload assembly for the viewer, structure resolution by slug or TA term |
| `LessonService` | Lesson delivery, step sequencing, completion, `lesson_progress` |
| `AssessmentService` | Question delivery **with answers stripped**, attempt validation, feedback, attempt persistence |
| `MissionService` | Mission config, sequence/pathway validation, scoring, `mission_attempts` |
| `SimulationService` | Deterministic state transitions, event log, terminal-state detection |
| `ProgressService` | Reads mastery, activity history, dashboard aggregation |
| `MasteryCalculator` | The scoring formula (§10). Pure — no I/O, no persistence |
| `RecommendationService` | Next-activity selection from mastery + recent attempts |
| `AITutorService` | Orchestrates context → retrieval → provider → validation → persistence |
| `AIContextBuilder` | Assembles `LearningContext` from user, organ, structure, lesson, recent mistakes |
| `AIResponseValidator` | Scope, safety, and length checks on model output (§9.4) |
| `KnowledgeService` | Document ingest, status lifecycle |
| `ChunkingService` | Document → chunks with metadata |
| `EmbeddingService` | Text → vectors via `AIProviderInterface` |
| `RetrievalService` | Filtered top-k search via `VectorStoreInterface`, returns `RetrievedChunk[]` with source attribution |

---

## 5. The 3D layer

### 5.1 Ownership

`AnatomyViewer` owns the canvas, the scene graph, the camera, the RAF loop, and all
pointer/keyboard input inside the canvas. Vue owns everything outside the canvas. The
viewer is constructed once, imperatively, and lives outside Vue's reactivity — reactive
proxies on Three.js objects are a known performance disaster.

`useAnatomyViewer.ts` is the **single** bridge. It constructs the viewer on mount,
disposes it on unmount, exposes typed commands to Vue, and re-emits viewer events as
Vue events. No other file in `resources/js` may import from `anatomy/`.

### 5.2 Public interface

Derived from the audited implementation (`project-context.md` §2.4/§2.5) rather than
invented, as PRD §1.4 requires. Method names follow the PRD where the semantics match.

```ts
class AnatomyViewer {
  constructor(container: HTMLElement, options: ViewerOptions)

  // --- content
  loadOrgan(organ: OrganDto): Promise<void>   // model + structures + accent, from the API
  prefetchOrgan(modelUrl: string): void
  dispose(): void

  // --- selection
  selectStructure(id: string | null): void    // NEW — programmatic; lessons and missions need it
  getSelectedStructure(): StructureDto | null
  highlightStructure(id: string | null): void // transient emphasis without changing selection
  focusStructure(id: string): void            // NEW — camera fly-to + select
  isolateStructure(id: string | null): void   // see §5.3 — reduced semantics
  flashStructure(id: string, correct: boolean): void

  // --- view
  resetView(): void
  zoom(direction: 1 | -1): void
  setAutoRotate(enabled: boolean): void
  setLayer(layer: 'solid' | 'wireframe' | 'section'): void   // see §5.3
  setCrossSection(enabled: boolean, axis?: 'x'|'y'|'z', offset?: number): void

  // --- modes
  setMode(mode: 'explore' | 'quiz' | 'mission' | 'author'): void

  // --- learning
  triggerAnimation(animationId: string): Promise<void>        // see §5.3
  applySimulationState(state: SimulationState): void          // see §8

  // --- events (typed emitter, replaces the audited callback bag)
  on(event: 'structure:selected' | 'structure:picked' | 'structure:hovered'
          | 'organ:loaded'      | 'load:progress'    | 'load:failed'
          | 'author:point'      | 'webgl:unavailable', handler): Unsubscribe
}
```

`ViewerOptions` carries `{ reducedMotion, lowPower, initialMode }` so the host page can
pass down `prefers-reduced-motion` and device hints.

### 5.3 What these methods honestly do under current assets

The audited models are single-mesh (`project-context.md` §2.2). Three methods therefore
have **reduced semantics** today, and this table is the contract — not a bug list. Each
gains full behaviour automatically when per-structure meshes land, with no change to the
interface, the API, or the schema.

| Method | With single-mesh models (MVP) | With per-structure meshes (upgrade path) |
|---|---|---|
| `selectStructure` | Selects the hotspot marker; callout opens | Also highlights the mesh |
| `highlightStructure` | Marker emphasis + pulse ring | Emissive tint on the mesh |
| `isolateStructure` | Dims all other markers, fades the organ to ~35 %, flies the camera to the structure, dims the plinth | Hides every other mesh |
| `setLayer('wireframe')` | Wireframe on the single material | Per-layer mesh visibility (superficial → deep) |
| `setLayer('section')` | Global clipping plane across the organ | Per-structure clipping |
| `triggerAnimation` | **No GLTF clips exist.** Implemented as scripted camera + marker choreography from a JSON step list (`focus A → hold → focus B → …`) | Plays the named `AnimationClip` |

Calling a method whose full behaviour is unavailable **must not fail silently**: the
viewer emits a `capability:degraded` detail on the returned promise/event so the UI can
avoid advertising a control that will not do what its label says. The three misleading
tools found in the audit (`Isolate` fading only the plinth, `Layers` meaning wireframe,
`Compare` being a 2D drawer) are not reproduced under those names.

### 5.4 Integration rules — non-negotiable

1. **Coordinate space.** Every model is normalised into a `FIT_SIZE = 3.8` cube and
   centred at the origin before hotspots are attached. All `anchor_position` values in
   the database are in this pivot space. **Changing `FIT_SIZE`, or shipping a model that
   skips normalisation, invalidates every authored coordinate in the database.** The
   constant lives in `AssetManager.ts` and is mirrored in a PHP config value used by
   the admin authoring tool; a test asserts they match.
2. **The client never holds an answer key.** Question payloads sent to the browser have
   `is_correct`, `correct_structure_id`, and mission target sequences stripped by the
   API Resource. Validation is `POST` to Laravel, always.
3. **The viewer never fetches.** It receives fully-formed `OrganDto` objects. All
   network access belongs to the Vue layer.
4. **Structure ids are opaque strings to the viewer.** It does not parse, derive, or
   construct them. They come from the API and go back unchanged.
5. **Every load path handles failure.** Model 404, decode error, and WebGL-unavailable
   each emit an event and render a text fallback — the structure list, description, and
   lesson content stay usable without 3D (PRD §31, §40).
6. **Disposal is mandatory.** Unmounting a page disposes the viewer. The audited
   `dispose()` discipline is preserved verbatim; a Vitest test asserts renderer,
   geometry, and texture counts return to zero.
7. **One WebGL context per page.** Never two viewers side by side. "Compare" swaps
   content or uses two DOM-composited canvases only if measured to be safe.

---

## 6. Data model

MySQL, PRD §22 as the base, with the audit's corrections applied. Every table gets
`id`, `created_at`, `updated_at` unless noted. All foreign keys are real constraints.

**Deltas from PRD §22, and why:**

| Change | Reason |
|---|---|
| `anatomical_structures.model_object_name` → **nullable**, plus new `anchor_position JSON` and `ta_term` | The audited models have no named sub-objects. `anchor_position` is the working selection mechanism; `model_object_name` is reserved for the per-structure upgrade. `ta_term` is the canonical anatomical identity found in the audit. |
| `organs` gains `body_system` FK-ish enum instead of free-text `system` | Mastery and progress are reported *per system* (PRD §15, §18); free text cannot be grouped reliably. |
| `attempts` gains `question_id` nullable + `mission_attempt_id` nullable | Spatial-quiz attempts and mission-step attempts are the same shape and should share mastery input. |
| `knowledge_chunks` gains `embedding LONGBLOB` + `organ_id`/`structure_id`/`education_level` filter columns | The MySQL vector store needs the vector and the filter keys in the same row. `embedding_reference` is kept for the Pinecone adapter. |
| `structures` gains `is_published` | Authored-but-unverified structures must not reach students. |

```
users                 id, name, email, password, role(student|admin),
                      education_level, difficulty_preference, xp, level

body_systems          id, slug, name, description
organs                id, body_system_id→, slug, name, scientific_name, description,
                      model_path, model_format, thumbnail_path, accent_color,
                      status(draft|published)
anatomical_structures id, organ_id→, slug, ta_term, name, scientific_name,
                      description, function, location, difficulty(1-5),
                      anchor_position JSON,        -- [x,y,z] in FIT_SIZE pivot space
                      model_object_name NULL,      -- reserved: per-structure meshes
                      marker_color, metadata JSON, is_published
                      UNIQUE(organ_id, slug)
structure_relations   structure_id→, related_structure_id→, relation_type
                      -- PRD §7 "related structures"; drives compare + AI context

lessons               id, organ_id→, title, slug, description, objective,
                      difficulty, estimated_minutes, content JSON, status
lesson_progress       id, user_id→, lesson_id→, status, progress_percent, completed_at
                      UNIQUE(user_id, lesson_id)

questions             id, lesson_id→ NULL, organ_id→, type(mcq|spatial|short_answer),
                      question, difficulty, explanation, correct_structure_id→ NULL,
                      metadata JSON, status(draft|review|published), generated_by_ai
question_options      id, question_id→, label, value, is_correct
attempts              id, user_id→, question_id→ NULL, mission_attempt_id→ NULL,
                      selected_structure_id→ NULL, selected_option_id→ NULL,
                      answer_text NULL, is_correct, time_spent_ms, hint_used
                      INDEX(user_id, created_at)

missions              id, organ_id→, title, slug, description, type, difficulty,
                      configuration JSON, status
mission_attempts      id, user_id→, mission_id→, score, completed, duration_ms,
                      result JSON

simulations           id, organ_id→, title, slug, description, configuration JSON, status
simulation_sessions   id, user_id→, simulation_id→, state JSON, events JSON, result JSON

learning_mastery      id, user_id→, topic_type(system|organ|structure), topic_id,
                      mastery_score DECIMAL(5,2), attempts, correct_attempts,
                      last_activity_at
                      UNIQUE(user_id, topic_type, topic_id)

conversations         id, user_id→, context_type, context_id, title
conversation_messages id, conversation_id→, role, content, metadata JSON
                      -- metadata holds retrieved source ids for citation

knowledge_documents   id, title, source, source_type, version, status, metadata JSON
knowledge_chunks      id, document_id→, chunk_index, content,
                      embedding LONGBLOB NULL,       -- MySqlVectorStore
                      embedding_reference NULL,      -- PineconeVectorStore
                      organ_id→ NULL, structure_id→ NULL, education_level,
                      content_type, metadata JSON
                      INDEX(organ_id, structure_id, education_level)

learning_events       id, user_id→, event_type, context_type, context_id,
                      payload JSON, created_at        -- PRD §29, append-only
achievements          id, slug, name, description, icon, criteria JSON
user_achievements     user_id→, achievement_id→, earned_at
```

Model rules: every model declares `$fillable`, `$casts` (JSON columns cast to `array`,
timestamps to `datetime`, `mastery_score` to `decimal:2`), typed relationship methods,
and PHPDoc properties. Query anything list-shaped with `with()` — a lesson page that
N+1s across structures will be visible in the demo.

---

## 7. API and page boundaries

Two surfaces, one auth mechanism.

**Inertia pages** (`routes/web.php`, `Http/Controllers/Web`) — the shell, the organ
explorer, lesson pages, dashboard, admin. Data arrives as page props. No JSON round-trip
for first paint.

**`/api/v1`** (`routes/api.php`, `Http/Controllers/Api/V1`) — the interactive calls the
viewer and panels make after load. **Session-cookie authenticated** via Sanctum's
stateful guard, not bearer tokens. Same-origin, so no CORS and no token storage.

```
/api/v1
  /anatomy
    GET  /organs                          list + thumbnails (cached)
    GET  /organs/{organ}                  OrganDto: model_path, accent, structures[]
    GET  /structures/{structure}          full metadata + related structures
  /lessons
    GET  /lessons                         filterable by organ, system, difficulty
    GET  /lessons/{lesson}
    POST /lessons/{lesson}/complete
  /quiz
    GET  /quizzes/{quiz}                  questions, ANSWERS STRIPPED
    POST /quizzes/{quiz}/attempt          → { correct, explanation, mastery_delta }
  /missions
    GET  /missions
    GET  /missions/{mission}              config with target sequence stripped
    POST /missions/{mission}/attempt      → { score, per_step, feedback }
  /simulations
    GET  /simulations/{simulation}        definition + initial state
    POST /simulations/{simulation}/event  → { state, visual_directives, explanation }
  /ai
    POST /tutor/ask                       { question, organ, structure, lesson }
    POST /tutor/explain                   { structure }
    POST /tutor/hint                      { question_id }
  /progress
    GET  /progress                        overall + recent activity
    GET  /progress/systems                per-system mastery
    GET  /progress/recommendations
  /events
    POST /events                          batched analytics (§13)
```

Auth is handled by Laravel's session flow through Inertia (`/login`, `/register`,
`/logout` as web routes), not by `/api/v1/auth/*`. PRD §21 lists API auth endpoints
because it assumed a decoupled SPA; Inertia makes them redundant. If a mobile client is
ever added, Sanctum tokens can be enabled alongside without touching the endpoints above.

**Response shaping is the API Resource's job, and only the Resource's job.** This is
where `is_correct` and `correct_structure_id` are stripped, where `anchor_position`
becomes a plain array, and where nothing else leaks.

---

## 8. AI and RAG

### 8.1 Contracts

```php
interface AIProviderInterface {
    public function chat(array $messages, array $options = []): AIResponse;
    public function generateEmbedding(string $text): array;      // float[]
    public function generateEmbeddings(array $texts): array;     // float[][] — batched ingest
}

interface VectorStoreInterface {
    public function upsert(array $documents): void;
    public function search(array $queryVector, int $topK = 5, array $filters = []): array;
    public function delete(array $ids): void;
}
```

`search()` takes a **vector**, not a string. The PRD's `search(string $query, …)` would
force every implementation to own an embedding step; keeping embedding in
`EmbeddingService` means the two adapters stay trivially interchangeable and the same
query vector can be reused across stores. `RetrievalService` is the only caller and
handles embed-then-search.

Bindings, from `config/ai.php`, resolved once in `AppServiceProvider`:

```
AI_PROVIDER=anthropic        → AnthropicProvider  | OpenAIProvider | NullProvider
VECTOR_STORE=mysql           → MySqlVectorStore   | PineconeVectorStore | NullVectorStore
```

`MySqlVectorStore` applies the metadata filters in SQL, loads the surviving embeddings,
and computes cosine similarity in PHP. At MVP corpus size (thousands of chunks, filtered
to hundreds) this is sub-50 ms and needs no extension. If it stops being fast, the
answer is `PineconeVectorStore`, not a rewrite.

### 8.2 Tutor request flow

```
POST /api/v1/ai/tutor/ask
  → TutorController: validate (FormRequest), authorize, rate-limit
  → AITutorService
      → AIContextBuilder    user level · organ · structure (+ TA term) · lesson objective
                            · difficulty · recent mistakes on this topic
      → RetrievalService    embed question+context → VectorStoreInterface->search(
                              topK: 5,
                              filters: { organ_id, structure_id, education_level, content_type })
      → PromptBuilder       system prompt (§8.4) + retrieved chunks + context + question
      → AIProviderInterface->chat()
      → AIResponseValidator (§8.4)
      → persist conversation + message (+ retrieved source ids in metadata)
  ← { answer, sources[], follow_up_questions[], conversation_id }
```

Every answer carries its sources. If retrieval returns nothing above the relevance
threshold, the tutor answers from the structure's own curated metadata and **says so** —
it does not silently fall back to unsourced model knowledge.

### 8.3 Ingestion (queued)

```
Upload → KnowledgeService (validate type, size, sanitize)
       → dispatch ProcessKnowledgeDocument
           → extract text → ChunkingService (~500 tokens, ~50 overlap, metadata attached)
           → dispatch GenerateEmbeddings (batched)
               → dispatch SyncVectorStore
       → document.status: pending → processing → indexed | failed
```

Nothing in this chain runs in a web request.

### 8.4 Safety and quality

`AIResponseValidator` runs on every response before it reaches a student and enforces:
stay within educational scope; never claim to be a doctor; refuse clinical diagnosis and
redirect to the educational framing; prefer and cite retrieved sources; state uncertainty
rather than inventing; match the student's education level; length ceiling appropriate to
a 13–18 audience. A failed validation returns a safe fallback message and logs the
incident — it never returns raw model output or a provider error.

AI-generated **questions** are written with `status = 'review'` and `generated_by_ai =
true`. They cannot be served to students until an admin publishes them (PRD §19, §24).

Rate limits (`throttle` middleware, per-user): tutor 20/min and 200/day, hints 30/min,
attempts 60/min. Provider timeout 20 s, one retry, then a graceful message.

---

## 9. Assessment

Both quiz types share one attempt pipeline, which is what lets them feed one mastery
score.

```
Student clicks a structure in the 3D scene
  → viewer emits structure:picked { structureId }
  → Vue POSTs /quizzes/{quiz}/attempt { question_id, selected_structure_id, time_spent_ms, hint_used }
  → AssessmentService validates against the question's correct_structure_id
  → persists attempt · dispatches RecalculateMastery · records a learning_event
  ← { is_correct, correct_structure_id, explanation, mastery_delta }
  → Vue calls viewer.flashStructure(picked, correct) and, on a miss,
    viewer.flashStructure(correct_structure_id, true)   ← audited UX, preserved
```

MCQ attempts take the same route with `selected_option_id`. Short-answer attempts are
graded by `AITutorService` against a rubric in `questions.metadata`, returning a
correctness verdict plus feedback; the verdict is persisted as a normal attempt.

**Missions** are ordered structure sequences validated server-side. `missions.configuration`
holds `{ type, steps: [{ structure_id, prompt, hint }], scoring }`. The client receives
prompts and hints; it never receives the sequence. `MissionService` scores per step
(correct / correct-after-hint / wrong) and returns per-step feedback so the UI can walk
the student back through the pathway.

---

## 10. Progress and mastery

`MasteryCalculator` is pure and deterministic. Per `(user, topic_type, topic_id)`:

```
accuracy      = correct_attempts / attempts                       (Laplace-smoothed:
                                                                   (correct+1)/(attempts+2))
recency       = exponential decay, half-life 14 days, on last_activity_at
hint_penalty  = 1 - 0.15 × (hinted_attempts / attempts)
coverage      = distinct structures attempted / structures in topic

mastery_score = 100 × accuracy × recency_weight × hint_penalty × (0.5 + 0.5 × coverage)
```

Structure-level scores roll up to organ, organ to body system, unweighted by count so a
single well-drilled structure cannot carry an organ. Recomputed in a queued
`RecalculateMastery` job after each attempt — never in the request.

`RecommendationService` picks the next activity: lowest-mastery published topic with
available unattempted content, tie-broken toward the current organ so the session stays
coherent. It returns a topic, an activity, and a one-line reason ("You are strong on
organ function but need practice identifying structures") — the reason is templated from
the mastery breakdown, not generated by the LLM.

---

## 11. Caching and queues

**Cache** (Redis, tagged, cleared on model save via observers):

```
anatomy:organs                     24 h    organ list + thumbnails
anatomy:organ:{slug}               24 h    OrganDto incl. structures — the viewer's hot path
anatomy:structure:{id}             24 h
lesson:{slug}                       1 h
quiz:{id}:public                    1 h    answer-stripped payload
simulation:{slug}                   1 h
```

Personalised data — progress, mastery, recommendations, and **all AI responses** — is
never cached across users. Retrieval results for an identical (query-hash, filter-set)
may be cached for 10 minutes; the generated answer may not.

**Queues** (Redis + Horizon), three named queues so a slow ingest never delays a student:

| Queue | Jobs |
|---|---|
| `default` | `RecalculateMastery`, `AwardAchievements`, `RecordLearningEvents` |
| `ai` | `GenerateLessonQuestions`, `GenerateRecommendations` |
| `ingest` | `ProcessKnowledgeDocument`, `GenerateEmbeddings`, `SyncVectorStore` |

---

## 12. Simulations

Deterministic state machine in `simulations.configuration`; the AI explains outcomes and
never drives them (PRD §14).

```json
{
  "initial_state": { "valve_closure": 1.0, "output": 1.0, "oxygenation": 0.98 },
  "actions": [
    { "id": "impair_mitral", "label": "Mitral valve does not close fully",
      "effects": { "valve_closure": -0.4, "output": -0.25 },
      "visual": { "highlight": "mitral", "tint": "#d1584f", "pulse_rate": 1.35 } }
  ],
  "thresholds": [
    { "when": "output < 0.8", "outcome": "reduced_systemic_flow",
      "explain_key": "sim.heart.reduced_flow" }
  ]
}
```

`POST /simulations/{id}/event` applies one action, clamps the state, evaluates
thresholds, appends to the event log, and returns `{ state, visual_directives,
explanation }`. `visual_directives` is a small closed vocabulary the viewer understands —
`highlight`, `tint`, `pulse_rate`, `focus`, `cross_section` — chosen because they are
things a **single-mesh model can actually do** (`project-context.md` §2.2). The
explanation is retrieved from curated content when available and generated by the tutor
otherwise, always labelled as educational, never diagnostic.

---

## 13. Analytics

`learning_events` is append-only. Events are emitted through Laravel events and written
by a queued listener. The client batches UI-side events (`organ_viewed`,
`structure_selected`, `structure_isolated`, `layer_changed`) and flushes them to
`POST /api/v1/events` on an interval and on page unload. Server-side events
(`lesson_completed`, `question_answered`, `mission_completed`, `ai_question_asked`,
`hint_requested`, `simulation_*`) are recorded where they happen.

The dashboard reports learning outcomes — accuracy trend, structure-identification
accuracy, mission completion, mastery progression, repeated-misconception rate — not
page views (PRD §30).

---

## 14. Auth, authorization, security

- **Auth:** Laravel session auth via Inertia. Two roles on `users.role`: `student`,
  `admin`. No separate admin table — the audited inherited pattern of a second auth
  system is exactly the complexity this project does not need.
- **Authorization:** Policies for every writable resource. Admin routes behind an
  `admin` middleware **and** a policy check; middleware alone is not authorization.
- **Ownership:** attempts, progress, conversations, and simulation sessions are scoped to
  `auth()->id()` in the service, never trusted from a request parameter.
- **Validation:** one FormRequest per write endpoint. No `$request->all()` into a model.
- **Rate limiting:** per §8.4, plus global `throttle:api` and login throttling.
- **Uploads:** knowledge documents restricted by MIME **and** extension, size-capped,
  stored outside the web root with generated names, text extracted in a queued job,
  admin-only.
- **Secrets:** LLM and vector-store credentials are server-side only, read from config,
  never in an Inertia prop, a Vite `VITE_*` variable, or a client bundle. A CI check
  greps the built bundle for key prefixes.
- **Errors:** provider and vector-store errors are caught, logged with a correlation id,
  and returned to students as plain educational-tone messages. No stack traces, no
  provider names, no upstream status codes (PRD §40).
- Standard Laravel CSRF, `HttpOnly`/`Secure`/`SameSite` cookies, and a CSP that permits
  the model/texture origin and blocks inline script.

---

## 15. Performance, testing, deployment

### 15.1 Budgets

| Budget | Target | Rationale |
|---|---|---|
| Initial page JS (excl. Three.js chunk) | < 200 KB gzip | Three.js is dynamically imported, as in the audited code |
| First organ interactive | < 3 s on a mid-range laptop, cable | Judge's first impression |
| Per-organ payload | **< 2 MB** | Audited models are 2.0–5.8 MB (`project-context.md` §2.6) — re-encode required |
| Per-organ triangles | **< 150 k** | Audited models are 308–387 k |
| Idle frame cost | ~0 | Render-on-demand loop, preserved from the audit |
| `/api/v1/anatomy/*` (cached) | < 100 ms | |
| AI tutor round-trip | < 6 s, with a streaming or progressive indicator | Must not block 3D interaction |

Asset pipeline before shipping any model: decimate to budget → `EXT_meshopt_compression`
→ **KTX2/Basis textures at 1024² or 2048²** (fixes the three JPEG outliers, adds GPU-side
compression) → verify normalisation to `FIT_SIZE = 3.8` → re-verify hotspot anchors.
Delete `public/draco/` and `public/basis/` unless a decoder is genuinely wired in.

### 15.2 Testing

| Layer | Tool | Scope |
|---|---|---|
| Services | Pest unit | `MasteryCalculator` (pure, table-driven), `AssessmentService`, `MissionService` sequence validation, `SimulationService` transitions, `AIResponseValidator` |
| API | Pest feature | Every endpoint: happy path, validation, authorization, **and an explicit test that answer keys are absent from every payload** |
| Contracts | Pest | Both `VectorStoreInterface` implementations against one shared test suite; `NullProvider`/`NullVectorStore` used everywhere else so no test hits a real API |
| Viewer | Vitest + headless WebGL mock | Structure id round-trip, disposal returns resources to zero, `FIT_SIZE` constant matches the PHP config |
| Journey | Playwright | One test walking PRD §44 end to end: register → open heart → select left ventricle → read explanation → answer a spatial question → ask the tutor → complete a mission → see mastery |

CI: Pint → PHPStan level 6 → Pest → Vitest → Playwright → bundle secret-scan.

### 15.3 Deployment

Single VM or container: nginx + PHP-FPM 8.3, MySQL 8, Redis, one Horizon supervisor.
Models and textures on S3-compatible storage behind a CDN with long-lived immutable
cache headers (hashed filenames). `.env` holds every credential; `php artisan
config:cache`, `route:cache`, `view:cache` in the release step; `migrate --force` before
cutover. A staging deploy exists solely so the competition demo is never the first time
the production path runs.

---

## 16. Build order and what is deferred

Phases follow PRD §36. Entry conditions matter more than the list:

| Phase | Delivers | Exit condition |
|---|---|---|
| 0 ✅ | Audit + this architecture | `project-context.md` accepted |
| 1 | Laravel skeleton, MySQL schema + migrations, auth, anatomy API, **viewer extracted into `resources/js/anatomy`, structures served from the DB** | An organ loads from the API and a structure is selectable end to end |
| 2 | Selection wired to learning context, guided exploration, spatial quiz engine | A spatial question is answered and scored server-side |
| 3 | Lessons, questions, missions, attempts, mastery, dashboard | A student completes a lesson and sees mastery change |
| 4 | AI provider abstraction, context builder, conversations, prompts, validation | A contextual tutor answer returns with the selected structure in context |
| 5 | Ingestion, chunking, embeddings, vector store, retrieval filters, citations | A tutor answer cites a real source |
| 6 | Simulation definitions, state engine, visual directives, AI explanation | "What happens if the mitral valve does not close" runs |
| 7 | Design, onboarding, demo journey, performance, error states, accessibility, demo dataset | The §44 Playwright journey passes on staging |

**Licensing (`project-context.md` §3) runs in parallel from week one and gates Phase 7.**
The Phase 1 extraction is deliberately interface-first so the model and viewer decision
can land as late as Phase 5 without re-architecting.

**Explicitly deferred** — no code, no schema, no config until MVP is stable: voice
interaction, multi-language, teacher/class management, leaderboards, AR/VR, mobile app,
WebGPU, AI-generated question automation beyond the review queue, and any second vector
store beyond the one adapter proving the seam works.
