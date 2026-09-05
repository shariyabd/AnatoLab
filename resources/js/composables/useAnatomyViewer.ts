/**
 * The single bridge between Vue and the 3D viewer.
 *
 * `resources/js/anatomy/` is a standalone library that imports no framework and
 * never fetches. This file is the **only** place in `resources/js` allowed to
 * import from it (docs/architecture.md §5.1, docs/engineering.md invariant 3).
 * Pages get the viewer through this composable and nothing else.
 *
 * Two rules shape the code below:
 *
 * - **Three.js objects are never made reactive.** The viewer instance lives in a
 *   plain closure variable, not a `ref`. Wrapping a scene graph in a Vue proxy
 *   means every matrix write goes through a Proxy trap sixty times a second —
 *   a known performance disaster (docs/architecture.md §5.1, invariant 6).
 *   Only plain, serialisable state is exposed reactively.
 * - **Disposal on every unmount path.** `onBeforeUnmount` and `onScopeDispose`
 *   both fire the same idempotent teardown, so a component destroyed by a
 *   keep-alive eviction or a failed setup frees its GPU resources too
 *   (docs/architecture.md §5.4 rule 6).
 */

import { onBeforeUnmount, onMounted, onScopeDispose, ref, shallowRef, type Ref } from 'vue'
import { AnatomyViewer } from '@/anatomy'
import type { AnimationStep } from '@/anatomy/animation'
import type { SimulationState } from '@/anatomy/simulation'
import type {
  CapabilityDegraded,
  OrganDto,
  StructureDto,
  StructureId,
  Unsubscribe,
  ViewerEventMap,
  ViewerEventName,
  ViewerFailureReason,
  ViewerLayer,
  ViewerMode,
} from '@/anatomy/types'

type EventHandlers = {
  [E in ViewerEventName]?: (payload: ViewerEventMap[E]) => void
}

export interface UseAnatomyViewerOptions {
  /** The element the canvas is appended to. Bound with `ref="…"` on a div. */
  readonly container: Ref<HTMLElement | null>
  /** Loaded on mount and again whenever this changes. */
  readonly organ?: Ref<OrganDto | null>
  readonly initialMode?: ViewerMode
  readonly lowPower?: boolean
  /**
   * Forces the reduced-motion branch on. Omitted, the composable reads
   * `prefers-reduced-motion` from the browser — the audited implementation
   * honoured neither and auto-rotated by default (docs/project-context.md §5.1).
   */
  readonly reducedMotion?: boolean
  readonly on?: EventHandlers
}

export interface ViewerFailure {
  readonly reason: ViewerFailureReason
  readonly detail: string
}

export function useAnatomyViewer(options: UseAnatomyViewerOptions) {
  // Deliberately not a ref. See the file header.
  let viewer: AnatomyViewer | null = null
  let subscriptions: Unsubscribe[] = []
  let disposed = false

  const isReady = ref(false)
  const isAvailable = ref(true)
  const isLoading = ref(false)
  const progress = ref(0)
  const failure = ref<ViewerFailure | null>(null)
  const degraded = ref<CapabilityDegraded | null>(null)
  /** `shallowRef`: a DTO is a plain frozen object, and deep-tracking it buys nothing. */
  const selectedStructure = shallowRef<StructureDto | null>(null)
  const hoveredStructure = shallowRef<StructureDto | null>(null)
  const structures = shallowRef<readonly StructureDto[]>([])

  function prefersReducedMotion(): boolean {
    if (options.reducedMotion !== undefined) return options.reducedMotion
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
  }

  function subscribe(): void {
    if (viewer === null) return
    const instance = viewer

    subscriptions.push(
      instance.on('structure:selected', (payload) => {
        selectedStructure.value = payload.structure
        options.on?.['structure:selected']?.(payload)
      }),
      instance.on('structure:hovered', (payload) => {
        hoveredStructure.value = payload.structure
        options.on?.['structure:hovered']?.(payload)
      }),
      instance.on('structure:picked', (payload) => {
        options.on?.['structure:picked']?.(payload)
      }),
      instance.on('organ:loaded', (payload) => {
        isLoading.value = false
        progress.value = 1
        structures.value = payload.organ.structures
        failure.value = null
        options.on?.['organ:loaded']?.(payload)
      }),
      instance.on('load:progress', (payload) => {
        progress.value = payload.total > 0 ? payload.loaded / payload.total : 0
        options.on?.['load:progress']?.(payload)
      }),
      instance.on('load:failed', (payload) => {
        isLoading.value = false
        failure.value = payload
        options.on?.['load:failed']?.(payload)
      }),
      instance.on('webgl:unavailable', (payload) => {
        isAvailable.value = false
        options.on?.['webgl:unavailable']?.(payload)
      }),
      instance.on('capability:degraded', (payload) => {
        degraded.value = payload
        options.on?.['capability:degraded']?.(payload)
      }),
      instance.on('author:point', (payload) => {
        options.on?.['author:point']?.(payload)
      }),
    )
  }

  async function loadOrgan(organ: OrganDto): Promise<void> {
    if (viewer === null) return
    isLoading.value = true
    progress.value = 0
    failure.value = null
    // The structure list is populated up front, not on `organ:loaded`: it is the
    // text fallback, and it has to be on screen even when the model never
    // arrives (docs/architecture.md §5.4 rule 5, PRD §31).
    structures.value = organ.structures
    await viewer.loadOrgan(organ)
  }

  function teardown(): void {
    if (disposed) return
    disposed = true
    for (const unsubscribe of subscriptions) unsubscribe()
    subscriptions = []
    viewer?.dispose()
    viewer = null
    isReady.value = false
  }

  onMounted(() => {
    const container = options.container.value
    if (container === null) return

    viewer = new AnatomyViewer(container, {
      reducedMotion: prefersReducedMotion(),
      lowPower: options.lowPower ?? false,
      initialMode: options.initialMode ?? 'explore',
    })

    subscribe()
    isAvailable.value = viewer.isAvailable
    isReady.value = true

    const organ = options.organ?.value
    if (organ !== null && organ !== undefined) void loadOrgan(organ)
  })

  onBeforeUnmount(teardown)
  onScopeDispose(teardown)

  return {
    // state
    isReady,
    isAvailable,
    isLoading,
    progress,
    failure,
    degraded,
    selectedStructure,
    hoveredStructure,
    structures,

    // commands — thin pass-throughs; the viewer owns all 3D state
    loadOrgan,
    prefetchOrgan: (modelUrl: string): void => viewer?.prefetchOrgan(modelUrl),
    selectStructure: (id: StructureId | null): void => viewer?.selectStructure(id),
    highlightStructure: (id: StructureId | null): void => viewer?.highlightStructure(id),
    focusStructure: (id: StructureId): void => viewer?.focusStructure(id),
    isolateStructure: (id: StructureId | null): void => viewer?.isolateStructure(id),
    flashStructure: (id: StructureId, correct: boolean): void =>
      viewer?.flashStructure(id, correct),
    resetView: (): void => viewer?.resetView(),
    zoom: (direction: 1 | -1): void => viewer?.zoom(direction),
    setAutoRotate: (enabled: boolean): void => viewer?.setAutoRotate(enabled),
    setLayer: (layer: ViewerLayer): void => viewer?.setLayer(layer),
    setCrossSection: (enabled: boolean, axis?: 'x' | 'y' | 'z', offset?: number): void =>
      viewer?.setCrossSection(enabled, axis, offset),
    setMode: (mode: ViewerMode): void => viewer?.setMode(mode),
    triggerAnimation: async (id: string, steps?: readonly AnimationStep[]): Promise<void> =>
      viewer?.triggerAnimation(id, steps),
    applySimulationState: (state: SimulationState): void => viewer?.applySimulationState(state),
    getStructureScreenPosition: (id: StructureId) => viewer?.getStructureScreenPosition(id) ?? null,

    /** Escape hatch for an event the composable does not surface as state. */
    on: <E extends ViewerEventName>(
      event: E,
      handler: (payload: ViewerEventMap[E]) => void,
    ): Unsubscribe => {
      const unsubscribe = viewer?.on(event, handler)
      if (unsubscribe === undefined) return () => undefined
      subscriptions.push(unsubscribe)
      return unsubscribe
    },
  }
}
