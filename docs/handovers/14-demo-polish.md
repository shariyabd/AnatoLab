# Handover 14 — Demo Journey & Competition Polish

**Feature:** F14 · **Lane:** All-hands · **Wave:** 7 · **Branch:** `feat/f14-demo-polish`

> Read first: PRD §2.3, §31, §32, §44, §45 · `docs/architecture.md` §15.1

## Objective

Turn a working set of features into a coherent product a judge understands in 3–5 minutes
without a technical explanation. This is where the project is won or lost.

## Scope

- Onboarding and first-run experience
- Demo dataset seeder — the exact content the demo journey uses
- Visual design pass across all pages
- Loading, empty, and error states everywhere
- Accessibility audit (WCAG 2.2)
- Performance verification against budget
- **One Playwright test walking PRD §44 end to end**
- Attribution page rendering F02's asset register

## Out of scope

New features. If something is missing, it is a gap in an earlier handover — raise it, do not
quietly build it here.

## Dependencies / prerequisites

**Every feature merged.** **F02's licence decision resolved** — nothing deploys publicly
without it (`docs/project-context.md` §3).

## Existing code to reuse

The upstream's visual design language is worth harvesting (`docs/project-context.md` §5.2) —
the atelier framing, the plinth, callout styling, tool rail, quiz bar. It is the reason the
original looks like a product rather than a demo. Adapt the language; do not import the 37 KB
stylesheet.

## Ownership boundaries

This is the one handover that touches everything — which is why it is **last and alone**. No
other feature is in flight. Coordinate any change to a feature's service or schema with that
feature's owner; prefer presentation-layer fixes.

## Required implementation

### The demo journey (PRD §2.3 / §44) — the spine

```
register → open the heart → rotate the model → select the left ventricle
→ read the contextual explanation → answer a spatial question
→ ask the tutor why the left ventricular wall is thicker → receive a grounded, cited answer
→ run a "what happens if" simulation → complete the Trace the Blood mission
→ see mastery update → receive a recommended next activity
```

Every step must work on a cold database seeded by the demo seeder. **One Playwright test
covers this whole path** — it is the release gate.

### Demo dataset

A seeder producing exactly the content the journey needs: the heart with ~8 structures, the
blood-circulation lesson, a spatial question on the left ventricle, the Trace the Blood
mission, the mitral valve simulation, and enough knowledge documents for a cited answer.
Deterministic and idempotent.

### Accessibility (PRD §31)

Keyboard navigation for all non-3D UI; every 3D interaction has a text or keyboard
equivalent; high contrast; screen-reader-friendly surrounding content;
`prefers-reduced-motion` honoured throughout; clear interaction feedback. Use the
`accessibility` skill for the WCAG 2.2 audit.

### Performance verification (`docs/architecture.md` §15.1)

| Budget | Target |
|---|---|
| Initial JS excl. Three.js chunk | < 200 KB gzip |
| First organ interactive | < 3 s, mid-range laptop |
| Per-organ payload / triangles | < 2 MB / < 150k |
| Idle frame cost | ~0 (render-on-demand intact) |
| Cached anatomy endpoints | < 100 ms |
| Tutor round-trip | < 6 s with a progressive indicator |

Measure and record actual numbers. "Feels fast" is not a result.

### Error states

Model load failure, WebGL unavailable, AI timeout, rate limit, no relevant sources, vector
store down, validation, authorization. **Never expose a provider name, status code, or stack
trace to a student.**

## Tests

- **The PRD §44 Playwright journey** — the single most important test in the project
- Playwright: WebGL disabled → the whole learning path still works via text
- Performance assertions on bundle size and per-organ payload
- Secret scan over `public/build` — no key prefix present
- Full `/verify` and `/boundary-audit` clean

## Acceptance criteria

1. A new student completes every step of PRD §44 on a freshly seeded database.
2. The Playwright journey passes on staging, not just locally.
3. Every performance budget met, with recorded numbers.
4. No accessibility blocker; reduced-motion respected.
5. Attribution page renders F02's register.
6. A judge understands the product without a technical explanation.

## Constraints and guardrails

- **Do not add features.** Polish only.
- Do not weaken a test or an assertion to make the journey pass.
- Staging must run the production path before demo day — the demo is never the first time
  the release path executes.
- The licence gate is absolute: no public deployment until F02 signs off.

## Definition of Done

`docs/engineering.md` §11, plus PRD §44 fully demonstrated end to end on staging.

## Commit boundary

`feat(demo): demo dataset seeder` → `feat(polish): loading and error states` →
`feat(polish): visual design pass` → `feat(polish): onboarding` →
`fix(a11y): WCAG 2.2 audit fixes` → `perf: budget verification and fixes` →
`test(demo): PRD §44 playwright journey`.
