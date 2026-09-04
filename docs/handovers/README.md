# Handovers — Index

14 execution contracts, one per feature in `docs/feature-plan.md`. Each is self-contained and
independently executable, reviewable, and committable by one agent on one branch.

**Before starting any handover, read:** `PRD.md` · `docs/project-context.md` (what the audit
found) · `docs/architecture.md` (the target) · `docs/engineering.md` (the rules). Handovers
reference these rather than repeating them; where they disagree, the shared docs win.

---

## All handovers

| # | Handover | Purpose | Lane | Wave | Depends on |
|---|---|---|---|---|---|
| 01 | [Platform Foundation](01-platform-foundation.md) | Laravel skeleton, auth, shared contracts, the four anti-conflict registries, test harness | Platform | 1 | — |
| 02 | [Asset Pipeline & Licence](02-asset-pipeline-licence.md) | Legally shippable, budget-compliant models + provenance register. **Release gate** | Content | 1 | — |
| 03 | [Anatomy Domain & API](03-anatomy-domain-api.md) | Organ/structure schema, service, API, seed content | Anatomy | 2 | 01 |
| 04 | [Viewer Core Library](04-viewer-core-library.md) | Framework-free 3D viewer exposing the §5.2 interface | Viewer | 2 | 01 |
| 05 | [Explore Experience](05-explore-experience.md) | Viewer + API wired into a usable page; sets the mounting pattern | Anatomy+Viewer | 3 | 02, 03, 04 |
| 06 | [Lessons](06-lessons.md) | Structured lessons and completion tracking | Learning | 4 | 05 |
| 07 | [Assessment Engine](07-assessment-engine.md) | MCQ + 3D spatial quiz, server-side validation | Assessment | 4 | 04, 05 |
| 08 | [AI Tutor Core](08-ai-tutor-core.md) | Context-aware tutor, provider abstraction, response validator | AI | 4 | 01, 03 |
| 09 | [Missions](09-missions.md) | Multi-step spatial challenges with sequence validation | Assessment | 5 | 07 |
| 10 | [Progress & Mastery](10-progress-mastery.md) | Mastery score, recommendations, analytics, gamification | Progress | 5 | 06, 07 |
| 11 | [RAG & Knowledge Base](11-rag-knowledge-base.md) | Grounded, cited answers behind a vector-store abstraction | AI | 5 | 08 |
| 12 | [Simulations](12-simulations.md) | Deterministic "what happens if" engine, AI-explained | Simulation | 6 | 04, 05, 08 |
| 13 | [Admin & Content](13-admin-content.md) | Content CRUD, AI review queue, 3D hotspot authoring tool | Admin | 6 | 03, 06, 07, 09, 11 |
| 14 | [Demo Journey & Polish](14-demo-polish.md) | Onboarding, demo dataset, a11y, performance, PRD §44 journey | All-hands | 7 | all |

---

## Execution order

```
WAVE 1   01 Platform Foundation ──────────┐     02 Asset Pipeline & Licence
         (blocks everything)              │     (no code deps — start day one)
                                          │
WAVE 2   ┌────────────────────────────────┴───────────────┐
         03 Anatomy Domain & API      04 Viewer Core Library
         └──────────────────┬─────────────────────────────┘
                            │
WAVE 3              05 Explore Experience
                            │
WAVE 4   ┌──────────────────┼──────────────────┐
         06 Lessons     07 Assessment      08 AI Tutor Core
         └──────┬───────────┴────────┬─────────┴──────┐
                │                    │                │
WAVE 5   10 Progress &          09 Missions       11 RAG &
         Mastery                                  Knowledge Base
                └────────────────────┬────────────────┘
                                     │
WAVE 6          12 Simulations   ·   13 Admin & Content
                                     │
WAVE 7          14 Demo Journey & Competition Polish
```

## What can run in parallel

| Wave | Parallel | Notes |
|---|---|---|
| 1 | **01 ∥ 02** | Zero shared files. 02 needs no code at all |
| 2 | **03 ∥ 04** | Different lanes entirely — PHP vs TypeScript. 04 codes against `types.ts` |
| 3 | — | 05 alone. It is the first integration point and sets a pattern four features copy |
| 4 | **06 ∥ 07 ∥ 08** | Peak parallelism. Separate tables, separate services |
| 5 | **09 ∥ 10 ∥ 11** | 09 and 10 both read 07's `attempts`; neither alters it |
| 6 | **12 ∥ 13** | 13 administers what exists; 12 adds its own tables |
| 7 | — | 14 alone, everything else merged |

**Peak parallelism is 3 agents.** More than that and the integration surface outgrows review
capacity — features start blocking on each other's contracts faster than they finish.

---

## Rules that make the parallelism safe

**1. Contract first.** A feature's first commit publishes what others consume — the interface,
the API Resource shape, or the migration. 07 lands `attempts` before building its UI so 10 can
start. 04 publishes its interface signature so 05 and 07 can start. If you need something not
yet published, stub behind the interface and keep moving; never reach into a half-finished lane.

**2. One owner per table.** `docs/feature-plan.md` §6. Creating, altering, or seeding a table
you do not own is a scope violation. A foreign key into another feature's table is a dependency
— declare it.

**3. Never edit a shared file.** 01 builds four registries (routes, seeders, service providers,
navigation) precisely so no two agents touch one file. `docs/feature-plan.md` §7.

**4. Frozen after 01.** `app/Contracts/**`, `resources/js/anatomy/types.ts`,
`config/anatomy.php` (`FIT_SIZE`). Changes need human approval and update every consumer in
the same commit.

**5. Branch per feature.** `feat/f<NN>-<short-desc>`. Never commit to `main`.

---

## Two things every agent must know

**The models are single-mesh.** Every upstream GLB is one node, one mesh, one material —
Tripo AI output. There is no per-structure geometry, so `model_object_name` cannot work and
isolation, layers, and animation have reduced semantics by design, not by oversight.
`docs/project-context.md` §2.2 and `docs/architecture.md` §5.3. Structure selection works
through `anchor_position` in `FIT_SIZE = 3.8` pivot space.

**The upstream repository has no licence.** No `LICENSE` file; the GitHub API reports
`license: null`. That is all-rights-reserved by default — 3,158 stars and 837 forks grant
nothing. Handover 02 owns resolving it, and it gates public deployment in handover 14. Until
it clears, treat every reuse of upstream code or assets as provisional.
`docs/project-context.md` §3.

---

## Start here

1. `git init` a fresh repository — this directory is currently untracked inside an unrelated
   repo (`docs/project-context.md` §4). Committing before this pushes into someone else's project.
2. Start **01** and **02** together.
3. Nothing else begins until 01 merges.
