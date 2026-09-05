import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref, type Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useAnatomyViewer } from './useAnatomyViewer'
import { createOrgan } from '@/anatomy/testing/fixtures'
import type { OrganDto, Unsubscribe, ViewerEventMap, ViewerEventName } from '@/anatomy/types'

/**
 * The composable's job is wiring, not rendering: construct the viewer on mount,
 * re-emit its events as reactive state, forward commands, dispose on unmount.
 * The viewer itself is covered by `resources/js/anatomy/AnatomyViewer.test.ts`, so
 * it is replaced here by a double that records what it was asked to do.
 */
const hoisted = vi.hoisted(() => ({ instances: [] as unknown[] }))

vi.mock('@/anatomy', () => {
  type Handler = (payload: unknown) => void

  class FakeViewer {
    static readonly created: FakeViewer[] = []
    readonly handlers = new Map<string, Set<Handler>>()
    readonly calls: string[] = []
    disposeCount = 0
    isAvailable = true

    constructor(
      readonly container: HTMLElement,
      readonly options: unknown,
    ) {
      hoisted.instances.push(this)
    }

    on(event: string, handler: Handler): Unsubscribe {
      const set = this.handlers.get(event) ?? new Set<Handler>()
      set.add(handler)
      this.handlers.set(event, set)
      return () => set.delete(handler)
    }

    emit(event: string, payload: unknown): void {
      for (const handler of this.handlers.get(event) ?? []) handler(payload)
    }

    async loadOrgan(organ: OrganDto): Promise<void> {
      this.calls.push(`loadOrgan:${organ.id}`)
    }

    dispose(): void {
      this.disposeCount += 1
    }

    selectStructure(id: string | null): void {
      this.calls.push(`selectStructure:${String(id)}`)
    }
    focusStructure(id: string): void {
      this.calls.push(`focusStructure:${id}`)
    }
    flashStructure(id: string, correct: boolean): void {
      this.calls.push(`flashStructure:${id}:${String(correct)}`)
    }
    setMode(mode: string): void {
      this.calls.push(`setMode:${mode}`)
    }
    prefetchOrgan(url: string): void {
      this.calls.push(`prefetchOrgan:${url}`)
    }
  }

  return { AnatomyViewer: FakeViewer }
})

interface FakeViewer {
  container: HTMLElement
  options: { reducedMotion: boolean; lowPower: boolean; initialMode: string }
  calls: string[]
  disposeCount: number
  isAvailable: boolean
  emit<E extends ViewerEventName>(event: E, payload: ViewerEventMap[E]): void
  handlers: Map<string, Set<unknown>>
}

type Composable = ReturnType<typeof useAnatomyViewer>

function mountHost(options: { organ?: Ref<OrganDto | null>; reducedMotion?: boolean } = {}) {
  let api: Composable | null = null

  const wrapper = mount(
    defineComponent({
      setup() {
        const container = ref<HTMLElement | null>(null)
        api = useAnatomyViewer({
          container,
          organ: options.organ,
          reducedMotion: options.reducedMotion,
        })
        return () => h('div', { ref: container })
      },
    }),
  )

  return { wrapper, api: api as unknown as Composable, viewer: viewerAt(-1) }
}

function viewerAt(index: number): FakeViewer {
  const instance = hoisted.instances.at(index)
  if (instance === undefined) throw new Error('No viewer was constructed.')
  return instance as FakeViewer
}

describe('useAnatomyViewer', () => {
  it('constructs the viewer against the bound container on mount', () => {
    hoisted.instances.length = 0
    const { wrapper, api, viewer } = mountHost()

    expect(hoisted.instances).toHaveLength(1)
    expect(viewer.container).toBe(wrapper.element)
    expect(api.isReady.value).toBe(true)
  })

  it('passes prefers-reduced-motion down to the viewer', () => {
    hoisted.instances.length = 0
    const { viewer } = mountHost({ reducedMotion: true })

    expect(viewer.options.reducedMotion).toBe(true)
  })

  it('loads the organ it was given', async () => {
    hoisted.instances.length = 0
    const organ = createOrgan()
    const { api, viewer } = mountHost({ organ: ref(organ) })

    expect(viewer.calls).toContain(`loadOrgan:${organ.id}`)
    // The structure list is populated before the model arrives: it is the text
    // fallback and has to be on screen even if the load never completes.
    expect(api.structures.value).toHaveLength(organ.structures.length)
  })

  it('mirrors selection into reactive state', () => {
    hoisted.instances.length = 0
    const { api, viewer } = mountHost()
    const structure = createOrgan().structures[0]!

    viewer.emit('structure:selected', { structure })
    expect(api.selectedStructure.value?.id).toBe(structure.id)

    viewer.emit('structure:selected', { structure: null })
    expect(api.selectedStructure.value).toBeNull()
  })

  it('tracks load progress and completion', () => {
    hoisted.instances.length = 0
    const organ = createOrgan()
    const { api, viewer } = mountHost()

    viewer.emit('load:progress', { loaded: 256, total: 1024 })
    expect(api.progress.value).toBeCloseTo(0.25, 5)

    viewer.emit('organ:loaded', { organ, triangles: 100, loadMs: 12 })
    expect(api.isLoading.value).toBe(false)
    expect(api.progress.value).toBe(1)
  })

  it('surfaces a load failure without throwing', () => {
    hoisted.instances.length = 0
    const { api, viewer } = mountHost()

    viewer.emit('load:failed', { reason: 'model-not-found', detail: 'Missing.' })

    expect(api.failure.value).toEqual({ reason: 'model-not-found', detail: 'Missing.' })
    expect(api.isLoading.value).toBe(false)
  })

  it('surfaces a degraded capability so the UI can relabel its control', () => {
    hoisted.instances.length = 0
    const { api, viewer } = mountHost()

    viewer.emit('capability:degraded', { capability: 'isolate', reason: 'Single mesh.' })

    expect(api.degraded.value?.capability).toBe('isolate')
  })

  it('marks the viewer unavailable when WebGL is not', () => {
    hoisted.instances.length = 0
    const { api, viewer } = mountHost()

    viewer.emit('webgl:unavailable', { detail: 'No context.' })

    expect(api.isAvailable.value).toBe(false)
  })

  it('forwards host-supplied handlers alongside its own state', () => {
    hoisted.instances.length = 0
    const picked = vi.fn()
    let api: Composable | null = null

    mount(
      defineComponent({
        setup() {
          const container = ref<HTMLElement | null>(null)
          api = useAnatomyViewer({ container, on: { 'structure:picked': picked } })
          return () => h('div', { ref: container })
        },
      }),
    )
    void api

    const structure = createOrgan().structures[0]!
    viewerAt(-1).emit('structure:picked', { structure, pointer: { x: 1, y: 2 } })

    expect(picked).toHaveBeenCalledOnce()
  })

  it('forwards commands to the viewer', () => {
    hoisted.instances.length = 0
    const { api, viewer } = mountHost()

    api.selectStructure('str_left_ventricle')
    api.focusStructure('str_aorta')
    api.flashStructure('str_aorta', false)
    api.setMode('quiz')
    api.prefetchOrgan('/models/brain.glb')

    expect(viewer.calls).toEqual([
      'selectStructure:str_left_ventricle',
      'focusStructure:str_aorta',
      'flashStructure:str_aorta:false',
      'setMode:quiz',
      'prefetchOrgan:/models/brain.glb',
    ])
  })

  it('disposes the viewer and detaches every listener on unmount', () => {
    // docs/architecture.md §5.4 rule 6: unmounting a page disposes the viewer.
    hoisted.instances.length = 0
    const { wrapper, api, viewer } = mountHost()

    wrapper.unmount()

    expect(viewer.disposeCount).toBe(1)
    expect(api.isReady.value).toBe(false)
    for (const handlers of viewer.handlers.values()) expect(handlers.size).toBe(0)
  })

  it('disposes once even when unmount and scope teardown both fire', () => {
    hoisted.instances.length = 0
    const { wrapper, viewer } = mountHost()

    wrapper.unmount()
    wrapper.unmount()

    expect(viewer.disposeCount).toBe(1)
  })

  it('is inert after unmount rather than throwing', () => {
    hoisted.instances.length = 0
    const { wrapper, api, viewer } = mountHost()

    wrapper.unmount()

    expect(() => api.selectStructure('str_aorta')).not.toThrow()
    expect(viewer.calls).not.toContain('selectStructure:str_aorta')
  })
})
