# Handover 05 — Explore Experience

**Feature:** F05 · **Lane:** Anatomy + Viewer · **Wave:** 3 · **Branch:** `feat/f05-explore-experience`

> Read first: `docs/architecture.md` §5.1, §5.4 · PRD §6 · `docs/project-context.md` §2.5

## Objective

Wire the viewer to the anatomy API into the page a student actually uses — and establish the
mounting pattern that F06, F07, F09, and F12 will copy. This is the first integration point
and the first thing a judge sees.

## Scope

- `resources/js/Pages/Explore.vue` and its child components
- Organ library panel, structure callout, info panel, tool rail, screen-reader structure index
- `Web/ExploreController` and its Inertia props

## Out of scope

Quiz, lesson, mission, simulation, and AI logic. You render slots for them; you implement
none of them. You also do not change the viewer library or the anatomy API — if either is
wrong, raise it with its owner.

## Dependencies / prerequisites

**F03 and F04 merged.** F02 supplies real models; placeholders work for layout.

## Existing code to reuse

The upstream `AnatomyApp.tsx` / `OrganViewer.tsx` and `globals.css` as **visual reference
only** — the layout, information density, and design language are good and worth harvesting
(`docs/project-context.md` §5.2). Do not port the React or import the 37 KB stylesheet
wholesale into a Tailwind build.

Reproduce two things from the upstream that are genuinely well-designed:
- The callout is positioned **imperatively** by the viewer, so a spinning model costs zero
  Vue re-renders.
- The dots are mirrored as a real `<ul>` structure index for screen readers.

## Ownership boundaries

**You own** `resources/js/Pages/Explore.vue`, its components, and
`routes/features/explore.php`.

**You must not** edit `resources/js/anatomy/**`, `AnatomyService`, or the anatomy Resources.

## Required implementation

- Mount the viewer through `useAnatomyViewer` — **the only bridge.** Nothing else imports
  from `resources/js/anatomy/`.
- Organ library: list, thumbnails, switch organ, prefetch on hover.
- Structure selection → callout with name, TA term, description, function.
- Info panel: organ metadata, related structures.
- Tool rail: rotate, zoom, **pan**, reset, isolate, cross-section, layer. **Label each tool
  for what it actually does** — the upstream's "Isolate" only faded the plinth and its
  "Layers" meant wireframe. Hide or disable a control the viewer reports as degraded.
- Text fallback: with WebGL unavailable or a model 404, the structure list, descriptions, and
  organ metadata stay fully usable.

## Frontend constraints

- The viewer owns the canvas; Vue owns everything else.
- No Three.js object in a `ref()`/`reactive()`.
- Dispose the viewer on unmount.
- Keyboard: every structure reachable and selectable without a pointer.
- Honour `prefers-reduced-motion`.

## Tests

- Playwright: open an organ, rotate, select a structure, read its explanation
- Playwright: WebGL disabled → text fallback renders, no error surface
- Feature test: the Inertia page receives the organ list without N+1
- Vitest: unmounting the page disposes the viewer

## Acceptance criteria

1. PRD §44 steps 2–5 work end to end: open organ, interact, select structure, read explanation.
2. Switching organs is smooth; the previous organ's resources are released.
3. Every tool does what its label says, or is not shown.
4. Fully usable with WebGL off and with keyboard only.

## Constraints and guardrails

- Set the mounting pattern deliberately — four features copy it. Document it in the PR.
- No business logic in the template (`docs/engineering.md` §4).
- No `is_correct` or any correctness field anywhere in this page.

## Definition of Done

`docs/engineering.md` §11, plus the mounting pattern documented for downstream features.

## Commit boundary

`feat(explore): page shell and viewer mount` → `feat(explore): organ library and switching`
→ `feat(explore): structure callout and info panel` → `feat(explore): tool rail` →
`feat(explore): accessibility fallback` → `test(explore): journey coverage`.
