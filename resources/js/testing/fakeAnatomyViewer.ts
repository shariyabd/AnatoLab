import { createStructure } from '@/anatomy/testing/fixtures'
import type { SimulationState } from '@/anatomy/simulation'
import type { OrganDto, StructureId, Unsubscribe } from '@/anatomy/types'

/** What `applySimulationState` receives. Aliased so the recorder can name it. */
type SimulationStateLike = SimulationState

/**
 * A stand-in for `AnatomyViewer`, for tests of the code around it.
 *
 * The viewer library has its own suite under `resources/js/anatomy/`. Anything
 * above the bridge — the composable, ViewerStage, a page — wants to assert
 * *wiring*: that the viewer was built once, told the right things, and
 * disposed. Booting Three.js to find that out is slow and makes a WebGL
 * problem look like a Vue problem.
 *
 * Shared rather than copied into each test file so that "what the viewer does
 * when you call this" is described in one place. It mirrors two real
 * behaviours that tests depend on:
 *
 * - `selectStructure` always emits `structure:selected`, including for null.
 * - `loadOrgan` emits `organ:loaded` and does **not** emit a selection change,
 *   which is why the host has to clear selection itself when switching organs.
 * - With `hasHotspots` false, `focusStructure` records the call and emits
 *   nothing. That is the real viewer with no model attached — `structureById`
 *   finds no marker and returns early — and it is the state every organ is in
 *   until real GLBs land (docs/licence-log.md §3).
 */
export interface FakeAnatomyViewer {
  readonly container: HTMLElement
  readonly options: { reducedMotion: boolean; lowPower: boolean; initialMode: string }
  readonly calls: string[]
  /** Every applySimulationState payload, in order. See the method for why. */
  readonly simulationStates: SimulationStateLike[]
  disposeCount: number
  isAvailable: boolean
  hasHotspots: boolean
  screenPosition: { x: number; y: number; visible: boolean } | null
  emit(event: string, payload: unknown): void
}

/** Every fake constructed since the last `resetFakeViewers()`, in order. */
export const fakeViewers: FakeAnatomyViewer[] = []

export function resetFakeViewers(): void {
  fakeViewers.length = 0
}

/** The most recently constructed fake. Throws rather than returning undefined. */
export function lastFakeViewer(): FakeAnatomyViewer {
  const viewer = fakeViewers.at(-1)
  if (viewer === undefined) throw new Error('No viewer was constructed.')
  return viewer
}

/** The module body for `vi.mock('@/anatomy', …)`. */
export function createFakeAnatomyModule(): { AnatomyViewer: unknown } {
  type Handler = (payload: unknown) => void

  class FakeViewer implements FakeAnatomyViewer {
    readonly handlers = new Map<string, Set<Handler>>()
    readonly calls: string[] = []
    readonly simulationStates: SimulationStateLike[] = []
    disposeCount = 0
    isAvailable = true
    hasHotspots = true
    screenPosition: { x: number; y: number; visible: boolean } | null = {
      x: 120,
      y: 64,
      visible: true,
    }

    constructor(
      readonly container: HTMLElement,
      readonly options: { reducedMotion: boolean; lowPower: boolean; initialMode: string },
    ) {
      fakeViewers.push(this)
    }

    on(event: string, handler: Handler): Unsubscribe {
      const set = this.handlers.get(event) ?? new Set<Handler>()
      set.add(handler)
      this.handlers.set(event, set)
      return () => set.delete(handler)
    }

    emit(event: string, payload: unknown): void {
      for (const handler of [...(this.handlers.get(event) ?? [])]) handler(payload)
    }

    async loadOrgan(organ: OrganDto): Promise<void> {
      this.calls.push(`loadOrgan:${organ.slug}`)
      this.emit('organ:loaded', { organ, triangles: 10, loadMs: 1 })
    }

    dispose(): void {
      this.disposeCount += 1
    }

    selectStructure(id: StructureId | null): void {
      this.calls.push(`selectStructure:${String(id)}`)
      this.emit('structure:selected', {
        structure: id === null ? null : createStructure({ id }),
      })
    }

    focusStructure(id: StructureId): void {
      this.calls.push(`focusStructure:${id}`)
      if (!this.hasHotspots) return
      this.selectStructure(id)
    }

    highlightStructure(id: StructureId | null): void {
      this.calls.push(`highlightStructure:${String(id)}`)
    }

    // Added by Handover 07: quiz feedback is a ring on the marker, and the
    // whole point of the assertion is *which* id was rung in *which* colour —
    // a miss flashes the pick red and the answer green
    // (docs/project-context.md §2.4).
    flashStructure(id: StructureId, correct: boolean): void {
      this.calls.push(`flashStructure:${id}:${String(correct)}`)
    }

    isolateStructure(id: StructureId | null): void {
      this.calls.push(`isolateStructure:${String(id)}`)
    }

    prefetchOrgan(modelUrl: string): void {
      this.calls.push(`prefetchOrgan:${modelUrl}`)
    }

    setAutoRotate(enabled: boolean): void {
      this.calls.push(`setAutoRotate:${String(enabled)}`)
    }

    setLayer(layer: string): void {
      this.calls.push(`setLayer:${layer}`)
    }

    setMode(mode: string): void {
      this.calls.push(`setMode:${mode}`)
    }

    zoom(direction: number): void {
      this.calls.push(`zoom:${String(direction)}`)
    }

    resetView(): void {
      this.calls.push('resetView')
    }

    // Added by Handover 12. The assertion this exists for is that the server's
    // `visualDirectives` object reaches the viewer *unchanged* — the page maps
    // no key and computes no value — so the payload is recorded whole rather
    // than summarised into the `calls` string list
    // (docs/architecture.md §12).
    applySimulationState(state: SimulationStateLike): void {
      this.calls.push(`applySimulationState:${String(state.visualDirectives.tint ?? 'none')}`)
      this.simulationStates.push(state)
    }

    getStructureScreenPosition(): { x: number; y: number; visible: boolean } | null {
      return this.screenPosition
    }
  }

  return { AnatomyViewer: FakeViewer }
}
