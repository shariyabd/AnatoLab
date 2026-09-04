# Handover 04 — Viewer Core Library

**Feature:** F04 · **Lane:** Viewer · **Wave:** 2 · **Branch:** `feat/f04-viewer-core`

> Read first: `docs/architecture.md` §5 (all of it) · `docs/project-context.md` §2.2, §2.4, §2.5, §5.1

## Objective

Deliver a framework-free 3D anatomy viewer as a standalone library, exposing the interface in
`docs/architecture.md` §5.2. F05, F07, F09, and F12 all drive it.

## Scope

- `resources/js/anatomy/**` — `AnatomyViewer.ts`, `HotspotLayer.ts`, `AssetManager.ts`, `dispose.ts`
- `resources/js/composables/useAnatomyViewer.ts` — the single Vue↔viewer bridge
- Vitest suite

## Out of scope

Any Vue page, any PHP, any network call, any quiz/lesson/simulation logic. You provide the
capability; other features orchestrate it.

## Dependencies / prerequisites

**F01 merged** (for `types.ts`). You do **not** need F03 — code against the DTO types and test
with fixtures. F02 supplies real models; a placeholder GLB is fine until then.

## Existing code to reuse

**This is the one feature with substantial reusable prior art** — but only if F02 clears the
licence (`docs/project-context.md` §3). Confirm before copying.

Reuse with minimal change (audit §5.1):

| From | What is worth keeping |
|---|---|
| `hotspots.ts` | `snapToSurface()` — direction-cone filtering then nearest-vertex, so a dot never punches through to the far side. Screen-space picking (no per-frame mesh raycast). Occlusion fade via facing test. Quiz flash feedback |
| `loaders.ts` | `AnatomyAssetManager` — LRU cache (limit 3), in-flight de-duplication, low-priority prefetch, material conditioning, anisotropy on every sampled map, full disposal |
| `viewer.ts` | Render-on-demand loop (`dirty` + `busyUntil`), depth prepass for cross-fades, PMREM env map baked once, baked contact shadow instead of shadow maps, `IntersectionObserver` + `visibilitychange` gating |

**If F02 reports no licence grant:** the *techniques* above are not copyrightable — reimplement
them from the descriptions. Budget this as real work, not a rename.

Do **not** carry over: `tsl-materials.ts` (dead code, nothing imports it), the i18n layer, or
any React.

## Ownership boundaries

**You own** `resources/js/anatomy/**` and `useAnatomyViewer.ts`, exclusively.

**You must not** touch `app/`, any `.vue` page, or `types.ts` (frozen — you implement against it).

## Required implementation

### The interface

Implement every method in `docs/architecture.md` §5.2 plus the typed event emitter
(`structure:selected`, `structure:picked`, `structure:hovered`, `organ:loaded`,
`load:progress`, `load:failed`, `author:point`, `webgl:unavailable`).

### Reduced semantics are the contract, not a bug

The models are single-mesh. Per `docs/architecture.md` §5.3:

| Method | Under current assets |
|---|---|
| `isolateStructure` | Dim other markers, fade organ to ~35%, fly camera to the structure. **Cannot hide geometry** |
| `setLayer('wireframe')` | Wireframe on the single material |
| `setLayer('section')` | One global clipping plane across the whole organ |
| `triggerAnimation` | **No GLTF clips exist** — scripted camera + marker choreography from a JSON step list |

Emit `capability:degraded` when a method cannot fully deliver, so the UI does not advertise a
control that will not do what its label says. **Do not reproduce the upstream's three
misleading tools** — its "Isolate" faded only the plinth, "Layers" meant wireframe, "Compare"
was a 2D drawer.

### What to add beyond the audit

- **Pan** — upstream sets `enablePan = false`; the PRD requires it
- `selectStructure(id)` and `focusStructure(id)` — programmatic; lessons and missions need them
- `prefers-reduced-motion` honoured (upstream has none, and auto-rotates by default)
- `webgl:unavailable` event — upstream renders a blank canvas with no message
- Typed emitter replacing the constructor callback bag
- `setMode('explore'|'quiz'|'mission'|'author')`

## Constraints and guardrails — 3D, non-negotiable

1. **`FIT_SIZE = 3.8`.** Every model is normalised into that cube and centred before hotspots
   attach. Changing it invalidates every `anchor_position` in the database. A test asserts the
   TS constant matches `config('anatomy.fit_size')`.
2. **Never fetch.** You receive a fully-formed `OrganDto`.
3. **Structure ids are opaque strings** — do not parse, derive, or construct them.
4. **Dispose on unmount, every path.**
5. **One WebGL context per page.** Never two viewers side by side.
6. Never make a Three.js object Vue-reactive. Plain module scope or `shallowRef`.

## Tests (Vitest, headless WebGL mock)

- Structure-id round-trip: `loadOrgan` → `selectStructure(id)` → event carries the same id
- **Disposal returns renderer, geometry, and texture counts to zero**
- `FIT_SIZE` parity with the PHP config
- `webgl:unavailable` fires when context creation fails
- `capability:degraded` fires for `isolateStructure` on a single-mesh model
- Reduced-motion disables auto-rotate and shortens tweens

## Acceptance criteria

1. Every §5.2 method implemented, with §5.3 semantics documented in the source.
2. Library imports nothing from Vue, Inertia, axios, or `app/`.
3. `/boundary-audit` reports no violation under `resources/js/anatomy/`.
4. Loading a fixture organ, selecting a structure, and disposing leaks nothing.

## Definition of Done

`docs/engineering.md` §11. Plus: publish the final interface signature in the PR — four
features drive it.

## Commit boundary

`feat(viewer): asset manager and disposal` → `feat(viewer): hotspot layer and picking` →
`feat(viewer): scene, controls, render-on-demand loop` → `feat(viewer): public interface and
event emitter` → `feat(viewer): Vue composable bridge` → `test(viewer): vitest suite`.
**Publish the interface signature in your first commit** so F05/F07 can start.
