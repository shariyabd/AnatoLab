import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolRail from './ToolRail.vue'

/**
 * The rail's one job: never offer a control the viewer will not honour
 * (handover 15 Phase 3, docs/architecture.md §5.3).
 *
 * These are behaviour, not markup shape — what is asserted is which controls
 * exist, never how they are laid out (docs/engineering.md §9).
 */
function mountRail(props: Record<string, unknown> = {}) {
  return mount(ToolRail, {
    props: {
      disabled: false,
      layer: 'solid',
      isolated: false,
      hasSelection: false,
      degraded: {},
      ...props,
    },
  })
}

function captions(wrapper: ReturnType<typeof mountRail>): string[] {
  return wrapper
    .get('[role="toolbar"]')
    .findAll('button')
    .map((button) => button.text().trim())
}

describe('what the rail offers', () => {
  it('offers only controls that map to a real viewer command', () => {
    // Rotate and Pan are OrbitControls gestures with no programmatic command
    // behind them, so they are taught in the tip note rather than shipped as
    // buttons wired to nothing.
    expect(captions(mountRail())).toEqual([
      'Zoom in',
      'Zoom out',
      'Wireframe',
      'Cross-section',
      'Reset',
    ])
  })

  it('removes Focus until there is something to focus on, rather than grey it out', () => {
    expect(captions(mountRail())).not.toContain('Focus')
    expect(captions(mountRail({ hasSelection: true }))).toContain('Focus')
  })

  it('takes the whole rail away when there is no usable viewer under it', () => {
    const wrapper = mountRail({ disabled: true })

    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false)
  })
})

describe('capability:degraded', () => {
  it('keeps a tool whose caption already states the reduction, and shows the reason', () => {
    /*
     | Named for what it asserts. All three of the rail's capabilities caption
     | their own reduction, so the removal branch has nothing to fire on today;
     | writing a test that pretended otherwise would be testing the harness. It
     | fires the first time the viewer reports a capability whose caption does
     | not admit what it does — see the component's header for why that rule is
     | not "hide on any degraded signal".
     */
    const wrapper = mount(ToolRail, {
      props: {
        disabled: false,
        layer: 'solid',
        isolated: false,
        hasSelection: true,
        degraded: { isolate: 'Other structures are dimmed rather than hidden.' },
      },
    })

    // Focus captions its reduction, so it stays and the reason is shown.
    expect(captions(wrapper)).toContain('Focus')
    expect(wrapper.text()).toContain('dimmed rather than hidden')
  })

  it('shows the viewer’s own explanation rather than swallowing it', () => {
    const wrapper = mountRail({
      degraded: {
        layers: 'These models carry no layer information.',
        crossSection: 'The cut is whole-organ.',
      },
    })

    expect(wrapper.text()).toContain('These models carry no layer information.')
    expect(wrapper.text()).toContain('The cut is whole-organ.')
  })

  it('says nothing at all when the viewer has reported nothing', () => {
    expect(mountRail().text()).not.toContain('models')
  })
})

describe('the layer buttons are one piece of state', () => {
  it('marks the active layer pressed and offers the way back to solid', async () => {
    const wrapper = mountRail({ layer: 'wireframe' })

    const wireframe = wrapper
      .get('[role="toolbar"]')
      .findAll('button')
      .find((button) => button.text().includes('Wireframe'))

    expect(wireframe?.attributes('aria-pressed')).toBe('true')

    await wireframe?.trigger('click')

    // Back to solid, not on again: the viewer holds one layer, so the button
    // is a toggle against `solid` rather than a member of a radio group that
    // could be turned on twice.
    expect(wrapper.emitted('update:layer')?.[0]).toEqual(['solid'])
  })

  it('switching layers turns the other one off, because the viewer holds one', async () => {
    const wrapper = mountRail({ layer: 'section' })

    const wireframe = wrapper
      .get('[role="toolbar"]')
      .findAll('button')
      .find((button) => button.text().includes('Wireframe'))

    await wireframe?.trigger('click')

    expect(wrapper.emitted('update:layer')?.[0]).toEqual(['wireframe'])
  })
})
