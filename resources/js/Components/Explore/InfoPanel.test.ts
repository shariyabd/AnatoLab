import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import InfoPanel from './InfoPanel.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import type { OrganEditorial } from '@/types/explore'

/**
 * The panel's two contracts: it offers no action the platform cannot honour,
 * and it is finished ahead of the data it is waiting for (handover 15 Phase 5).
 */
const Link = defineComponent({
  props: { href: { type: String, required: true } },
  render(this: { href: string; $slots: { default?: () => unknown } }) {
    return h('a', { href: this.href }, this.$slots.default?.() as never)
  },
})

const HEART = createOrgan({ structures: [createStructure()] })

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(InfoPanel, {
    props: {
      organ: HEART,
      structure: null,
      detail: null,
      detailFailed: false,
      selectableIds: [],
      ...props,
    },
    global: { stubs: { Link } },
  })
}

function buttonLabelled(wrapper: ReturnType<typeof mountPanel>, label: string) {
  return wrapper.findAll('button').find((candidate) => candidate.text().includes(label))
}

describe('the action row', () => {
  it('links to this organ’s lessons, which is a filter the server supports', () => {
    const link = mountPanel().get('a')

    expect(link.attributes('href')).toBe(`/lessons?organ=${HEART.slug}`)
  })

  it('offers no Quiz or Compare, because neither has anything behind it', () => {
    const text = mountPanel().text()

    expect(text).not.toContain('Quiz')
    expect(text).not.toContain('Compare')
  })

  it('offers the guided tour when there are structures to choreograph', async () => {
    const wrapper = mountPanel()

    await buttonLabelled(wrapper, 'Guided tour')?.trigger('click')

    expect(wrapper.emitted('play-tour')).toHaveLength(1)
  })

  it('takes the guided tour away when the organ has no structures', () => {
    // The same test the viewer applies before reporting `animation` degraded:
    // no step list means no choreography, and a play button that does nothing
    // is worse than no play button.
    const wrapper = mountPanel({ organ: createOrgan({ structures: [] }) })

    expect(buttonLabelled(wrapper, 'Guided tour')).toBeUndefined()
  })
})

describe('the editorial blocks', () => {
  const EDITORIAL: OrganEditorial = {
    tagline: 'The tireless pump',
    keyFacts: [
      { icon: 'weight', label: 'Weight', value: '250–350 g' },
      { icon: 'pulse', label: 'Resting rate', value: '60–100 bpm' },
    ],
    medicalImportance: 'Ischaemic heart disease is the leading cause of death worldwide.',
    didYouKnow: 'It beats around 100,000 times a day.',
  }

  it('renders nothing for them until the columns exist', () => {
    /*
     | `organs` carries no tagline, key_facts, medical_importance or
     | did_you_know, and handover 15 may not migrate a table F03 owns. Every
     | block is guarded rather than stubbed with invented copy, so the panel
     | shows less rather than showing something untrue.
     */
    const text = mountPanel().text()

    expect(text).not.toContain('Key facts')
    expect(text).not.toContain('Medical importance')
    expect(text).not.toContain('Did you know')
  })

  it('renders all of them the moment the data arrives', () => {
    const text = mountPanel({ editorial: EDITORIAL }).text()

    expect(text).toContain('The tireless pump')
    expect(text).toContain('Key facts')
    expect(text).toContain('Resting rate')
    expect(text).toContain('60–100 bpm')
    expect(text).toContain('Medical importance')
    expect(text).toContain('leading cause of death')
    expect(text).toContain('Did you know')
    expect(text).toContain('100,000 times a day')
  })

  it('falls back to the scientific name while there is no tagline', () => {
    expect(mountPanel().text()).toContain(HEART.scientificName ?? '')
  })
})

describe('the selected structure', () => {
  it('explains a structure without waiting for the detail request', () => {
    const structure = createStructure({
      name: 'Left ventricle',
      description: 'The thickest chamber.',
      function: 'Pumps blood into the aorta.',
    })

    const text = mountPanel({ structure }).text()

    expect(text).toContain('Left ventricle')
    expect(text).toContain('The thickest chamber.')
    expect(text).toContain('Pumps blood into the aorta.')
  })

  it('says a failed detail request cost the links and nothing else', () => {
    const wrapper = mountPanel({ structure: createStructure(), detailFailed: true })

    expect(wrapper.text()).toContain('Related structures could not be loaded.')
  })
})
