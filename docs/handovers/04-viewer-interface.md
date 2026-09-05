# Handover 04 — Published viewer interface

**Branch:** `feat/f04-viewer-core` · **Status:** complete · **Frozen for consumers**

Handovers 05, 07, 09, 12 and 13 all drive this surface, and
`docs/handovers/parallel-execution-plan.md` C6 tells each of them not to reopen this lane.
Everything they were promised is here. If something is genuinely missing, raise it — do not
add a method.

Import path: **`@/composables/useAnatomyViewer` only.** Nothing outside that file may import
from `resources/js/anatomy/` (docs/architecture.md §5.1, docs/engineering.md invariant 3).

---

## 1. `AnatomyViewer` — the full surface

```ts
class AnatomyViewer {
  constructor(container: HTMLElement, options?: ViewerOptions, deps?: ViewerDependencies)

  // content
  loadOrgan(organ: OrganDto): Promise<void>          // never rejects; failure is an event
  prefetchOrgan(modelUrl: string): void
  dispose(): void

  // selection
  selectStructure(id: StructureId | null): void
  getSelectedStructure(): StructureDto | null
  highlightStructure(id: StructureId | null): void
  focusStructure(id: StructureId): void              // camera fly-to + select
  isolateStructure(id: StructureId | null): void     // reduced semantics — §3
  flashStructure(id: StructureId, correct: boolean): void

  // view
  resetView(): void
  zoom(direction: 1 | -1): void
  setAutoRotate(enabled: boolean): void
  setLayer(layer: 'solid' | 'wireframe' | 'section'): void          // reduced semantics — §3
  setCrossSection(enabled: boolean, axis?: 'x'|'y'|'z', offset?: number): void

  // modes
  setMode(mode: 'explore' | 'quiz' | 'mission' | 'author'): void
  getMode(): ViewerMode

  // learning
  triggerAnimation(id: string, steps?: readonly AnimationStep[]): Promise<void>  // §3
  applySimulationState(state: SimulationState): void

  // events + host integration
  on<E extends ViewerEventName>(event: E, handler: (payload: ViewerEventMap[E]) => void): Unsubscribe
  getStructureScreenPosition(id: StructureId): HotspotScreenPosition | null
  get isAvailable(): boolean
}
```

Two additions beyond `docs/architecture.md` §5.2, both deliberate:

- `getMode()` / `isAvailable` — read-back for a UI that has to reflect viewer state.
- `getStructureScreenPosition(id)` — `{ x, y, visible }` in canvas pixels, so a callout can be
  anchored to a marker imperatively while the model spins (docs/project-context.md §2.4). Without
  it F05 could not build the callout without reopening this lane.

`deps` is a test seam (`createRenderer`, `createLoader`, `probeWebGL`). Production code omits it.

## 2. Events

Every event and payload is defined in the frozen `resources/js/anatomy/types.ts`
(`ViewerEventMap`). `on()` is typed over the whole map, so the handler's parameter is inferred
from the event name.

| Event | Payload | Fires when |
|---|---|---|
| `structure:selected` | `{ structure: StructureDto \| null }` | selection changes, from any cause |
| `structure:picked` | `{ structure, pointer: {x,y} }` | a marker is clicked — quiz and mission consume this without selecting |
| `structure:hovered` | `{ structure: StructureDto \| null }` | pointer enters or leaves a marker |
| `organ:loaded` | `{ organ, triangles, loadMs }` | model decoded, normalised, hotspots attached |
| `load:progress` | `{ loaded, total }` | during a real network fetch only — not on a cache hit |
| `load:failed` | `{ reason, detail }` | `webgl-unavailable` · `model-not-found` · `decode-failed` · `network` |
| `author:point` | `{ position: Vec3 }` | author mode: a surface click, in FIT_SIZE pivot space |
| `webgl:unavailable` | `{ detail }` | one microtask after construction, so a caller can subscribe first |
| `capability:degraded` | `{ capability, reason }` | a method ran with reduced semantics — §3 |

## 3. Reduced semantics — the contract, not a bug list

The models are single-mesh with zero animation clips (docs/project-context.md §2.2). Three
methods therefore cannot fully deliver, and **say so** by emitting `capability:degraded` rather
than failing silently. Show the reason, or do not label the control.

| Call | What actually happens today |
|---|---|
| `isolateStructure(id)` | Dims other markers, fades the organ to 35 %, dims the plinth, flies the camera in. **Does not hide geometry.** `capability: 'isolate'` |
| `setLayer('wireframe')` | Wireframe on the one material. Not anatomical layers. `capability: 'layers'` |
| `setLayer('section')` / `setCrossSection(true)` | One global clipping plane; the models are hollow shells, so the cut exposes the inside of the shell. `capability: 'crossSection'` |
| `triggerAnimation(id, steps?)` | Scripted camera + marker choreography. `capability: 'animation'` always. Built-in id: `'tour'` (walks every structure). Any other id without `steps` does nothing but report why |

Each gains full behaviour when per-structure meshes land, with no change to this interface, the
API, or the schema.

## 4. Modes

| Mode | Marker click | Selection |
|---|---|---|
| `explore` | selects, emits `structure:picked` then `structure:selected` | sticky |
| `quiz` | emits `structure:picked` only | never set — a sticky highlight would give the answer away |
| `mission` | as `quiz`; drive steps with `focusStructure` / `flashStructure` | never set |
| `author` | markers inert; a click raycasts the mesh and emits `author:point` | cleared |

Leaving `explore` clears the selection.

## 5. `applySimulationState` (Handover 12)

```ts
interface SimulationState {
  state: Readonly<Record<string, number>>
  visualDirectives: VisualDirectives
  explanation?: string | null
}

interface VisualDirectives {
  highlight?: StructureId | null
  tint?: string | null                 // CSS colour, blended into the material
  pulseRate?: number | null            // marker pulse multiplier; 0 stops it
  focus?: StructureId | null
  crossSection?: { enabled: boolean; axis?: 'x'|'y'|'z'; offset?: number } | boolean | null
}
```

The vocabulary is **closed** to those five. Anything else is ignored, not approximated.

**Note for F12:** keys are camelCase here. `architecture.md` §12 writes the stored JSON config
as `pulse_rate` and `cross_section`; your API Resource converts them on the way out, the way
every other field in the contract is converted (`types.ts` header). Nothing on the viewer side
transforms keys at runtime.

## 6. `useAnatomyViewer` — the only way in

```ts
const viewer = useAnatomyViewer({
  container,            // Ref<HTMLElement | null>, bound with ref="" on a div
  organ,                // Ref<OrganDto | null>, loaded on mount
  initialMode,          // ViewerMode
  lowPower,             // boolean
  reducedMotion,        // boolean — omitted, it reads prefers-reduced-motion
  on: { 'structure:picked': handler, /* … any ViewerEventMap key */ },
})
```

Returns reactive state — `isReady`, `isAvailable`, `isLoading`, `progress`, `failure`,
`degraded`, `selectedStructure`, `hoveredStructure`, `structures` — plus a pass-through for
every command in §1 and an `on()` escape hatch. The viewer instance itself is **never** exposed
and never made reactive.

## 7. Rules a consumer must not break

1. **One WebGL context per page.** Never mount two viewers side by side.
2. **Dispose on unmount.** The composable does it for you; do not construct `AnatomyViewer`
   yourself.
3. **The viewer never fetches.** Hand it a fully-formed `OrganDto`.
4. **Structure ids are opaque.** Do not parse, derive, or construct one.
5. **`FIT_SIZE = 3.8`.** Every `anchorPosition` and every `author:point` is in that space.
6. **Handle `load:failed` and `webgl:unavailable`.** The structure list, description, and lesson
   content must stay usable without 3D (PRD §31, §40).

## 8. Licence position

Reimplemented from the technique descriptions in `docs/project-context.md` §2.4, not adapted:
`docs/licence-log.md` §4 still records the grant-vs-replace decision as *not yet taken*, and
`parallel-execution-plan.md` §3 C2 says to default to reimplementation absent a written grant.
No upstream code was copied. Techniques are not copyrightable (`licence-log.md` §5).
