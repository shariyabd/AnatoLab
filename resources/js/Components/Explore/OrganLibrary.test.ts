import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import OrganLibrary from './OrganLibrary.vue'
import type { ExploreOrganCard, UpcomingOrganCard } from '@/types/explore'

/**
 * The panel at full-body scale — handover 16, "library panel renders 60+
 * organs without layout or scroll regression".
 *
 * F15 Phase 7 left the panel two promises to keep once the taxonomy grows:
 * group by body system above a threshold, and bound its own height so a long
 * list scrolls inside the panel rather than pushing the viewer off the page.
 * Both are cheap to break in a restyle and neither is visible in a nine-organ
 * fixture, which is why they are asserted at sixty rather than at nine.
 */
const SYSTEMS = [
  'cardiovascular',
  'respiratory',
  'nervous',
  'digestive',
  'urinary',
  'sensory',
  'integumentary',
  'musculoskeletal',
  'endocrine',
  'lymphatic',
  'reproductive',
] as const

function createCard(index: number): ExploreOrganCard {
  const system = SYSTEMS[index % SYSTEMS.length] as string

  return {
    id: String(index),
    slug: `organ-${index}`,
    name: `Organ ${index}`,
    scientificName: null,
    description: null,
    accentColor: '#B86858',
    thumbnailUrl: null,
    modelUrl: `/storage/models/organ-${index}.glb`,
    bodySystem: { slug: system, name: system },
    structureCount: 8,
  }
}

function createUpcoming(index: number, system?: string): UpcomingOrganCard {
  const slug = system ?? (SYSTEMS[index % SYSTEMS.length] as string)

  return {
    id: `u${index}`,
    slug: `upcoming-${index}`,
    name: `Upcoming ${index}`,
    scientificName: null,
    description: null,
    accentColor: '#9A8E7A',
    bodySystem: { slug, name: slug },
  }
}

function mountLibrary(count: number, upcomingCount = 0) {
  return mount(OrganLibrary, {
    props: {
      organs: Array.from({ length: count }, (_, index) => createCard(index)),
      upcoming: Array.from({ length: upcomingCount }, (_, index) => createUpcoming(index)),
      currentSlug: null,
    },
  })
}

describe('at full-body scale', () => {
  it('renders a row per organ at sixty-six', () => {
    const wrapper = mountLibrary(66)

    expect(wrapper.findAll('li')).toHaveLength(66)
    expect(wrapper.text()).toContain('66 organs across 11 systems')
  })

  it('groups by body system once the flat list stops being scannable', () => {
    const headings = mountLibrary(66).findAll('h3')

    expect(headings).toHaveLength(SYSTEMS.length)
    expect(headings.map((heading) => heading.text())).toContain('cardiovascular')
  })

  it('keeps the group headings sticky, which is what makes the scroll navigable', () => {
    expect(mountLibrary(66).get('h3').classes()).toContain('sticky')
  })

  it('scrolls inside its own bounded panel rather than growing the page', () => {
    // The regression this guards is a restyle dropping the max-height: the
    // panel then pushes the viewer off screen at sixty organs, which is the
    // "scroll pit" F15 Phase 7 was told to confirm does not happen.
    const scroller = mountLibrary(66).get('[aria-labelledby="organ-library-heading"] > div')

    expect(scroller.classes()).toContain('overflow-y-auto')
    expect(scroller.classes().join(' ')).toContain('max-h-')
  })

  it('still renders a flat list at the nine organs published today', () => {
    const wrapper = mountLibrary(9)

    expect(wrapper.findAll('h3')).toHaveLength(0)
    expect(wrapper.findAll('li')).toHaveLength(9)
  })
})

describe('prefetch', () => {
  it('warms a model on hover and on keyboard focus, at any list size', async () => {
    const wrapper = mountLibrary(66)
    const row = wrapper.get('button')

    await row.trigger('pointerenter')
    await row.trigger('focusin')

    expect(wrapper.emitted('prefetch')).toHaveLength(2)
  })
})

/**
 * The coverage roadmap — handover 16, handover 15 Phase 7: "visibly inert, not
 * clickable, not a 404".
 *
 * The failure this guards against is a well-meaning restyle turning the row
 * into a button because every other row is one. A taxonomy row has no model to
 * open, and a control that does nothing is worse than no control.
 */
describe('the coming-soon rows', () => {
  it('renders a row per taxonomy entry alongside the published ones', () => {
    const wrapper = mountLibrary(9, 35)

    expect(wrapper.findAll('li')).toHaveLength(44)
    expect(wrapper.text()).toContain('35 coming soon')
  })

  it('gives them no button, so there is nothing to click', () => {
    // Nine published organs, nine buttons. Not eighteen.
    expect(mountLibrary(9, 35).findAll('button')).toHaveLength(9)
  })

  it('says what they are, in words, not only in opacity', () => {
    expect(mountLibrary(9, 35).text()).toContain('Coming soon')
  })

  it('keeps a system with nothing published from disappearing', () => {
    // The case the roadmap exists for: a heading with only taxonomy under it.
    const wrapper = mount(OrganLibrary, {
      props: {
        organs: Array.from({ length: 13 }, (_, index) => createCard(index)),
        upcoming: [createUpcoming(0, 'reproductive')],
        currentSlug: null,
      },
    })

    expect(wrapper.findAll('h3').map((heading) => heading.text())).toContain('reproductive')
  })

  it('groups on the combined count, so nine published organs still get headings', () => {
    // Nine is under the threshold on its own and well over it once the taxonomy
    // is seeded. Counting only the published list would leave forty-four rows
    // in one flat run.
    expect(mountLibrary(9).findAll('h3')).toHaveLength(0)
    expect(mountLibrary(9, 35).findAll('h3').length).toBeGreaterThan(0)
  })

  it('cannot warm a model it has no URL for', () => {
    const wrapper = mountLibrary(0, 5)

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.emitted('prefetch')).toBeUndefined()
  })
})
