import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import Explore from './Explore.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { fakeViewers, lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { ExploreOrganCard } from '@/types/explore'

/**
 * The page's contract with the rest of the platform.
 *
 * Three things here are the reason handover 05 exists at all: the viewer is
 * disposed when the page goes away, switching organs is a partial reload
 * rather than a remount, and the page is fully usable with no 3D.
 */
vi.mock('@/anatomy', async () => {
  const { createFakeAnatomyModule } = await import('@/testing/fakeAnatomyViewer')
  return createFakeAnatomyModule()
})

const visit = vi.hoisted(() => vi.fn())

vi.mock('@inertiajs/vue3', () => ({
  Head: defineComponent({ render: () => null }),
  router: { visit },
}))

const HEART = createOrgan({
  structures: [
    createStructure(),
    createStructure({
      id: 'str_aorta',
      slug: 'aorta',
      name: 'Aorta',
      taTerm: 'Aorta',
      description: 'The largest artery in the body.',
      function: 'Carries oxygenated blood away from the left ventricle.',
    }),
  ],
})

const CARDS: ExploreOrganCard[] = [
  {
    id: 'org_heart',
    slug: 'heart',
    name: 'Heart',
    scientificName: 'Cor',
    description: null,
    accentColor: '#d1584f',
    thumbnailUrl: null,
    modelUrl: '/models/heart.glb',
    bodySystem: { slug: 'cardiovascular', name: 'Cardiovascular' },
    structureCount: 2,
  },
  {
    id: 'org_lungs',
    slug: 'lungs',
    name: 'Lungs',
    scientificName: 'Pulmones',
    description: null,
    accentColor: '#5f86c9',
    thumbnailUrl: null,
    modelUrl: '/models/lungs.glb',
    bodySystem: { slug: 'respiratory', name: 'Respiratory' },
    structureCount: 4,
  },
]

type Page = ReturnType<typeof mountPage>

/** The organ cards and the structure rows are both plain buttons. */
function buttonLabelled(wrapper: Page, label: string) {
  const button = wrapper.findAll('button').find((candidate) => candidate.text().includes(label))
  if (button === undefined) throw new Error(`No button containing "${label}".`)
  return button
}

function mountPage(props: Record<string, unknown> = {}) {
  return mount(Explore, {
    props: { organs: CARDS, organ: HEART, ...props },
    attachTo: document.body,
  })
}

beforeEach(() => {
  resetFakeViewers()
  visit.mockClear()
  // useStructureDetail is the page's only network call. A rejection is the
  // honest default here: the panel must render without it.
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
})

describe('viewer lifecycle', () => {
  it('disposes the viewer when the page unmounts', () => {
    const wrapper = mountPage()
    const viewer = lastFakeViewer()

    expect(viewer.disposeCount).toBe(0)

    wrapper.unmount()

    expect(viewer.disposeCount).toBe(1)
  })

  it('switches organs with a partial reload that keeps the viewer alive', async () => {
    const wrapper = mountPage()

    await buttonLabelled(wrapper, 'Lungs').trigger('click')

    expect(visit).toHaveBeenCalledWith(
      '/explore/lungs',
      expect.objectContaining({ only: ['organ'], preserveState: true }),
    )
    // A full navigation, or a `v-if` on the stage, would have torn this down.
    expect(fakeViewers).toHaveLength(1)
    expect(lastFakeViewer().disposeCount).toBe(0)

    wrapper.unmount()
  })

  it('does not re-request the organ already on screen', async () => {
    const wrapper = mountPage()

    await buttonLabelled(wrapper, 'Heart').trigger('click')

    expect(visit).not.toHaveBeenCalled()

    wrapper.unmount()
  })

  it('warms the model of an organ the pointer is only hovering', async () => {
    const wrapper = mountPage()

    await buttonLabelled(wrapper, 'Lungs').trigger('pointerenter')

    expect(lastFakeViewer().calls).toContain('prefetchOrgan:/models/lungs.glb')

    wrapper.unmount()
  })
})

describe('keyboard and no-pointer use', () => {
  it('offers every structure as a real button', () => {
    const wrapper = mountPage()

    const index = wrapper.get('ul[aria-label="Structures in this organ"]')

    expect(index.findAll('button')).toHaveLength(HEART.structures.length)

    wrapper.unmount()
  })

  it('selects and flies to a structure chosen from the list', async () => {
    const wrapper = mountPage()

    await buttonLabelled(wrapper, 'Aorta').trigger('click')

    expect(lastFakeViewer().calls).toContain('focusStructure:str_aorta')

    wrapper.unmount()
  })
})

describe('the text fallback', () => {
  it('keeps the structure list and organ metadata usable with no WebGL', async () => {
    const wrapper = mountPage()

    lastFakeViewer().emit('webgl:unavailable', { detail: 'No context.' })
    await nextTick()

    expect(wrapper.text()).toContain('cannot show 3D graphics')
    expect(wrapper.get('ul[aria-label="Structures in this organ"]').findAll('button')).toHaveLength(
      HEART.structures.length,
    )
    expect(wrapper.text()).toContain('Heart')

    wrapper.unmount()
  })

  it('disables the camera tools it cannot honour rather than hiding the page', async () => {
    const wrapper = mountPage()

    lastFakeViewer().emit('webgl:unavailable', { detail: 'No context.' })
    await nextTick()

    const rail = wrapper.get('[role="toolbar"]')

    for (const button of rail.findAll('button')) {
      expect(button.attributes('disabled')).toBeDefined()
    }

    wrapper.unmount()
  })

  it('still selects and explains a structure when the model never arrived', async () => {
    const wrapper = mountPage()
    const viewer = lastFakeViewer()

    // No model means no hotspots, so the viewer cannot hold a selection. The
    // page has to, or the structure list becomes decorative exactly when it is
    // the only thing left (docs/architecture.md §5.4 rule 5).
    viewer.hasHotspots = false
    viewer.emit('load:failed', { reason: 'model-not-found', detail: 'No model yet.' })
    await nextTick()

    await buttonLabelled(wrapper, 'Aorta').trigger('click')

    expect(wrapper.text()).toContain('The largest artery in the body.')
    expect(wrapper.text()).toContain('Carries oxygenated blood away from the left ventricle.')

    wrapper.unmount()
  })

  it('renders the page when no organ is published at all', () => {
    const wrapper = mountPage({ organs: [], organ: null })

    expect(wrapper.text()).toContain('No organs have been published yet.')

    wrapper.unmount()
  })
})

describe('the tutor seat', () => {
  /*
   * Handover 05 left this slot, handover 08 built the panel for it, and
   * handover 14 joined them (D3 in docs/handovers/parallel-execution-plan.md).
   * The assertion moved with the wiring: it used to prove the seat was empty
   * and reserved, and now proves the panel is actually in it, because PRD §2.3
   * step 6 is asking about the structure you just selected.
   */
  it('mounts the tutor in the slot', () => {
    const wrapper = mountPage()

    const slot = wrapper.get('#explore-tutor-slot')

    expect(slot.attributes('data-slot')).toBe('ai-tutor')
    expect(slot.find('section[aria-label="Anatomy tutor"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('hands the tutor the selected structure as its subject', async () => {
    const wrapper = mountPage()

    const panel = wrapper.get('#explore-tutor-slot')

    // With an organ open but nothing selected the subject is the organ, which
    // is what the tutor will actually be asked about.
    expect(panel.text()).toContain('Asking about Heart')

    await buttonLabelled(wrapper, 'Aorta').trigger('click')

    // Selecting narrows it: PRD §8.2 passes the selected structure as context,
    // and the panel has to be showing the one the page thinks is selected.
    expect(panel.text()).toContain('Asking about Aorta')

    wrapper.unmount()
  })
})
