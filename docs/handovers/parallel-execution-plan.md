# Parallel Execution Plan

Which of handovers 03–14 can run simultaneously in separate terminals, in what order, and the
prompt to paste for each. **01 and 02 are implemented.** Analysis only — implements nothing.

Where this adds a dependency a handover does not declare, it is marked **[undeclared]**. Those
are the ones that break parallel runs.

---

## 1. Dependencies

```
[01 ✔]  [02 ✔ pipeline done, licence OPEN]
   │
  03 ── 04
   │     │
   ├─────┼──── 05 ──── 06 ──┐
   │     │      │       │   │
   └──── 08 ────┼── 07 ─┼───┼─ 09
         │      │   │   │   │
         └─ 11  └── 12  └── 10
                   │
      03+06+07+09+11 ── 13 ── 14 (all)
```

| # | Declared deps | Owns tables | Owns paths |
|---|---|---|---|
| 03 | 01 | `body_systems`, `organs`, `anatomical_structures`, `structure_relations` | `AnatomyService`, `Api/V1/AnatomyController`, anatomy Resources, `routes/features/anatomy.php`, `AnatomySeeder` |
| 04 | 01 | — | `resources/js/anatomy/**`, `composables/useAnatomyViewer.ts` |
| 05 | 02,03,04 | — | `Pages/Explore.vue` + children, `Web/ExploreController`, `routes/features/explore.php` |
| 06 | 05,03 | `lessons`, `lesson_progress` | `LessonService`, lesson pages, `routes/features/lessons.php`, `LessonSeeder` |
| 07 | 04,05,03 | `questions`, `question_options`, `attempts` | `AssessmentService`, quiz pages, `routes/features/quiz.php`, `AssessmentSeeder` |
| 08 | 01,03 | `conversations`, `conversation_messages` | `app/Services/AI/**`, `app/Infrastructure/AI/**`, `config/ai.php`, `routes/features/ai.php`, tutor panel |
| 09 | 07,04 | `missions`, `mission_attempts` | `MissionService`, mission pages, `routes/features/missions.php`, `MissionSeeder` |
| 10 | 06,07 | `learning_mastery`, `learning_events`, `achievements`, `user_achievements` | `MasteryCalculator`, `ProgressService`, `RecommendationService`, `routes/features/progress.php` |
| 11 | 08 | `knowledge_documents`, `knowledge_chunks` | `app/Services/Rag/**`, `app/Infrastructure/VectorStore/**`, `RagServiceProvider` |
| 12 | 04,05,08 | `simulations`, `simulation_sessions` | `SimulationService`, simulation pages, `routes/features/simulations.php`, `SimulationSeeder` |
| 13 | 03,06,07,09,11 | **none** | `Http/Controllers/Admin/**`, admin pages, `routes/features/admin.php`, policies |
| 14 | all | — | presentation layer, everywhere |

### Undeclared dependencies

| ID | Finding | Evidence | Consequence |
|---|---|---|---|
| **D1** | 07 → 06 at schema level | H07 DB: `questions (lesson_id nullable, …)`; `lessons` owned by 06 | Cross-branch migration order breaks `migrate:fresh`. **Blocks batch C** |
| **D2** | 11 edits files 08 owns | H11: inserts at 08's seam in `AITutorService` | **08 ∥ 11 is never safe** |
| **D3** | 08's tutor panel vs 05's `Explore.vue` | H08 owns the panel; H05 "renders slots, implements none" | 08 must not edit `Explore.vue` |
| **D4** | `RecalculateMastery` has two owners | H07 creates the no-op; H10 implements it | One file, sequential only |
| **D5** | 09 needs 07's service, not just its table | H09: one `attempts` row per step | 09 cannot start from 07's migration alone |
| **D6** | 04 must ship methods used 3 batches later | Arch §5.2: `applySimulationState` (12), `setMode('author')`/`author:point` (13) | 04 ships the **full** §5.2 surface or 12/13 block on a merged lane |
| **D7** | 03 ↔ 04 contract-coupled | feature-plan §7.8: `types.ts` mirrors 03's Resources | File-isolated, contract-locked. `types.ts` frozen |
| **D8** | Licence still open | `licence-log.md` §3 *Status: OPEN*; `manifest.json` `"pending-licence"`, `"models": []` | 04's scope undecided; 14 cannot deploy |
| **D9** | 13 does **not** administer simulations | H13 scope omits them | 12 ∥ 13 confirmed safe |

---

## 2. Batches

| Batch | Parallel | Agents | Gate before moving on |
|---|---|---|---|
| **A** | 03 ∥ 04 | 2 | 04 publishes full §5.2 signature; 03 posts `OrganDto` JSON |
| **B** | 05 ∥ 08 | 2 | 05 documents the mounting pattern; 08 documents the retrieval seam |
| **C** | 06 ∥ 07 ∥ 11 | 3 | `migrate:fresh --seed` on the **merged** base |
| **D** | 09 ∥ 10 ∥ 12 | 3 | `/boundary-audit` on the merged base |
| **E** | 13 | 1 | — |
| **F** | 14 | 1 | release |

Six runs instead of twelve. **08 moves into batch B** — its deps (01, 03) close after batch A,
so `README.md`'s wave-4 placement wastes a batch and drags 11 and 12 with it.

**Never in parallel:** 08 ∥ 11 (D2) · 07 ∥ 09 (D5) · 07 merging before 10 (D4) · 04 ∥ 05/07/12
(moving interface) · 13 ∥ 03/06/07/09/11 (it CRUDs their services) · anything ∥ 14.

---

## 3. Conflict risks

**C1 · `questions.lesson_id` crosses ownership (D1) — decide before batch C.**
Two branches, two migration timestamps, `questions` may be created before `lessons` exists.
Preferred fix: 07 ships `lesson_id` as a nullable indexed column with **no FK constraint**.
Alternative: 06 lands its `lessons` migration day one of batch C and 07 rebases onto it.

**C2 · Licence open (D8) — decide before batch A.**
04 does not know if it is *adapting* upstream code or *reimplementing from descriptions*
(H04 budgets these differently). If replacement per-structure models are adopted later, 03's
`model_object_name`, 04's §5.3 semantics, 12's directive vocabulary and 13's authoring tool all
change. Default the brief to **reimplement** unless a written grant exists.

**C3 · Migration timestamp interleaving.** Hits batches C and D (three table owners each).
Allocate a band per handover before the batch starts, e.g. batch C = `..._0100_*` (06),
`..._0200_*` (07), `..._0300_*` (11). Re-run `migrate:fresh --seed` after rebasing; a green
branch proves nothing about merged ordering.

**C4 · Append-only registries.**

| File | Writers | Pre-stubbed by 01 |
|---|---|---|
| `database/seeders/DatabaseSeeder.php` | 03,06,07,09,10,11,12 | **Yes** — one comment slot each |
| `config/navigation.php` | 03/05,06,07,09,10,12,13 | **Yes** — slots, `order` gaps of 10 |
| `bootstrap/providers.php` | 08, 11 | No — safe only because D2 keeps them sequential |
| `.env.example` | 08, 11 | No — same |
| `package.json` / `composer.json` | any | Approval required (§7.7). `three@0.185.1`, `gsap@3.15.0` already pinned |

**C5 · Frozen surface.** `app/Contracts/**`, `resources/js/anatomy/types.ts`,
`config/anatomy.php` (`FIT_SIZE = 3.8`). A lane that "fixes" `types.ts` breaks every other lane
silently — PHP and Vue both fail quietly.

**C6 · Cross-lane viewer requests (D6).** 09, 12, 13 are each told to request missing viewer
methods from 04's owner, who is gone by then. Everything they need is already in arch §5.2 —
enforce completeness at 04's review, not at 12's.

**C7 · Answer-key leakage across lanes.** 07 strips correctness fields, 09 strips the target
sequence, 13 legitimately exposes both to admins. Per-lane green ≠ merged green. Run
`/boundary-audit` after batches D and E.

---

## 4. Prompts

Paste one per terminal. Each assumes a fresh session at the repo root.

### Before batch A — two decisions

```text
Two blocking decisions before parallel work starts.

1. docs/handovers/parallel-execution-plan.md §3 C1: handover 07 puts `lesson_id` on
   `questions`, but handover 06 owns `lessons`. Decide whether it carries a real FK
   constraint. Write the answer into docs/handovers/07-assessment-engine.md.

2. §3 C2: docs/licence-log.md still reads Status: OPEN and public/models/manifest.json is
   "pending-licence" with an empty models array. Decide whether handover 04 adapts the
   upstream viewer code or reimplements from the technique descriptions. Record the decision
   and its date in docs/licence-log.md §4.

Do not implement any handover.
```

### Batch A · terminal 1 — Handover 03

```text
Implement handover 03 (Anatomy Domain & API) on branch feat/f03-anatomy-domain-api.

Read first: docs/handovers/03-anatomy-domain-api.md, then docs/architecture.md §6 §7,
docs/engineering.md §1-§6, docs/project-context.md §2.3 §5.5.

Running in parallel with handover 04 in another terminal. Constraints from
docs/handovers/parallel-execution-plan.md:
- You are PHP only. Do not touch resources/js/anatomy/** or any .ts file.
- resources/js/anatomy/types.ts is FROZEN. Mirror it in your API Resources; never edit it.
  If the shape is wrong, stop and raise it — it needs human approval and a matching change
  in handover 04's lane (D7).
- F02's manifest is empty and pending-licence: seed placeholder model_path values and note
  the swap commit for later.
- Append exactly one line to DatabaseSeeder.php (replace the `// F03 → AnatomySeeder`
  comment) and one entry to config/navigation.php. Edit no other shared file.
- Land the migration in your first commit so downstream features can start.

Done when: /verify and /boundary-audit are green, and you have posted the final OrganDto
JSON example — handovers 04, 05 and 07 code against it.
```

### Batch A · terminal 2 — Handover 04

```text
Implement handover 04 (Viewer Core Library) on branch feat/f04-viewer-core.

Read first: docs/handovers/04-viewer-core-library.md, then docs/architecture.md §5 in full,
docs/project-context.md §2.2 §2.4 §2.5 §5.1.

Running in parallel with handover 03 in another terminal. Constraints from
docs/handovers/parallel-execution-plan.md:
- You are TypeScript only. Do not touch app/, database/, routes/, or any .vue page.
- types.ts is FROZEN — implement against it, do not change it (D7).
- Implement the COMPLETE architecture §5.2 surface, including methods with no consumer yet:
  applySimulationState (handover 12) and setMode('author') + the author:point event
  (handover 13). They are three batches away and cannot reopen your lane later (D6).
- three@0.185.1 and gsap@3.15.0 are already pinned. Do not change package.json.
- Licence status: check docs/licence-log.md §4 for the adapt-vs-reimplement decision before
  copying anything from the upstream repo. If it is still undecided, reimplement from the
  technique descriptions in the handover.

Done when: /verify and /boundary-audit are green, Vitest proves disposal returns renderer,
geometry and texture counts to zero, and you have published the final interface signature —
handovers 05, 07, 09 and 12 all drive it.
```

### Batch B · terminal 1 — Handover 05

```text
Implement handover 05 (Explore Experience) on branch feat/f05-explore-experience.

Read first: docs/handovers/05-explore-experience.md, then docs/architecture.md §5.1 §5.4,
PRD §6, docs/project-context.md §2.5.

Handovers 03 and 04 are merged. Running in parallel with handover 08 in another terminal.
Constraints from docs/handovers/parallel-execution-plan.md:
- Handover 08 owns the AI tutor panel and is building it right now. Leave a named, empty
  slot for it in Explore.vue. Do not implement any tutor UI (D3).
- Do not edit resources/js/anatomy/**, AnatomyService, or the anatomy Resources.
- Mount the viewer only through useAnatomyViewer. Nothing else imports from
  resources/js/anatomy/.
- Models are still placeholders (manifest is pending-licence) — build for layout.

Done when: /verify and /boundary-audit are green, and the PR documents the viewer mounting
pattern. Handovers 06, 07, 09 and 12 copy it verbatim, so make it deliberate.
```

### Batch B · terminal 2 — Handover 08

```text
Implement handover 08 (AI Tutor Core) on branch feat/f08-ai-tutor-core.

Read first: docs/handovers/08-ai-tutor-core.md, then PRD §9 §24 §39,
docs/architecture.md §8.1 §8.2 §8.4 §14.

Your declared dependencies (01, 03) are merged — you do NOT need 05 or 07. Running in
parallel with handover 05 in another terminal. Constraints from
docs/handovers/parallel-execution-plan.md:
- Handover 05 owns Explore.vue and is editing it right now. Ship the tutor panel as a
  self-contained component mounted on your own route. Do NOT edit Explore.vue — 05 leaves
  a named slot and the wiring lands in its lane or in handover 14 (D3).
- Do not implement VectorStoreInterface, embeddings or any retrieval — that is handover 11.
  Leave the retrieval step as an explicit, named seam and ship sources[] as an empty array.
- app/Contracts/** is FROZEN.
- Register AiServiceProvider by appending to bootstrap/providers.php; append your keys to
  the end of .env.example. Handover 11 appends to both later — never reorder.
- No test may make a real API call. NullProvider everywhere.

Done when: /verify and /boundary-audit are green, and the PR documents exactly where
handover 11 inserts at the retrieval seam.
```

### Batch C · terminal 1 — Handover 06

```text
Implement handover 06 (Lessons) on branch feat/f06-lessons.

Read first: docs/handovers/06-lessons.md, then PRD §8, docs/architecture.md §6 §11.

Handovers 03, 04, 05 and 08 are merged. Running in parallel with handovers 07 and 11.
Constraints from docs/handovers/parallel-execution-plan.md:
- Check the C1 decision recorded in docs/handovers/07-assessment-engine.md: handover 07 may
  need `lessons` to exist before its migration runs. If the decision was "06 lands first",
  commit and push your lessons migration before anything else.
- Use migration timestamp band 2026_MM_DD_0100_* so ordering is deterministic on merge (C3).
- You own `lessons` and `lesson_progress` only. Do not create question, attempt or mastery
  tables. You emit lesson_progress rows; handover 10 reads them.
- Embed the viewer using handover 05's documented mounting pattern — do not invent your own.
- Append one line to DatabaseSeeder.php (your `// F06 → LessonSeeder` slot) and one entry to
  config/navigation.php.

Done when: /verify and /boundary-audit are green, and after rebasing onto the merged base
`php artisan migrate:fresh --seed` succeeds.
```

### Batch C · terminal 2 — Handover 07

```text
Implement handover 07 (Assessment Engine) on branch feat/f07-assessment-engine.

Read first: docs/handovers/07-assessment-engine.md, then PRD §12,
docs/architecture.md §9 §5.4, docs/project-context.md §2.4.

Handovers 03, 04, 05 and 08 are merged. Running in parallel with handovers 06 and 11.
Constraints from docs/handovers/parallel-execution-plan.md:
- questions.lesson_id points into handover 06's `lessons` table, which is being built in
  parallel right now. Follow the C1 decision recorded at the top of your handover. Absent
  one, ship lesson_id as a nullable indexed column with NO foreign key constraint (D1).
- Use migration timestamp band 2026_MM_DD_0200_*.
- Land the questions/question_options/attempts migration in your FIRST commit — handovers 09
  and 10 both start against it.
- Include the nullable, unused mission_attempt_id column. Handover 09 populates it; leaving
  it out forces 09 to alter your table.
- Create RecalculateMastery as a no-op job. Handover 10 implements its body later (D4).
- The client never receives an answer key. is_correct, correct_structure_id and
  correct_option_id are stripped by the API Resource, with a test per endpoint.

Done when: /verify and /boundary-audit are green, and a test proves no correctness field is
reachable from any payload.
```

### Batch C · terminal 3 — Handover 11

```text
Implement handover 11 (RAG & Knowledge Base) on branch feat/f11-rag-knowledge-base.

Read first: docs/handovers/11-rag-knowledge-base.md, then PRD §10 §11 §28,
docs/architecture.md §8.1 §8.3 §11.

Handover 08 is merged — read its PR for the documented retrieval seam. Running in parallel
with handovers 06 and 07. Constraints from docs/handovers/parallel-execution-plan.md:
- You are the only lane permitted to touch AITutorService, and only at that named seam.
  Do not otherwise modify AITutorService, PromptBuilder or AIResponseValidator (D2).
- app/Contracts/** is FROZEN. search() takes a vector, not a string.
- MySqlVectorStore is the DEFAULT, not a fallback. Pinecone exists to prove the seam. Do not
  add a third store.
- Use migration timestamp band 2026_MM_DD_0300_*.
- Append RagServiceProvider to bootstrap/providers.php and your keys to the end of
  .env.example — handover 08 already appended to both; never reorder its lines.
- No test makes a network call. One shared contract suite runs against both store
  implementations — that is what proves the abstraction.

Done when: /verify and /boundary-audit are green and the "no relevant sources, and saying so"
path still fires.
```

### Batch D · terminal 1 — Handover 09

```text
Implement handover 09 (Missions) on branch feat/f09-missions.

Read first: docs/handovers/09-missions.md, then PRD §13, docs/architecture.md §9.

Handovers 04, 07 and 11 are merged. Running in parallel with handovers 10 and 12.
Constraints from docs/handovers/parallel-execution-plan.md:
- You persist through handover 07's attempt pipeline: one attempts row per step with
  mission_attempt_id set. Go through AssessmentService — never write its tables directly and
  never alter its schema (D5).
- Do not add viewer methods. focusStructure, flashStructure and setMode('mission') are
  already in the merged handover 04 interface (D6). If something is genuinely missing, stop
  and raise it rather than adding it.
- The client receives prompts and hints, never the target sequence. The Resource strips
  structure_id from every step, with a test proving it.
- Use a migration timestamp band distinct from handovers 10 and 12 (C3).

Done when: /verify and /boundary-audit are green and "Trace the Blood" runs end to end with
per-step feedback.
```

### Batch D · terminal 2 — Handover 10

```text
Implement handover 10 (Progress, Mastery & Gamification) on branch feat/f10-progress-mastery.

Read first: docs/handovers/10-progress-mastery.md, then PRD §15 §16 §18 §29 §30,
docs/architecture.md §10 §13.

Handovers 06 and 07 are merged. Running in parallel with handovers 09 and 12. Constraints
from docs/handovers/parallel-execution-plan.md:
- Handover 07 left RecalculateMastery as a no-op job. You implement its body — that file is
  yours from here (D4).
- Read `attempts` and `lesson_progress`. Never modify either.
- MasteryCalculator is pure: no I/O, no persistence, no auth(). The formula in
  architecture.md §10 is fixed — raise a change, do not improvise one.
- Handover 09 is writing mission rows into `attempts` in parallel. Consume attempts through
  their existing shape; do not special-case missions.
- Use a migration timestamp band distinct from handovers 09 and 12 (C3).

Done when: /verify and /boundary-audit are green, the table-driven MasteryCalculator tests
cover zero attempts / all correct / all wrong / heavy hints / stale activity / partial
coverage, and no mastery computation happens synchronously in a web request.
```

### Batch D · terminal 3 — Handover 12

```text
Implement handover 12 (Simulations) on branch feat/f12-simulations.

Read first: docs/handovers/12-simulations.md, then PRD §14,
docs/architecture.md §12 §5.3.

Handovers 04, 05 and 08 are merged. Running in parallel with handovers 09 and 10.
Constraints from docs/handovers/parallel-execution-plan.md:
- Call the merged viewer's applySimulationState. Do not add viewer methods (D6).
- Call the tutor read-only; do not modify AITutorService — handover 11 already inserted at
  its seam. Mock explanations in tests; no real API call.
- Mount the viewer using handover 05's documented pattern.
- The visual directive vocabulary is closed: highlight, tint, pulse_rate, focus,
  cross_section. A single-mesh model can perform exactly these five. Do not extend it.
- The LLM never drives state. State transitions are computed in PHP and are reproducible.
- Use a migration timestamp band distinct from handovers 09 and 10 (C3).

Done when: /verify and /boundary-audit are green and replaying the same action sequence
reproduces the same state exactly.
```

### Batch E — Handover 13

```text
Implement handover 13 (Admin & Content Management) on branch feat/f13-admin-content.
Run alone — no other lane is in flight.

Read first: docs/handovers/13-admin-content.md, then PRD §19 §24 §41,
docs/architecture.md §14, docs/project-context.md §2.4.

Handovers 03, 06, 07, 09 and 11 are merged. Constraints from
docs/handovers/parallel-execution-plan.md:
- You own NO tables. Do not create or alter one, and never bypass a feature's service to
  write its tables directly. If you need a service method that does not exist, stop and say
  so rather than reaching past the service into a model.
- You are the only place answer keys and mission target sequences are legitimately exposed —
  to admins, behind the admin middleware AND a policy check. Middleware alone is not
  authorization. Test the policy directly, independent of the middleware.
- The hotspot authoring tool uses the merged viewer's setMode('author') and author:point.
  Coordinates are only meaningful in FIT_SIZE = 3.8 space — show which model version a
  coordinate was authored against and warn if the model changes.
- Simulations are out of scope (handover 12 administers its own).

Done when: /verify and /boundary-audit are green on the merged base — a per-lane green does
not prove the merged answer-key boundary holds (C7).
```

### Batch F — Handover 14

```text
Implement handover 14 (Demo Journey & Competition Polish) on branch feat/f14-demo-polish.
Run alone — every other handover is merged and nothing else is in flight.

Read first: docs/handovers/14-demo-polish.md, then PRD §2.3 §31 §32 §44 §45,
docs/architecture.md §15.1.

Constraints from docs/handovers/parallel-execution-plan.md:
- HARD GATE: check docs/licence-log.md §4. If the licence decision is still open, nothing
  deploys publicly. Say so plainly rather than working around it (D8).
- Add no features. If something is missing it is a gap in an earlier handover — raise it,
  do not quietly build it here. Prefer presentation-layer fixes to service or schema changes.
- Do not weaken a test or an assertion to make the journey pass.
- Measure the performance budgets and record actual numbers. "Feels fast" is not a result.
- The attribution page renders docs/asset-register.md.

Done when: /verify and /boundary-audit are green, and one Playwright test walks the whole
PRD §44 journey on a cold database seeded by the demo seeder.
```

---

## 5. Summary

| | |
|---|---|
| Remaining | 12 handovers (03–14) |
| Sequential batches | **6** |
| Max concurrent agents | **3** (batches C, D) |
| Isolated pairs | 03 ∥ 04 · 05 ∥ 08 |
| Must run alone | 13, 14 |
| Never overlap | 08 ∥ 11 · 07 ∥ 09 · 04 ∥ its consumers · anything ∥ 14 |
| Decide before starting | **C1** (`questions.lesson_id` FK) · **C2** (licence → 04's scope) |
| Largest risk | **D8** — licence open, model manifest empty |
