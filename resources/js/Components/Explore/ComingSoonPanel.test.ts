import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ComingSoonPanel from './ComingSoonPanel.vue'
import type { UpcomingOrganCard } from '@/types/explore'

const STOMACH: UpcomingOrganCard = {
  id: '12',
  slug: 'stomach',
  name: 'Stomach',
  scientificName: 'Gaster',
  description: 'A muscular bag between the oesophagus and the duodenum.',
  accentColor: '#B86858',
  bodySystem: { slug: 'digestive', name: 'Digestive System' },
}

function mountPanel(organ: UpcomingOrganCard = STOMACH) {
  return mount(ComingSoonPanel, { props: { organ } })
}

describe('the coming-soon panel', () => {
  it('names the organ and says plainly that it is not here yet', () => {
    const text = mountPanel().text()

    expect(text).toContain('Stomach')
    expect(text).toContain('Digestive System')
    expect(text).toContain('Coming soon')
  })

  it('offers nothing to press', () => {
    // The whole point of the state. A control that does nothing is worse than
    // the 404 this replaced (handover 15 Phase 7: "visibly inert").
    const wrapper = mountPanel()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.findAll('a')).toHaveLength(0)
  })

  it('renders a row that carries no prose', () => {
    const wrapper = mountPanel({ ...STOMACH, scientificName: null, description: null })

    expect(wrapper.text()).toContain('Stomach')
    expect(wrapper.text()).toContain('Coming soon')
  })
})
