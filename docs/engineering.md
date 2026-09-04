# Engineering Standards

**Scope:** the minimum rules required for several agents to work this repository in
parallel without breaking each other.
**Companion docs:** `docs/architecture.md` (what we build), `docs/project-context.md`
(what we found). This document is *how* we build it.

Every rule here exists because breaking it costs someone else time. Nothing is here
for tidiness. Where a rule can be machine-checked it is — see §10.

---

## 1. The seven invariants

If you remember nothing else, remember these. They are enforced by hooks and by
`/boundary-audit`, and each one is load-bearing for a decision in
`docs/architecture.md`.

| # | Invariant | Breaking it causes |
|---|---|---|
| 1 | Services never touch `request()` / `auth()` / `session()` | Service cannot be queued or unit-tested |
| 2 | Services depend on `App\Contracts`, never `App\Infrastructure` | Provider/vector-store swap becomes a rewrite |
| 3 | `resources/js/anatomy/` imports no Vue, no Inertia, and never fetches | Viewer becomes untestable and unswappable |
| 4 | The client never receives an answer key | Students read the answers out of the network tab |
| 5 | `FIT_SIZE` is `3.8` | Every `anchor_position` in the database silently becomes wrong |
| 6 | Eloquent only in `app/`; `declare(strict_types=1)` on every PHP file | Silent coercion bugs, unreviewable SQL |
| 7 | Stay in your lane; shared files need human approval | Two agents overwrite each other |

---

## 2. Coding and naming

**PHP**

- `declare(strict_types=1);` at the top of every file. No exceptions.
- Classes `PascalCase`, filename matches class. Methods `camelCase`, **verb-first**
  (`calculateMastery()`, not `mastery()` or `handle()`). Variables `camelCase` and
  meaningful — `$correctAttempts`, never `$c`.
- Type every parameter and return, including `void` and nullable (`?Organ`). Property
  types on every declared property.
- Constructor property promotion, `readonly` where the value never changes, `final`
  on classes not designed for extension (services, DTOs, actions).
- Named arguments for any call with 3+ parameters or with a boolean parameter —
  `recordAttempt(user: $user, question: $q, isCorrect: true)` reads; positional does not.
- One public reason to exist per class. If you cannot name it in a sentence without
  "and", split it.

**JavaScript / TypeScript / Vue**

- TypeScript in `resources/js/anatomy/` (strict). Vue SFCs use `<script setup>`.
- Components `PascalCase.vue`, composables `useThing.ts`, plain modules `camelCase.ts`.
- No `any` in `resources/js/anatomy/`. Elsewhere, `any` needs a comment saying why.

**Database**

- Tables `snake_case` plural (`anatomical_structures`). Columns `snake_case`.
  Foreign keys `<singular>_id`. Booleans read as assertions: `is_published`, `hint_used`.
- Pivot tables alphabetical singular (`question_option` — but prefer a named model
  when the row carries its own data).

**Everywhere**

- Comments explain **why**, never what. A comment restating the code is deleted on review.
- No commented-out code. Git remembers it.
- Names use the domain vocabulary from `docs/architecture.md` §6 — `structure`, not
  `part`; `mastery`, not `score`; `attempt`, not `answer`.

---

## 3. Backend standards

- **Controllers are thin.** Validate (FormRequest) → delegate (Service) → return
  (Resource or Inertia response). A controller method over ~15 lines is a smell; over
  30 is a violation.
- **One FormRequest per write endpoint.** Never `$request->all()`. Authorization goes
  in the Policy, not the FormRequest's `authorize()` beyond the trivial case.
- **Services take typed arguments and return typed values.** The authenticated `User`
  is a parameter. This is invariant 1 and it is what makes a service reusable from a
  queued job.
- **Eloquent only.** Models, relationships, scopes, query builder. No `DB::table`,
  `DB::raw`, or hand-written joins in `app/`. Migrations and seeders may use
  `DB::statement` where Eloquent genuinely cannot express the operation.
- **Eager load.** Any relation touched inside a loop is loaded with `with()`. N+1 in
  the demo is visible to a judge.
- **Queue anything slow.** Embeddings, document processing, mastery recalculation,
  analytics. The user-facing request never waits on an LLM ingest.
- **Models hold state, not behaviour**: `$fillable`, `$casts`, typed relationships,
  PHPDoc properties, scopes. Business rules live in services.

## 4. Frontend and 3D standards

- **Templates display.** No queries, no calculations, no business rules, no formatting
  logic in Blade or in a Vue template. Compute it in a service, pass it in as data.
- **The viewer owns the canvas; Vue owns everything else.** The single bridge is
  `resources/js/composables/useAnatomyViewer.ts`. Nothing else imports from
  `resources/js/anatomy/`.
- **Never make Three.js objects reactive.** No `ref()`/`reactive()` around a scene,
  camera, mesh, or material. Hold them in a plain module-scope object or a `shallowRef`.
- **Dispose on unmount, every time.** Renderer, geometries, materials, textures,
  event listeners, observers. The audited upstream code gets this right — keep it right.
- **Structure ids are opaque strings.** The client does not parse, derive, or construct
  them. They arrive from the API and go back unchanged.
- **Every load path has a failure path.** Model 404, decode error, WebGL unavailable
  → the text fallback (structure list, description, lesson content) still works.
- **Accessibility is not a phase.** Every 3D interaction has a keyboard or text
  equivalent, and `prefers-reduced-motion` is honoured, in the same commit that adds it.

## 5. API and dependency standards

- Versioned under `/api/v1`. RESTful, plural nouns, no verbs in URIs
  (`POST /quizzes/{quiz}/attempt`, never `/submitQuiz`).
- **API Resources are the only place a model becomes JSON.** This is where answer keys
  are stripped. Never `return $model;` from a controller.
- Consistent envelopes: `{ data: … }` for success, `{ message, errors }` for failure.
  Correct status codes — 422 validation, 403 authorization, 404 missing, 429 throttled.
- Never leak an upstream error to a student. Catch provider/vector-store failures, log
  with a correlation id, return an educational-tone message (`architecture.md` §14).
- **Adding a dependency requires human approval.** The hook prompts on `composer
  require` / `npm install`. Justify: what it does, why nothing installed already does
  it, its licence, its maintenance status. Pin exact versions for `three` and `gsap` —
  a minor Three.js bump can change renderer behaviour.
- **Two abstractions exist, and only two:** `AIProviderInterface` and
  `VectorStoreInterface`. Both have a real second implementation. Do not add a third
  interface because something "might" be swapped later.

## 6. Database standards

- Every column used in code exists in a migration. `php artisan migrate:fresh` must
  pass from empty — a migration that only works incrementally is broken.
- Real foreign key constraints. Deliberate nullability (write down why a column is
  nullable). Indexes on every FK and on every column used in a `where` on a hot path.
- **Never edit a committed migration.** Write a new one. The hook prompts if you try.
- Seeders are idempotent and produce the demo dataset. The demo is a first-class
  artefact, not an afterthought.
- Money and scores are `DECIMAL`, never `FLOAT`.

---

## 7. Module ownership

One owner per module. The owner writes it; others read it and open a request rather
than editing it. "Owner" is a lane, not a person — an agent assigned that lane.

| Lane | Owns | Must not touch |
|---|---|---|
| **Anatomy** | `app/Services/Anatomy/`, `app/Models/{Organ,AnatomicalStructure,BodySystem}.php`, anatomy migrations, `Api/V1/AnatomyController` | Viewer internals, AI, assessment |
| **Viewer (3D)** | `resources/js/anatomy/**`, `resources/js/composables/useAnatomyViewer.ts` | Anything in `app/`, any other JS |
| **Learning** | `app/Services/Learning/`, lesson models/migrations, lesson pages | Assessment scoring, AI |
| **Assessment** | `app/Services/{Assessment,Progress}/`, question/attempt/mission/mastery models | Anatomy metadata, AI prompts |
| **AI + RAG** | `app/Services/{AI,Rag}/`, `app/Infrastructure/{AI,VectorStore}/`, `config/ai.php` | Assessment scoring, viewer |
| **Simulation** | `app/Services/Simulation/`, simulation models, simulation directives | Everything else |
| **Platform** | auth, middleware, policies, `AppServiceProvider`, CI, base layout | Domain services |

**Shared — human approval required (hook prompts):** `app/Contracts/**`,
`resources/js/anatomy/types.ts`, `config/ai.php`, `docs/**`, `composer.json`,
`package.json`, `.github/workflows/**`, `CLAUDE.md`.

**Never agent-editable (hook denies):** `.env*`, lock files, `vendor/`,
`node_modules/`, `public/build/`, `.claude/settings.json`, `.claude/hooks/`.

## 8. Rules for independent agents

1. **Declare your lane before you write.** State which module you own for this task
   and which files you expect to touch. If the task needs a file outside your lane,
   say so up front rather than discovering it halfway.
2. **One lane, one branch.** `feat/<lane>-<short-desc>`. Never commit to `main`.
3. **Depend on interfaces, not on other agents' progress.** If you need something a
   parallel lane is building, code against the contract in `app/Contracts` or the
   documented Resource shape and stub the rest. Do not wait, and do not reach in.
4. **Changing a shared contract is a stop-and-ask.** Interface, DTO, API Resource
   shape, DB column another lane reads — announce it, get approval, and update every
   consumer in the same commit.
5. **Rule 0 — propagate every change.** A rename is not done until every usage site is
   updated. PHP and Vue templates fail silently at runtime, so grep is not optional:
   ```bash
   grep -rn "oldName" app/ resources/ routes/ database/ config/ tests/
   ```
   Then re-grep to confirm zero remaining references. Named routes, `route('…')` calls
   in templates, and `$request->input('field')` string keys cannot be found by a
   refactoring tool — check them by hand and say which files you checked.
6. **Never disable enforcement to get unblocked.** If a hook blocks you, either the
   code is wrong or the rule is wrong. Fix the code, or raise the rule with the human.
   Editing `.claude/hooks/` to get past a check is a serious violation.
7. **Report honestly.** If you finished 3 of 4 items, say which one you did not do and
   why. A confident "done" that is not done costs more than an honest partial.
8. **Leave the tree green.** Run `/verify` before you hand back.

## 9. Testing requirements

Write the test in the same commit as the code. Not after, not "later".

| What | Test type | Required? |
|---|---|---|
| `MasteryCalculator` and any pure calculation | Pest unit, table-driven | **Yes** |
| Every service method with a branch | Pest unit | **Yes** |
| Every API endpoint | Pest feature — happy path, validation, authorization | **Yes** |
| **Every payload that could carry an answer key** | Pest feature asserting absence | **Yes — non-negotiable** |
| `VectorStoreInterface` implementations | One shared Pest suite run against each | **Yes** |
| Viewer structure-id round-trip and disposal | Vitest | **Yes** |
| `FIT_SIZE` PHP config matches the TS constant | Pest | **Yes** |
| PRD §44 journey | Playwright, one test | **Yes, by Phase 7** |
| Blade/Vue markup details | — | No. Do not test the DOM shape. |

Rules:

- **No test touches a real LLM or vector store.** `NullProvider` and `NullVectorStore`
  exist for this. A test that makes a network call will be deleted.
- Factories for all test data. No hand-built arrays, no reliance on seeder state.
- A bug fix starts with a failing test that reproduces it.
- Never delete, skip, or weaken an assertion to make a suite green. If a test is
  wrong, fix the test and say why in the commit message.

## 10. Security and performance

**Security**

- Secrets in `.env`, read through `config()`. Never `env()` outside `config/`
  (it returns null once config is cached). Never in an Inertia prop, a `VITE_*`
  variable, or any client bundle.
- Ownership is scoped in the service from the passed-in `User` — never trusted from a
  request parameter. `Attempt::find($id)` without an ownership check is a vulnerability.
- Policy on every writable resource. Admin routes need middleware **and** a policy;
  middleware alone is not authorization.
- Uploads: MIME **and** extension checked, size-capped, stored outside the web root
  with generated names, processed in a queued job, admin-only.
- Rate limits on every AI endpoint before it ships, not after.
- Never log a prompt, an API key, or a student's personal data.

**Performance** (budgets in `architecture.md` §15.1)

- Per organ: **< 2 MB payload, < 150k triangles.** Upstream models are 2.0–5.8 MB and
  308–387k triangles — every model is re-encoded before it ships.
- Cache anatomy metadata; never cache a personalised AI response.
- Eager load. Paginate any list that can grow.
- The render loop stays render-on-demand. Do not introduce an unconditional
  `requestAnimationFrame` redraw.
- Measure before optimising, and say what you measured.

## 11. Git and Definition of Done

**Commits**

- Conventional commits: `feat|fix|refactor|test|docs|chore(<lane>): <imperative>` —
  e.g. `feat(assessment): validate spatial attempts server-side`.
- Small and coherent. One logical change. Never mix a refactor with a behaviour change.
- The body says **why**. Reference the PRD section or architecture rule.
- Never commit: `.env`, secrets, `vendor/`, `node_modules/`, build output, debug
  statements (`dd`, `dump`, `var_dump`, `console.log`), commented-out code.
- Never force-push a shared branch (the hook denies it).

**Definition of Done** — all of it, or it is not done:

1. The requested scope is complete. Anything left out is named explicitly, with why.
2. Tests written in the same commit, and they cover the branches.
3. `/verify` passes — Pint, PHPStan, Pest, `migrate:fresh`, `route:list`, frontend
   build, `/boundary-audit`, secret scan. Output shown, not summarised.
4. Rule 0 done: re-grepped, zero stale references, hand-checked string keys named.
5. No new architecture violation (`/boundary-audit` clean).
6. No answer key, secret, or credential reachable from the client.
7. Errors handled — no raw provider error, no unhandled model-load failure.
8. Accessibility equivalent exists for any new 3D interaction.
9. Performance budget respected for anything touching the viewer or a hot query.
10. Commit message explains the change; shared-contract changes are announced.

## 12. Forbidden

**Practices**

- Committing to `main`; force-pushing a shared branch.
- Editing a committed migration; editing lock files by hand.
- Disabling, weakening, or working around a hook or a test to get unblocked.
- `$request->all()` into a model. Raw SQL in `app/`. Logic in templates.
- Sending correctness data to the client. Grading anything client-side.
- Claiming "tests pass" without having run them.
- Silently reducing scope, or reporting partial work as complete.
- Touching another lane's files without saying so.

**Abstractions we are not building** — the codebase is ~8 weeks of work for a
competition MVP. Each of these costs more than it saves at this size:

- A Repository layer over Eloquent. Eloquent *is* the data layer.
- A `Domain/` layer separate from `Models/` and `Services/`.
- Interfaces with one implementation (except the two named in §5).
- Generic `AbstractBaseService`, `BaseController`, or a custom container.
- An event bus, CQRS, or a state machine library for the simulation — the simulation
  is a JSON config and a `match` expression.
- Microservices, a separate API gateway, or a second datastore.
- A custom frontend state library. Inertia props plus local state is enough.
- Premature caching, sharding, or read replicas.
- A plugin/module system for organs. Organs are database rows.

If you believe one of these is genuinely needed, say why in one paragraph and get
agreement before building it.

---

## 13. What is enforced automatically

Configured in `.claude/settings.json`; scripts in `.claude/hooks/`.

| Hook | Fires on | Effect |
|---|---|---|
| `session-brief.sh` | SessionStart | Injects the §1 invariants and lane map so every agent starts aligned |
| `guard-protected-paths.sh` | before Write/Edit | **Denies** `.env*`, lock files, `vendor/`, `node_modules/`, `.claude/settings.json`, `.claude/hooks/`, and any write containing an API-key-shaped string. **Prompts** for `docs/**`, `app/Contracts/**`, `config/ai.php`, `resources/js/anatomy/types.ts`, `composer.json`, `package.json`, existing migrations, CI |
| `guard-bash.sh` | before Bash | **Denies** recursive root/home delete, force-push, `chmod 777`. **Prompts** for `migrate:fresh`, `git reset --hard`, dependency installs, and commits on `main` |
| `format-file.sh` | after Write/Edit | Pint on PHP, Prettier on JS/Vue/CSS. Silent no-op until the toolchain is installed |
| `guard-architecture.sh` | after Write/Edit | **Blocks and explains** violations of invariants 1–6: service/request coupling, Infrastructure leakage, model→service dependency, raw SQL, fat controllers, viewer framework imports, viewer fetching, answer-key leaks, logic in templates, missing `strict_types`, `FIT_SIZE` drift |

| Skill | Use |
|---|---|
| `/verify` | The §11 DoD gate. Runs the whole pipeline, reports evidence, never claims a skipped step passed |
| `/boundary-audit` | Whole-repo sweep for §1 violations — catches what the per-file hook could not see |

### Installed skills to reach for

| Skill | When |
|---|---|
| `laravel-best-practices` | Any backend PHP work. Official Laravel Boost skill — 20 rule files (eloquent, db-performance, security, validation, queue-jobs, testing) |
| `laravel-security` · `laravel-patterns` · `laravel-verification` | Authorization, architecture review, pre-deploy checks |
| `pest-testing` | Writing any Pest test (§9). Pest 4 syntax, datasets, expectations |
| `vue-best-practices` | Any `.vue` file, Pinia, Vue Router. Enforces Composition API + `<script setup>` — matches §2 |
| `threejs-fundamentals` | Viewer work — scene, camera, renderer, Object3D hierarchy, transforms |
| `accessibility` | WCAG 2.2 audit. Required by PRD §31 and DoD item 8 |
| `test-driven-development` · `systematic-debugging` · `verification-before-completion` | Method, not subject matter |
| `/grill-me` · `/grill-with-docs` | User-invoked only. Pressure-test a plan or design before building it |
| `brainstorming` · `writing-plans` · `executing-plans` | Before and during a multi-step lane |

**Not installed, deliberately:** there is no Inertia-for-Vue skill on the registry
(only React and Rails variants — neither applies), and no RAG/vector-store skill worth
the context budget. `docs/architecture.md` §8 is the reference for both.

**Laravel Boost MCP server** (`php artisan boost:mcp`) is a *plugin*, not a skill, and
it needs a Laravel app plus `composer require laravel/boost --dev`. Enable it in Phase 1
once `artisan` exists — enabling it now yields a server that fails to start every session.

**The hooks are a safety net, not the standard.** They catch a handful of mechanical
mistakes. Everything else in this document is on you.
