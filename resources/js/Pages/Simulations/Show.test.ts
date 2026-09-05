import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import Show from './Show.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { SimulationDto, SimulationStep } from '@/types/simulations'

/**
 * The page's contract with the rest of the platform.
 *
 * Three things here are why handover 12 exists: the server's visual directives
 * reach the viewer unchanged, the page computes no state of its own, and the
 * whole simulation is runnable with no 3D at all.
 */
vi.mock('@/anatomy', async () => {
  const { createFakeAnatomyModule } = await import('@/testing/fakeAnatomyViewer')
  return createFakeAnatomyModule()
})

vi.mock('@inertiajs/vue3', () => ({
  Head: defineComponent({ render: () => null }),
  Link: defineComponent({ render: () => null }),
  router: { visit: vi.fn() },
}))

const MITRAL = createStructure({ id: 'str_mitral', slug: 'mitral-valve', name: 'Mitral valve' })

const SIMULATION: SimulationDto = {
  id: '1',
  slug: 'mitral-valve-closure',
  title: 'What happens if the mitral valve does not close?',
  description: null,
  premise: 'A simplified educational model, not a medical tool.',
  organ: createOrgan({ structures: [MITRAL] }),
  actions: [
    { id: 'impair_mitral', label: 'The mitral valve does not close fully', description: null },
    { id: 'restore_mitral', label: 'The valve seals properly again', description: null },
  ],
  readouts: [
    { key: 'output', label: 'Cardiac output', min: 0, max: 1, precision: 2, unit: 'of normal' },
  ],
  notice: 'This is a simplified educational model of how the body works.',
}

const BASELINE: SimulationStep = {
  sequence: 0,
  actionId: null,
  actionLabel: null,
  stateKey: 'baseline',
  label: 'Beating normally',
  isTerminal: false,
  variables: { output: 1 },
  affectedStructureIds: [],
  outcomes: [],
  visualDirectives: {},
  explanation: 'Everything is working normally.',
  explanationSource: 'curated',
  notice: 'This is a simplified educational model. It is not a medical diagnosis.',
}

/** What the server sends after the valve is impaired. */
const IMPAIRED: SimulationStep = {
  ...BASELINE,
  sequence: 1,
  actionId: 'impair_mitral',
  actionLabel: 'The mitral valve does not close fully',
  stateKey: 'reduced_systemic_flow',
  label: 'Less blood reaches the body with each beat',
  variables: { output: 0.75 },
  affectedStructureIds: ['str_mitral'],
  outcomes: [
    {
      key: 'reduced_systemic_flow',
      label: 'Less blood reaches the body with each beat',
      condition: 'output < 0.8',
      terminal: false,
    },
  ],
  visualDirectives: {
    highlight: 'str_mitral',
    tint: '#d1584f',
    pulseRate: 1.35,
    focus: 'str_mitral',
    crossSection: { enabled: true, axis: 'z', offset: 0 },
  },
  explanation: 'Some blood travels backwards through the leaking valve.',
  explanationSource: 'curated',
}

function respondWith(step: SimulationStep): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: () => Promise.resolve({ data: step }),
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function mountPage(step: SimulationStep = BASELINE) {
  return mount(Show, { props: { simulation: SIMULATION, step, events: [] } })
}

beforeEach(() => {
  resetFakeViewers()
  document.head.innerHTML = '<meta name="csrf-token" content="test-token">'
})

afterEach(() => {
  vi.unstubAllGlobals()
})

it('hands the viewer the server directives, unchanged and unmapped', async () => {
  respondWith(IMPAIRED)
  const wrapper = mountPage()

  await wrapper.findAll('button')[0]!.trigger('click')
  await nextTick()

  const applied = lastFakeViewer().simulationStates

  // The last call is the impaired step. Its directive object must be the one
  // the server sent — not a copy with keys renamed, values coerced, or the
  // closed vocabulary re-derived on the client (docs/architecture.md §12).
  expect(applied.at(-1)?.visualDirectives).toEqual(IMPAIRED.visualDirectives)
  expect(applied.at(-1)?.state).toEqual(IMPAIRED.variables)

  wrapper.unmount()
})

it('applies a resumed run to the viewer on mount', () => {
  const wrapper = mountPage(IMPAIRED)

  // A student who left mid-run comes back to the model they left, not to an
  // untouched one.
  expect(lastFakeViewer().simulationStates.at(-1)?.visualDirectives).toEqual(
    IMPAIRED.visualDirectives,
  )

  wrapper.unmount()
})

it('posts the action id and derives nothing itself', async () => {
  const fetchMock = respondWith(IMPAIRED)
  const wrapper = mountPage()

  await wrapper.findAll('button')[0]!.trigger('click')
  await nextTick()

  const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
  expect(JSON.parse(String(init.body))).toEqual({ actionId: 'impair_mitral' })

  // The readout shows the server's number, to the server's precision.
  expect(wrapper.text()).toContain('0.75')

  wrapper.unmount()
})

it('renders the outcome, its condition, and where the words came from', async () => {
  respondWith(IMPAIRED)
  const wrapper = mountPage()

  await wrapper.findAll('button')[0]!.trigger('click')
  await nextTick()

  expect(wrapper.text()).toContain('Less blood reaches the body with each beat')
  expect(wrapper.text()).toContain('output < 0.8')
  expect(wrapper.text()).toContain('Some blood travels backwards')
  expect(wrapper.text()).toContain('Written for this simulation')

  wrapper.unmount()
})

it('shows the educational notice on every step, never once', async () => {
  respondWith(IMPAIRED)
  const wrapper = mountPage()

  expect(wrapper.text()).toContain('not a medical diagnosis')

  await wrapper.findAll('button')[0]!.trigger('click')
  await nextTick()

  expect(wrapper.text()).toContain('not a medical diagnosis')

  wrapper.unmount()
})

it('stays fully runnable with no 3D at all', async () => {
  const viewerFailed = mountPage()
  lastFakeViewer().isAvailable = false

  respondWith(IMPAIRED)
  await viewerFailed.findAll('button')[0]!.trigger('click')
  await nextTick()

  // Actions, readouts, outcomes and explanation are DOM. Losing WebGL loses
  // the picture and nothing else (docs/architecture.md §5.4 rule 5).
  expect(viewerFailed.text()).toContain('The mitral valve does not close fully')
  expect(viewerFailed.text()).toContain('Cardiac output')
  expect(viewerFailed.text()).toContain('0.75')
  expect(viewerFailed.text()).toContain('Some blood travels backwards')

  viewerFailed.unmount()
})

it('disposes the viewer when the page unmounts', () => {
  const wrapper = mountPage()
  const viewer = lastFakeViewer()

  expect(viewer.disposeCount).toBe(0)
  wrapper.unmount()
  expect(viewer.disposeCount).toBe(1)
})

it('records the run in order and offers a way back to the start', async () => {
  respondWith(IMPAIRED)
  const wrapper = mountPage()

  await wrapper.findAll('button')[0]!.trigger('click')
  await nextTick()

  expect(wrapper.text()).toContain('1.')
  expect(wrapper.text()).toContain('Start again')

  wrapper.unmount()
})
