import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useSimulation } from './useSimulation'
import type { SimulationDto, SimulationStep } from '@/types/simulations'
import { createOrgan } from '@/anatomy/testing/fixtures'

/**
 * The run's HTTP boundary.
 *
 * The property under test throughout: this composable posts an action id and
 * renders whatever comes back. It never derives a state — it has never been
 * sent the effects table that would let it — so there is no arithmetic here to
 * disagree with the server's.
 */

const SIMULATION: SimulationDto = {
  id: '1',
  slug: 'mitral-valve-closure',
  title: 'What happens if the mitral valve does not close?',
  description: null,
  premise: 'A simplified educational model.',
  organ: createOrgan(),
  actions: [
    { id: 'impair_mitral', label: 'The valve leaks', description: null },
    { id: 'restore_mitral', label: 'The valve seals', description: null },
  ],
  readouts: [{ key: 'output', label: 'Cardiac output', min: 0, max: 1, precision: 2, unit: null }],
  notice: 'Educational only.',
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
  notice: 'Educational only.',
}

function step(overrides: Partial<SimulationStep>): SimulationStep {
  return { ...BASELINE, ...overrides }
}

function respondWith(body: SimulationStep, status = 200): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve({ data: body }),
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

let scope: ReturnType<typeof effectScope>

function run(initial: SimulationStep = BASELINE) {
  scope = effectScope()
  const value = scope.run(() => useSimulation(SIMULATION, initial))
  if (value === undefined) throw new Error('The scope did not run.')
  return value
}

beforeEach(() => {
  document.head.innerHTML = '<meta name="csrf-token" content="test-token">'
})

afterEach(() => {
  scope?.stop()
  vi.unstubAllGlobals()
})

describe('useSimulation', () => {
  it('posts only the action id, and nothing about what it does', async () => {
    const fetchMock = respondWith(step({ sequence: 1, actionId: 'impair_mitral' }))
    const simulation = run()

    await simulation.apply('impair_mitral')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/simulations/mitral-valve-closure/event')
    // One key. No state, no effects, no directives — the client has no
    // standing to assert any of them.
    expect(JSON.parse(String(init.body))).toEqual({ actionId: 'impair_mitral' })
  })

  it('renders the server state verbatim rather than computing one', async () => {
    respondWith(
      step({
        sequence: 1,
        actionId: 'impair_mitral',
        stateKey: 'reduced_systemic_flow',
        // A value no client-side arithmetic on the baseline would produce.
        variables: { output: 0.4242 },
        isTerminal: true,
      }),
    )
    const simulation = run()

    await simulation.apply('impair_mitral')

    expect(simulation.step.value.variables).toEqual({ output: 0.4242 })
    expect(simulation.step.value.stateKey).toBe('reduced_systemic_flow')
    expect(simulation.isTerminal.value).toBe(true)
  })

  it('appends one log entry per applied action', async () => {
    const simulation = run()

    respondWith(step({ sequence: 1, actionId: 'impair_mitral', actionLabel: 'The valve leaks' }))
    await simulation.apply('impair_mitral')

    respondWith(step({ sequence: 2, actionId: 'restore_mitral', actionLabel: 'The valve seals' }))
    await simulation.apply('restore_mitral')

    expect(simulation.events.value.map((entry) => entry.action_id)).toEqual([
      'impair_mitral',
      'restore_mitral',
    ])
    expect(simulation.events.value.map((entry) => entry.sequence)).toEqual([1, 2])
  })

  it('empties the log on a reset', async () => {
    const simulation = run()

    respondWith(step({ sequence: 1, actionId: 'impair_mitral' }))
    await simulation.apply('impair_mitral')

    respondWith(BASELINE)
    await simulation.reset()

    expect(simulation.events.value).toEqual([])
    expect(simulation.step.value.sequence).toBe(0)
    const [url] = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls[0] as [string]
    expect(url).toBe('/api/v1/simulations/mitral-valve-closure/reset')
  })

  it('leaves the simulation where it was when a request fails', async () => {
    const simulation = run()

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 429,
        json: () => Promise.resolve({}),
      }),
    )

    await simulation.apply('impair_mitral')

    expect(simulation.step.value).toEqual(BASELINE)
    expect(simulation.events.value).toEqual([])
    expect(simulation.error.value).toContain('give it a moment')
  })

  it('reports a transport failure without moving the simulation', async () => {
    const simulation = run()
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    await simulation.apply('impair_mitral')

    expect(simulation.step.value).toEqual(BASELINE)
    expect(simulation.error.value).toContain('has not moved')
  })

  it('ignores a second action while one is in flight', async () => {
    // Held on an object rather than in a `let`: TypeScript's control-flow
    // analysis does not see the assignment inside the executor and narrows a
    // local to `null` for the rest of the test.
    const gate = { release: (): void => undefined }
    const inFlight = new Promise<void>((resolve) => {
      gate.release = resolve
    })

    const fetchMock = vi.fn().mockImplementation(async () => {
      await inFlight
      return {
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: step({ sequence: 1, actionId: 'impair_mitral' }) }),
      }
    })
    vi.stubGlobal('fetch', fetchMock)

    const simulation = run()

    const first = simulation.apply('impair_mitral')
    // A double click must not queue a second step: the run would then depend
    // on request ordering, which is the one thing a deterministic replay
    // cannot tolerate.
    await simulation.apply('restore_mitral')

    expect(fetchMock).toHaveBeenCalledTimes(1)

    gate.release()
    await first
  })

  it('resumes from a step the page was rendered with', () => {
    const resumed = step({ sequence: 3, actionId: 'impair_mitral', variables: { output: 0.6 } })
    const simulation = run(resumed)

    expect(simulation.step.value).toEqual(resumed)
  })
})
