import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import ViewerStage from './ViewerStage.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { fakeViewers, lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { OrganDto, StructureDto } from '@/anatomy/types'

/**
 * The mounting pattern's own tests.
 *
 * Four later handovers reuse this component, so the guarantees asserted here
 * are the ones they inherit: the viewer is built once, an organ switch never
 * remounts it, unmounting disposes it, and no-3D is a rendered explanation
 * rather than a broken page.
 *
 * The viewer library itself is covered by `resources/js/anatomy/*.test.ts`.
 * It is replaced here by a double, so a failure in this file is a failure in
 * the bridge and not in Three.js.
 */
vi.mock('@/anatomy', async () => {
  const { createFakeAnatomyModule } = await import('@/testing/fakeAnatomyViewer')
  return createFakeAnatomyModule()
})

const HEART = createOrgan()
const LUNGS: OrganDto = createOrgan({
  id: 'org_lungs',
  slug: 'lungs',
  name: 'Lungs',
  modelUrl: '/models/lungs.glb',
})

function mountStage(props: Record<string, unknown> = {}) {
  return mount(ViewerStage, {
    props: { organ: HEART, ...props },
    attachTo: document.body,
  })
}

beforeEach(resetFakeViewers)

describe('construction', () => {
  it('hands the viewer an element Vue does not render into', () => {
    const wrapper = mountStage()

    // Rule 2 of the mounting pattern. If the container ever gains a Vue-owned
    // child, the patcher and the viewer both claim the same subtree.
    const container = lastFakeViewer().container
    expect(container).toBeInstanceOf(HTMLElement)
    expect(container.childElementCount).toBe(0)

    wrapper.unmount()
  })

  it('passes the host page reduced-motion preference through', () => {
    const wrapper = mountStage({ reducedMotion: true })

    expect(lastFakeViewer().options.reducedMotion).toBe(true)

    wrapper.unmount()
  })

  it('loads the organ it was mounted with', () => {
    const wrapper = mountStage()

    expect(lastFakeViewer().calls).toContain('loadOrgan:heart')

    wrapper.unmount()
  })
})

describe('switching organs', () => {
  it('loads the new organ without constructing a second viewer', async () => {
    const wrapper = mountStage()
    const viewer = lastFakeViewer()

    await wrapper.setProps({ organ: LUNGS })

    expect(fakeViewers).toHaveLength(1)
    expect(viewer.disposeCount).toBe(0)
    expect(viewer.calls).toContain('loadOrgan:lungs')

    wrapper.unmount()
  })

  it('clears the previous organ selection', async () => {
    const wrapper = mountStage()
    const viewer = lastFakeViewer()

    viewer.emit('structure:selected', { structure: createStructure() })
    await nextTick()
    expect(wrapper.text()).toContain('Left ventricle')

    await wrapper.setProps({ organ: LUNGS })
    await nextTick()

    // loadOrgan resets the viewer's internal selection without emitting, so a
    // stale callout is exactly what this guards.
    expect(wrapper.text()).not.toContain('Left ventricle')
  })
})

describe('disposal', () => {
  it('disposes the viewer when the component unmounts', () => {
    const wrapper = mountStage()
    const viewer = lastFakeViewer()

    expect(viewer.disposeCount).toBe(0)

    wrapper.unmount()

    expect(viewer.disposeCount).toBe(1)
  })

  it('stops the callout animation frame on unmount', async () => {
    const cancel = vi.spyOn(globalThis, 'cancelAnimationFrame')
    const wrapper = mountStage()

    lastFakeViewer().emit('structure:selected', { structure: createStructure() })
    await nextTick()
    await new Promise((resolve) => requestAnimationFrame(resolve))

    wrapper.unmount()

    expect(cancel).toHaveBeenCalled()
    cancel.mockRestore()
  })
})

describe('the callout', () => {
  it('is positioned by writing a transform, not by a reactive binding', async () => {
    const wrapper = mountStage()
    const viewer = lastFakeViewer()

    viewer.emit('structure:selected', { structure: createStructure() })
    await nextTick()
    await new Promise((resolve) => requestAnimationFrame(resolve))
    await new Promise((resolve) => requestAnimationFrame(resolve))

    const anchor = wrapper.get('.will-change-transform').element as HTMLElement
    expect(anchor.style.transform).toBe('translate3d(120px, 64px, 0)')

    wrapper.unmount()
  })

  it('dims rather than removes a marker the model has turned away from', async () => {
    const wrapper = mountStage()
    const viewer = lastFakeViewer()
    viewer.screenPosition = { x: 10, y: 20, visible: false }

    viewer.emit('structure:selected', { structure: createStructure() })
    await nextTick()
    await new Promise((resolve) => requestAnimationFrame(resolve))
    await new Promise((resolve) => requestAnimationFrame(resolve))

    const anchor = wrapper.get('.will-change-transform').element as HTMLElement
    expect(anchor.style.opacity).toBe('0.35')

    wrapper.unmount()
  })

  it('is not rendered when the host page draws its own', async () => {
    const wrapper = mountStage({ showCallout: false })

    lastFakeViewer().emit('structure:selected', { structure: createStructure() })
    await nextTick()

    expect(wrapper.find('.will-change-transform').exists()).toBe(false)

    wrapper.unmount()
  })
})

describe('failure paths', () => {
  it('explains a missing WebGL context instead of rendering nothing', async () => {
    const wrapper = mountStage()

    lastFakeViewer().emit('webgl:unavailable', { detail: 'No context.' })
    await nextTick()

    expect(wrapper.text()).toContain('cannot show 3D graphics')

    wrapper.unmount()
  })

  it('surfaces the viewer own account of a failed load', async () => {
    const wrapper = mountStage()

    lastFakeViewer().emit('load:failed', {
      reason: 'model-not-found',
      detail: 'The model for this organ is not available yet.',
    })
    await nextTick()

    expect(wrapper.text()).toContain('The model for this organ is not available yet.')

    wrapper.unmount()
  })
})

describe('commands', () => {
  it('refuses auto-rotate while reduced motion is set', () => {
    const wrapper = mountStage({ reducedMotion: true })

    wrapper.vm.setAutoRotate(true)

    expect(wrapper.vm.autoRotate).toBe(false)
    expect(lastFakeViewer().calls).toContain('setAutoRotate:false')

    wrapper.unmount()
  })

  it('toggles isolation off when it is already isolating', () => {
    const wrapper = mountStage()
    const structure: StructureDto = createStructure()

    wrapper.vm.toggleIsolate(structure.id)
    expect(wrapper.vm.isolatedId).toBe(structure.id)

    wrapper.vm.toggleIsolate(structure.id)
    expect(wrapper.vm.isolatedId).toBeNull()

    expect(lastFakeViewer().calls).toContain(`isolateStructure:${structure.id}`)
    expect(lastFakeViewer().calls).toContain('isolateStructure:null')

    wrapper.unmount()
  })

  it('records every capability the viewer reports as degraded', async () => {
    const wrapper = mountStage()

    lastFakeViewer().emit('capability:degraded', {
      capability: 'isolate',
      reason: 'Dimmed, not hidden.',
    })
    lastFakeViewer().emit('capability:degraded', {
      capability: 'layers',
      reason: 'Wireframe only.',
    })
    await nextTick()

    expect(wrapper.vm.degraded).toEqual({
      isolate: 'Dimmed, not hidden.',
      layers: 'Wireframe only.',
    })

    wrapper.unmount()
  })
})
