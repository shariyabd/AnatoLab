# Handover 05 — Explore Experience (delivered)

**Branch** `feat/f05-explore-experience` · **Contract** [`05-explore-experience.md`](../05-explore-experience.md) · **Status** delivered

## What shipped

The first page that puts the viewer library (04) and the anatomy API (03) together: an
authenticated Explore page with an organ library, a 3D stage, a structure callout, an info
panel and a tool rail — and, more importantly for the rest of the project, the viewer
mounting pattern that 06, 07, 09 and 12 reuse verbatim.

- Browse published organs, switch between them without reloading the page or the WebGL context
- Select a structure from the model or from a keyboard-reachable list; read its name, TA term,
  description and function
- Info panel with organ metadata and related structures, fetched lazily and never gating the panel
- Tool rail: auto-rotate, zoom in/out, reset, isolate, and one Surface / Wireframe / Cut-through
  radio group
- Full text path: with WebGL unavailable or the model 404ing, the structure list, callout text
  and metadata stay usable
- `prefers-reduced-motion` honoured reactively, including mid-session changes
- Vitest was made able to parse `.vue` files at all (`vitest.config.ts` gained `@vitejs/plugin-vue`)

## Public surface

### Routes (`routes/features/explore.php`)

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/explore` | `explore.index` | `Web\ExploreController@index` |
| GET | `/explore/{organ}` | `explore.show` | `Web\ExploreController@show` |

Both are inside `auth`. `{organ}` is constrained to `[a-z0-9-]+`. Both render the same Inertia
component `Explore`, which is what makes an organ switch a partial reload.

### Components and composables

| Class / file | Responsibility |
|---|---|
| `Components/Anatomy/ViewerStage.vue` | The viewer mount. Owns the container, the callout RAF loop, degraded-capability collection, and the exposed command surface |
| `Components/Explore/OrganLibrary.vue` | Organ picker; warms a GLB on `pointerenter` and `focusin` |
| `Components/Explore/StructureIndex.vue` | The hotspot dots mirrored as real buttons — always on screen, not a fallback |
| `Components/Explore/StructureCallout.vue` | Presentational card; never sees a coordinate |
| `Components/Explore/InfoPanel.vue` | Organ metadata + selected structure + related structures |
| `Components/Explore/ToolRail.vue` | Camera/render controls, each labelled for what it does |
| `composables/useStructureDetail.ts` | `GET /api/v1/anatomy/structures/{id}`, memoised, out-of-order-safe, non-fatal on failure |
| `composables/usePrefersReducedMotion.ts` | Reactive OS motion preference |
| `testing/fakeAnatomyViewer.ts` | Shared viewer stand-in for every lane's Vitest suite |
| `Http/Resources/Explore/ExploreOrganResource.php` | `OrganSummaryResource` + `modelUrl`, for prefetch |

### Client contract (`resources/js/types/explore.ts`)

Re-exports `OrganDto`, `StructureDto`, `StructureId`, `ViewerLayer`, `CapabilityDegraded` from
`@/anatomy/types` (type-only, the one sanctioned exception to invariant 3), and adds
`ExploreOrganCard` (`id, slug, name, scientificName, description, accentColor, thumbnailUrl,
modelUrl, bodySystem?, structureCount?`), `RelatedStructure`, and `StructureDetail`
(a superset of `StructureDto`).

## The mounting pattern (copied by 06, 07, 09, 12)

`resources/js/Components/Anatomy/ViewerStage.vue` is the pattern, and its docblock is its
specification. A page passes `organ`, optionally `mode` / `reducedMotion` / `showCallout`,
puts lane chrome in the `overlay` slot, and drives everything else through `defineExpose`
(`focusStructure`, `highlightStructure`, `flashStructure`, `prefetchOrgan`, `zoom`,
`resetView`, `setLayer`, `setAutoRotate`, `toggleIsolate`, `setMode`, `setCrossSection`,
`triggerAnimation`, `applySimulationState`, plus read-only `isAvailable`, `failure`,
`degraded`, `canInteract`).

Five rules, all load-bearing:

1. `useAnatomyViewer` is the only runtime bridge to `resources/js/anatomy/`.
2. `#surface` (`<div ref="surface">`) has no Vue children — everything else is a sibling.
3. No Three.js object is reactive; the viewer lives in a closure inside the composable.
4. The callout is positioned by writing `style.transform` inside a `requestAnimationFrame`
   loop, so a spinning model costs zero Vue re-renders.
5. **Disposal is guaranteed by the composable, not by the host.** `useAnatomyViewer`
   registers `teardown` on both `onBeforeUnmount` and `onScopeDispose` and guards it with a
   `disposed` flag. `ViewerStage` adds only `onBeforeUnmount(stopTracking)` for the RAF loop
   the composable does not know about. Switching organs is a `loadOrgan` call on the live
   viewer, never a `v-if` or a `:key` that remounts.

At page level, `Pages/Explore.vue` adds the other half: `router.visit('/explore/{slug}',
{ only: ['organ'], preserveState: true })`, so the Vue tree, the WebGL context and the decoded
model cache all survive an organ switch.

## Key decisions

- **Both routes render one Inertia component**, so an organ switch is a partial reload — same
  component, new `organ` prop, viewer never remounted (`routes/features/explore.php`).
- **Both Inertia props are closures and both are pre-serialised to plain arrays** by
  `ExploreController::payload()`, because Inertia resolves a `JsonResource` prop recursively and
  the organ would otherwise arrive as `organ.structures.data` — the object handed to
  `useAnatomyViewer` must be exactly `OrganDto`, with no unwrapping step for four lanes to remember.
- **`index` re-reads the first organ by slug** rather than rendering the library row, because the
  library query loads only a body system and a structure count and `OrganResource` omits
  `structures` when unloaded — handing that row to the viewer would produce an organ with no hotspots.
- **Selection is page state, mirrored into the viewer, never read out of it**, so the list, callout
  and panel keep working when there is no WebGL and no model to hold a selection.
- **The structure index is not a fallback** — it is always on screen, so the keyboard path and the
  no-WebGL path are the same path and neither can rot.
- **Pan is documented, not buttoned**, and cross-section is `setLayer('section')` inside one radio
  group rather than a separate toggle: the viewer exposes no programmatic pan, and two switches over
  one piece of state could show a combination the viewer cannot be in.
- **`ExploreOrganResource` composes `OrganSummaryResource`** instead of copying its keys, because
  handover 03 owns the anatomy Resources and this lane does not edit them.

## Invariants honoured

- **3 — the viewer library stays standalone.** Only `useAnatomyViewer` is imported at runtime;
  `types/explore.ts` re-exports `@/anatomy/types` type-only (erased at build).
  `ViewerStage.test.ts` "construction" asserts the viewer is built once with a container Vue does
  not render into.
- **4 — no answer key on this page.** `ExplorePageTest` → *"carries no answer key"* asserts
  `toCarryNoAnswerKey()` over the Inertia page props.
- **7 — thin controller.** `ExploreController` has no FormRequest (nothing arrives but a slug),
  delegates to `AnatomyService`, returns Resources.
- **8 — templates display only.** All fetching is in `useStructureDetail`; step/tool logic lives
  in `<script setup>`, not the template.
- Viewer disposal (`docs/architecture.md` §5.4 rule 6): `ViewerStage.test.ts` "disposal" and
  `Explore.test.ts` *"disposes the viewer when the page unmounts"*.

## Tests

| File | Proves |
|---|---|
| `tests/Feature/Explore/ExplorePageTest.php` (13 tests) | Auth gate; first-organ default; published-only library and structures; `modelUrl` on every card; empty state; deep link; 404 on draft/unknown slug; unwrapped `OrganDto` (`missing('organ.data')`); no answer key; query count identical with 2 and 8 organs; a partial reload re-serialises `organ` only |
| `resources/js/Components/Anatomy/ViewerStage.test.ts` | Construction, organ switching without a second viewer, selection clearing, disposal + RAF cancellation, imperative callout transform, dimming on a turned-away marker, failure paths, degraded-capability accumulation, reduced-motion refusal |
| `resources/js/Pages/Explore.test.ts` | Viewer lifecycle across a partial reload, hover prefetch, keyboard selection, the no-WebGL text path, the empty-library page |
| `resources/js/composables/useStructureDetail.test.ts` | Memoisation, out-of-order response guard, non-throwing failure, clearing on deselect |
| `tests/Browser/explore.spec.ts` | Open an organ → select a structure → read its explanation; switch organs in-page; keyboard-only selection; WebGL disabled → text fallback |

## Known gaps / follow-ups

- No real GLBs exist yet (licence gate, `docs/licence-log.md` §3), so every organ runs the
  no-hotspot path in practice: `focusStructure` records the call and emits nothing. That is why
  `openStructure()` sets selection *before* asking the camera to move.
- The viewer exposes no programmatic pan, so the contract's "pan" tool has no button. Right-drag
  and two-finger drag work via `OrbitControls`; documented in `ToolRail.vue`, not surfaced.
- `Pages/Explore.vue` carries a tutor seat (`#explore-tutor-slot`) that was left empty by this
  lane; 08 built `TutorPanel` and **14** wired it in. The `useLearningEvents` analytics calls in
  the same file were added by **10**.
- The library payload has no `structures`, by design; a page that needs them must go through
  `/explore/{slug}` or the anatomy API.
