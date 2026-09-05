# Delivered handovers

One document per feature lane, written **after** the lane shipped. The sibling files one
directory up (`../NN-*.md`) are the *contracts* — what each lane was asked to build. These
are the *deliveries* — what it actually built, what it decided, and what it left open.

Read a contract to understand intent; read its delivery to understand the code.

| # | Delivered | Branch | Merged as |
|---|---|---|---|
| 01 | *(no delivery doc — see the merge commit)* | `feat/f01-platform-foundation` | `merge(f01)` |
| 02 | [Asset Pipeline & Licence](02-asset-pipeline-licence.md) | `feat/f02-asset-pipeline` | `merge(f02)` |
| 03 | [Anatomy Domain & API](03-anatomy-domain-api.md) | `feat/f03-anatomy-domain-api` | `merge(f03)` |
| 04 | [Viewer Core Library](04-viewer-core-library.md) | `feat/f04-viewer-core` | `merge(f04)` |
| 05 | [Explore Experience](05-explore-experience.md) | `feat/f05-explore-experience` | `merge(f05)` |
| 06 | [Lessons](06-lessons.md) | `feat/f06-lessons` | `merge(f06)` |
| 07 | [Assessment Engine](07-assessment-engine.md) | `feat/f07-assessment-engine` | `merge(f07)` |
| 08 | [AI Tutor Core](08-ai-tutor-core.md) | `feat/f08-ai-tutor-core` | `merge(f08)` |
| 09 | [Missions](09-missions.md) | `feat/f09-missions` | `merge(f09)` |
| 10 | [Progress & Mastery](10-progress-mastery.md) | `feat/f10-progress-mastery` | `merge(f10)` |
| 11 | [RAG & Knowledge Base](11-rag-knowledge-base.md) | `feat/f11-rag-knowledge-base` | `merge(f11)` |
| 12 | [Simulations](12-simulations.md) | `feat/f12-simulations` | `merge(f12)` |
| 13 | [Admin & Content](13-admin-content.md) | `feat/f13-admin-content` | `merge(f13)` |
| 14 | [Demo Journey & Polish](14-demo-polish.md) | `feat/f14-demo-polish` | `merge(f14)` |

## Merge order

Lanes were merged in dependency order, not numeric order — 08 lands before 06/07 because
its declared dependencies (01, 03) closed a batch earlier, and 11 lands after 07 because it
inserts at 08's retrieval seam and must never run alongside it:

```
02 → 03 → 04 → 05 → 08 → 06 → 07 → 11 → 09 → 10 → 12 → 13 → 14
```

The reasoning, including the nine undeclared cross-lane dependencies that set this order,
is in [`../parallel-execution-plan.md`](../parallel-execution-plan.md).

## Two published contracts

Two lanes published an interface other lanes coded against. They live beside the handover
contracts rather than here, because they are inputs to later lanes, not records of a
finished one:

- [`../04-viewer-interface.md`](../04-viewer-interface.md) — the viewer signature 05, 07, 09, 12 and 13 drive
- [`../08-retrieval-seam.md`](../08-retrieval-seam.md) — where 11 inserted retrieval into the tutor
