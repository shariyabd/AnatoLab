import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useAnatomyViewer } from './useAnatomyViewer'
import { createOrgan } from '@/anatomy/testing/fixtures'
import { restoreCanvasContext, stubWebGLUnavailable } from '@/anatomy/testing/dom'

/**
 * The composable against the real `AnatomyViewer`, on the path that matters most
 * for the product: a device with no usable WebGL.
 *
 * `useAnatomyViewer.test.ts` replaces the viewer with a double to test the wiring
 * in isolation. This file does not, so it also proves the two really fit
 * together — the sibling suite would pass just as happily against a signature
 * that has drifted.
 */
describe('useAnatomyViewer without WebGL', () => {
  beforeEach(() => {
    stubWebGLUnavailable()
  })

  afterEach(() => {
    restoreCanvasContext()
    document.body.replaceChildren()
  })

  function mountHost() {
    let api: ReturnType<typeof useAnatomyViewer> | null = null

    const wrapper = mount(
      defineComponent({
        setup() {
          const container = ref<HTMLElement | null>(null)
          api = useAnatomyViewer({ container, organ: ref(createOrgan()) })
          return () => h('div', { ref: container })
        },
      }),
      { attachTo: document.body },
    )

    return { wrapper, api: api as unknown as ReturnType<typeof useAnatomyViewer> }
  }

  it('keeps the structure list usable and reports the failure in words', async () => {
    // PRD §31/§40: the structure list, description and lesson content stay
    // usable without 3D.
    const { wrapper, api } = mountHost()

    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(api.isAvailable.value).toBe(false)
    expect(api.structures.value).toHaveLength(3)
    expect(api.failure.value?.reason).toBe('webgl-unavailable')
    expect(wrapper.element.querySelector('canvas')).toBeNull()
    expect(wrapper.element.querySelector('.anatomy-viewer__structures')).not.toBeNull()
  })

  it('tears the viewer down on unmount', async () => {
    const { wrapper } = mountHost()
    await new Promise((resolve) => setTimeout(resolve, 0))

    wrapper.unmount()

    expect(document.querySelector('.anatomy-viewer__structures')).toBeNull()
  })
})
