# Handover 04 — Viewer Core Library (delivered)

**Branch** `feat/f04-viewer-core` · **Contract** [`04-viewer-core-library.md`](../04-viewer-core-library.md) · **Status** delivered

## What shipped

A framework-free 3D anatomy viewer in `resources/js/anatomy/`, plus the one Vue bridge that
consumes it. The library imports no Vue, no Inertia, no HTTP client, and never fetches — it
is handed a fully-formed `OrganDto`. It was **reimplemented from the technique notes in
`docs/project-context.md` §2.4, not adapted**: `docs/licence-log.md` §4 still records the
grant-vs-replace decision as *not yet taken*, so no upstream code was copied.

- Load, normalise, cache (LRU 3) and dispose organ models, with in-flight de-duplication and prefetch
- Authored-coordinate hotspots, surface-snapped at load and picked in screen space
- Four modes — `explore`, `quiz`, `mission`, `author` — with different click semantics each
- Camera control including **pan** (the audited implementation disabled it), fly-to focus, zoom, reset
- Render-on-demand: an idle viewer draws nothing; `IntersectionObserver` + `visibilitychange` gate it
- Honest degradation — `capability:degraded` on isolate, layers, cross-section and animation
- `prefers-reduced-motion` honoured, and `webgl:unavailable` instead of a blank canvas
- `useAnatomyViewer()` — reactive state plus a pass-through for every command

## Public surface

**The interface contract is `docs/handovers/04-viewer-interface.md`**, published in this
phase and frozen for consumers. It carries the full `AnatomyViewer` signature (§1), the event
table (§2), the reduced-semantics table (§3), the mode table (§4), the `SimulationState`
shape for Handover 12 (§5), the composable's options and returns (§6), and the six rules a
consumer must not break (§7). Read that; the tables below are only the module map.

### Modules

| File | Responsibility |
|---|---|
| `anatomy/AnatomyViewer.ts` | Scene, camera, lights, controls, modes, render-on-demand loop, every public method (1,340 lines) |
| `anatomy/AssetManager.ts` | Load, normalise into the `FIT_SIZE` cube, LRU cache of 3, in-flight de-dup, prefetch, disposal |
| `anatomy/HotspotLayer.ts` | Surface snapping with direction-cone filtering, billboard markers, screen-space picking, occlusion fade, flash feedback |
| `anatomy/dispose.ts` | Deep disposal of a Three subtree, including textures found by property walk |
| `anatomy/emitter.ts` | `TypedEmitter` keyed on `ViewerEventMap`; a `Set` per event, `on()` returns an `Unsubscribe` |
| `anatomy/webgl.ts` | `probeWebGL()` — WebGL 2 only, returns `{ available, detail }` in words a UI can show |
| `anatomy/gltfLoader.ts` | Real `GLTFLoader` with Meshopt, reached only by dynamic import so the WASM blob never loads in tests |
| `anatomy/animation.ts` | `AnimationStep`, `GUIDED_TOUR_ID`, `buildGuidedTour()` — choreography, because there are no clips |
| `anatomy/simulation.ts` | `SimulationState` / `VisualDirectives` — the closed five-directive vocabulary for Handover 12 |
| `anatomy/index.ts` | The only import surface; nothing outside the composable may reach into a module path |
| `composables/useAnatomyViewer.ts` | The single Vue↔viewer bridge (220 lines) |
| `anatomy/testing/{dom,fakeRenderer,fixtures}.ts` | jsdom shims, a `WebGLRenderer` stand-in keeping three's own GPU bookkeeping, and `OrganDto` fixtures |

### Client contract

`useAnatomyViewer({ container, organ?, initialMode?, lowPower?, reducedMotion?, on? })`
returns reactive `isReady`, `isAvailable`, `isLoading`, `progress`, `failure`, `degraded`,
`selectedStructure`, `hoveredStructure`, `structures`; the commands `loadOrgan`,
`prefetchOrgan`, `selectStructure`, `highlightStructure`, `focusStructure`, `isolateStructure`,
`flashStructure`, `resetView`, `zoom`, `setAutoRotate`, `setLayer`, `setCrossSection`,
`setMode`, `triggerAnimation`, `applySimulationState`, `getStructureScreenPosition`; and an
`on()` escape hatch. The `AnatomyViewer` instance itself is never exposed.

## Key decisions

- **Reimplemented, not adapted.** "The techniques below come from our own audit notes
  (`docs/project-context.md` §2.4), which describe behaviour rather than code; §5 of the licence
  log confirms that is clean." This makes Handover 04 independent of Handover 02's open gate.
- **Selection is an authored coordinate, not geometry.** "A raycast against the heart returns
  'the heart', never 'the left ventricle'", so each structure is a coordinate snapped to the
  surface and drawn as a billboard, picked by screen-space distance.
- **Direction-cone filtering before nearest-vertex**, in one linear pass over the position
  buffer: nearest-vertex alone snaps an anchor onto the far wall of a hollow shell, and one walk
  per structure is "the difference between 8 ms and 60 ms on a mid-range phone."
- **Reduced semantics are announced, not hidden.** `isolateStructure`, `setLayer`,
  `setCrossSection` and `triggerAnimation` emit `capability:degraded` so a host page never
  labels a control with something it does not do — the exact failure the audit found upstream.
- **`loadOrgan` never rejects**; failure arrives as `load:failed` with a classified reason, so
  the page can fall back to text without a try/catch at every call site.
- **A baked contact shadow and a once-generated PMREM environment** instead of shadow maps —
  self-shadowing acne and a second render pass are avoided, and PMREM convolution is not paid per organ.
- **`ViewerDependencies` is a test seam, not part of the signature.** jsdom has no WebGL and no
  network; without injectable `createRenderer`/`createLoader`/`probeWebGL` the disposal test
  "could not run the real scene-graph, hotspot, and material code paths at all."
- **Three.js objects are never made reactive.** The viewer lives in a plain closure variable —
  "wrapping a scene graph in a Vue proxy means every matrix write goes through a Proxy trap
  sixty times a second."

## Invariants honoured

- **3 — `resources/js/anatomy/` imports no Vue, no Inertia, and never fetches.** A grep across
  every file in the directory for `from 'vue'`, `@inertiajs`, `axios`, `fetch(` and
  `XMLHttpRequest` returns nothing but the library's own `prefetch(` method name. The only
  imports are `three`, `gsap`, and sibling modules. `resources/js/anatomy/index.ts` and
  `useAnatomyViewer.ts` both state in their headers that the composable is the sole bridge, and
  `useAnatomyViewer.test.ts` drives the whole surface through it.
- **5 — `FIT_SIZE` is `3.8`.** `resources/js/anatomy/fitSizeParity.test.ts` reads
  `config/anatomy.php` as raw text and asserts `'fit_size'` equals the exported `FIT_SIZE`;
  `tests/Unit/FitSizeParityTest.php` asserts the same from the PHP side, and
  `scripts/verify-models.mjs` checks the pipeline's copy. `AnatomyViewer.test.ts` →
  *"normalises the model into the FIT_SIZE cube before hotspots attach"* proves the runtime
  path, and *"emits author:point in FIT_SIZE pivot space in author mode"* proves the write path
  Handover 13 authors coordinates through. Every camera distance derives from `FIT_SIZE` rather
  than a literal.
- **Contract acceptance 4 — nothing leaks.** `AnatomyViewer.test.ts` → *"returns renderer,
  geometry and texture counts to zero"* and *"leaks nothing across a second organ load"*, run
  against three's own `info.memory` bookkeeping reproduced in `testing/fakeRenderer.ts`.

## Tests

94 Vitest specs across eight files.

| File | What it proves |
|---|---|
| `anatomy/AnatomyViewer.test.ts` (33) | Load progress and classified failure; opaque-id round trip `loadOrgan` → `selectStructure` → event; per-mode click semantics including drag-vs-click and inert author markers; each of the four degraded capabilities announces itself; the closed simulation vocabulary is honoured and anything outside it ignored; reduced motion disables auto-rotate and easing; the loop stops once settled and wakes for a flash; `webgl:unavailable` instead of a blank canvas; disposal to zero, twice, idempotently |
| `anatomy/AssetManager.test.ts` (16) | `normaliseIntoFitCube`, per-node-instance triangle counting, LRU eviction, in-flight de-duplication, prefetch, disposal |
| `anatomy/HotspotLayer.test.ts` (18) | `snapAllToSurface` direction-cone behaviour and the marker layer's picking, occlusion and flash |
| `anatomy/dispose.test.ts` (5) | Materials and subtrees free every texture and buffer, including textures found by property walk |
| `anatomy/emitter.test.ts` (6) | Typed dispatch, duplicate registration, unsubscribe |
| `anatomy/fitSizeParity.test.ts` (1) | `FIT_SIZE` matches `config('anatomy.fit_size')` |
| `composables/useAnatomyViewer.test.ts` (13) | Construction against the bound container, reduced-motion pass-through, reactive mirroring of selection/progress/failure/degraded/availability, host handlers forwarded alongside internal state, command pass-through, disposal once across both unmount paths, inert after teardown |
| `composables/useAnatomyViewer.integration.test.ts` (2) | Without WebGL the structure list stays usable and the failure is reported in words; teardown still runs |

## Known gaps / follow-ups

- **No real model has ever been loaded.** Handover 02's manifest is `pending-licence` with an
  empty model set, so every test runs against the sphere fixture in `testing/fixtures.ts` and
  `gltfLoader.ts` has not been exercised against pipeline output. Handover 03's seeded
  `model_path` values are placeholders too.
- **Reduced semantics stay reduced until per-structure meshes land.** `isolateStructure` cannot
  hide geometry, `setLayer('wireframe')` is one material, `section` is one global clipping plane
  through a hollow shell, and `triggerAnimation` is choreography because
  `gltf.animations.length` is 0. All four gain full behaviour with no interface, API or schema
  change — which is the seam Handover 02's replacement path (CAN-03, per-structure GLB) would close.
- **KTX2 is opt-in and no transcoder is deployed.** `gltfLoader.ts` accepts a
  `ktx2TranscoderPath` but nothing sets one; Handover 02 excluded the upstream Basis payload
  deliberately, and reintroducing it is this lane's call once textures are genuinely KTX2.
- **GSAP is a hard dependency** for camera easing. `docs/asset-register.md` DEP-02 flags its
  bespoke non-OSI licence as unconfirmed for public deployment; the header of that row notes the
  easing is replaceable with a few lines of `requestAnimationFrame` if the terms fail.
- **Seams left open by design:** `simulation.ts` for **12**, `author:point` and
  `getStructureScreenPosition` for **13** and **05**, `flashStructure` / `focusStructure` for
  **07** and **09**. `04-viewer-interface.md` tells each of them not to reopen this lane.
- **`AnimationStep` and `SimulationState` live outside the frozen `types.ts`** (in
  `animation.ts` and `simulation.ts`) because that file is frozen and mirrors Handover 03's
  Resources. Consumers import them from `@/anatomy`, not from `@/anatomy/types`.
