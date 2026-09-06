import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import Explore from './Explore.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { fakeViewers, lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
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
  // The information panel's "View lessons" call to action is an Inertia link.
  Link: defineComponent({
    props: { href: { type: String, required: true } },
    render(this: { href: string; $slots: { default?: () => unknown } }) {
      return h('a', { href: this.href }, this.$slots.default?.() as never)
    },
  }),
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

    // `comingSoon` rides along with `organ` (handover 16): both answer "what is
    // on the stage", and asking for one without the other leaves a stale
    // coming-soon banner beside the organ the student just opened.
    expect(visit).toHaveBeenCalledWith(
      '/explore/lungs',
      expect.objectContaining({ only: ['organ', 'comingSoon'], preserveState: true }),
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

  it('takes the camera tools away rather than offering ones it cannot honour', async () => {
    // Changed by handover 15 Phase 3, and deliberately: the rail used to sit
    // below the canvas and grey itself out. It now floats *over* the canvas,
    // which with no WebGL is an explanation of why there is no model — so a
    // column of dead controls on top of that apology is chrome for something
    // that is not there. Hidden, not disabled, is the rule the rail follows
    // everywhere else too.
    const wrapper = mountPage()

    // One tick: the rail reads `canInteract` off the stage through a template
    // ref, which is null until the child has mounted.
    await nextTick()
    expect(wrapper.find('[role="toolbar"]').exists()).toBe(true)

    lastFakeViewer().emit('webgl:unavailable', { detail: 'No context.' })
    await nextTick()

    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)

    // The page is not what was hidden: every structure is still a button.
    expect(wrapper.get('ul[aria-label="Structures in this organ"]').findAll('button')).toHaveLength(
      HEART.structures.length,
    )

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

/**
 * Counts calls to a compiled SFC's render function.
 *
 * Handover 17 asks for this specifically — "spy the render function" — because
 * the cheap version of the assertion (watch the DOM) cannot tell "did not
 * re-render" from "re-rendered to the same output", and it is the re-rendering
 * that costs, not the diff.
 */
function countRendersOf(component: unknown): { count: number; restore: () => void } {
  const target = component as { render?: (...args: unknown[]) => unknown }
  const original = target.render

  if (original === undefined) {
    throw new Error('The component has no render function to spy on.')
  }

  const state = {
    count: 0,
    restore: () => {
      target.render = original
    },
  }

  target.render = function (this: unknown, ...args: unknown[]): unknown {
    state.count += 1
    return original.apply(this, args)
  }

  return state
}

describe('hover costs nothing in Vue', () => {
  /*
   * Handover 17: "Zero Vue re-renders on hover — the same rule as the callout.
   * If you bind hover state to a `ref`, a mouse sweep across the heart will
   * re-render the page a hundred times."
   *
   * `useAnatomyViewer` does keep a `hoveredStructure` shallowRef, so the
   * guarantee is not that nothing is written — it is that nothing *reads* it in
   * a template. A ref with no render-effect subscriber schedules no update. That
   * is easy to break by accident with a single `{{ hovered?.name }}`, and it
   * would not fail any other test in this suite, which is why this one exists.
   */
  it('re-renders the page zero times across a hover sweep', async () => {
    // Both components, because either could break it and they would fail
    // differently: ViewerStage is where `useAnatomyViewer` lives and so where a
    // hover binding would most naturally be added, and Explore is the page that
    // would pay for it if the structure were passed upward.
    const page = countRendersOf(Explore)
    const stage = countRendersOf(ViewerStage)
    const renders = {
      get count(): number {
        return page.count + stage.count
      },
      restore: (): void => {
        page.restore()
        stage.restore()
      },
    }

    try {
      const wrapper = mountPage()
      await nextTick()

      const baseline = renders.count
      const viewer = lastFakeViewer()

      // A sweep across the organ: the viewer throttles to ~60 ms, so even a
      // slow drag emits a few dozen of these.
      for (let sweep = 0; sweep < 40; sweep += 1) {
        viewer.emit('structure:hovered', {
          structure: HEART.structures[sweep % HEART.structures.length],
        })
      }
      viewer.emit('structure:hovered', { structure: null })
      await nextTick()

      expect(renders.count).toBe(baseline)

      // The counter is not asleep. Selecting *is* meant to re-render — the
      // information panel and the structure list both show the selection — so
      // this proves the assertion above could have failed.
      viewer.emit('structure:selected', { structure: HEART.structures[1] })
      await nextTick()

      expect(renders.count).toBeGreaterThan(baseline)

      wrapper.unmount()
    } finally {
      renders.restore()
    }
  })
})
