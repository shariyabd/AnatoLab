import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { FLUSH_INTERVAL_MS, MAX_BATCH, MAX_QUEUE, useLearningEvents } from './useLearningEvents'

/**
 * The client half of PRD §29's analytics.
 *
 * Three behaviours are worth a test and the rest is plumbing: the batch is
 * bounded, a failed flush does not lose events, and the unload path goes out
 * through `sendBeacon` — a `fetch` there is cancelled with the document, which
 * would silently lose every session that ends by closing the tab.
 */

/** Runs a composable inside a scope, the way a component would. */
function withScope<T>(run: () => T): { result: T; stop: () => void } {
  const scope = effectScope()
  const result = scope.run(run) as T

  return { result, stop: () => scope.stop() }
}

let fetchMock: ReturnType<typeof vi.fn>
let beaconMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  vi.useFakeTimers()

  fetchMock = vi.fn(() => Promise.resolve({ ok: true, status: 202 } as Response))
  vi.stubGlobal('fetch', fetchMock)

  beaconMock = vi.fn(() => true)
  Object.defineProperty(navigator, 'sendBeacon', { value: beaconMock, configurable: true })

  document.head.innerHTML = '<meta name="csrf-token" content="test-token">'
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  document.head.innerHTML = ''
})

describe('useLearningEvents', () => {
  it('batches events and flushes them on the interval', async () => {
    const { result, stop } = withScope(() => useLearningEvents())

    result.track('organ_viewed', { contextType: 'organ', contextId: '7' })
    result.track('layer_changed', { payload: { layer: 'wireframe' } })

    expect(fetchMock).not.toHaveBeenCalled()
    expect(result.pending()).toBe(2)

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS)

    expect(fetchMock).toHaveBeenCalledTimes(1)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body = JSON.parse(String(init.body)) as {
      events: { type: string; contextId?: number; payload?: Record<string, string> }[]
    }

    expect(url).toBe('/api/v1/events')
    expect(body.events).toHaveLength(2)
    // Ids cross the wire as strings and the endpoint wants an integer.
    expect(body.events[0]).toMatchObject({ type: 'organ_viewed', contextId: 7 })
    expect(body.events[1]).toMatchObject({ payload: { layer: 'wireframe' } })

    result.stop()
    stop()
  })

  it('sends nothing when the queue is empty', async () => {
    const { result, stop } = withScope(() => useLearningEvents())

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS * 3)

    expect(fetchMock).not.toHaveBeenCalled()

    result.stop()
    stop()
  })

  it('never sends more than the server accepts in one batch', async () => {
    const { result, stop } = withScope(() => useLearningEvents())

    for (let index = 0; index < MAX_BATCH + 10; index++) {
      result.track('structure_selected', { contextType: 'structure', contextId: index + 1 })
    }

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS)

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body = JSON.parse(String(init.body)) as { events: unknown[] }

    expect(body.events).toHaveLength(MAX_BATCH)
    expect(result.pending()).toBe(10)

    result.stop()
    stop()
  })

  it('puts a failed batch back on the queue', async () => {
    fetchMock.mockImplementation(() => Promise.resolve({ ok: false, status: 500 } as Response))

    const { result, stop } = withScope(() => useLearningEvents())

    result.track('organ_viewed', { contextType: 'organ', contextId: 1 })

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS)

    // Requeued, not dropped: a 500 costs latency, not a hole in the metrics.
    expect(result.pending()).toBe(1)

    result.stop()
    stop()
  })

  it('drops the oldest events rather than growing without bound', () => {
    const { result, stop } = withScope(() => useLearningEvents())

    for (let index = 0; index < MAX_QUEUE + 25; index++) {
      result.track('structure_selected', { contextType: 'structure', contextId: index + 1 })
    }

    expect(result.pending()).toBe(MAX_QUEUE)

    result.stop()
    stop()
  })

  it('flushes through sendBeacon on page unload, with the CSRF token in the body', () => {
    const { result, stop } = withScope(() => useLearningEvents())

    result.track('organ_viewed', { contextType: 'organ', contextId: 3 })

    window.dispatchEvent(new Event('pagehide'))

    // fetch would be cancelled with the document; sendBeacon is not.
    expect(fetchMock).not.toHaveBeenCalled()
    expect(beaconMock).toHaveBeenCalledTimes(1)
    expect(result.pending()).toBe(0)

    result.stop()
    stop()
  })

  it('flushes when the tab is hidden, which a phone may never follow with pagehide', () => {
    const { result, stop } = withScope(() => useLearningEvents())

    result.track('structure_isolated', { contextType: 'structure', contextId: 4 })

    Object.defineProperty(document, 'visibilityState', {
      value: 'hidden',
      configurable: true,
    })
    document.dispatchEvent(new Event('visibilitychange'))

    expect(beaconMock).toHaveBeenCalledTimes(1)

    Object.defineProperty(document, 'visibilityState', {
      value: 'visible',
      configurable: true,
    })

    result.stop()
    stop()
  })

  it('keeps the batch when the beacon is refused', () => {
    beaconMock.mockReturnValue(false)

    const { result, stop } = withScope(() => useLearningEvents())

    result.track('organ_viewed', { contextType: 'organ', contextId: 5 })

    window.dispatchEvent(new Event('pagehide'))

    expect(result.pending()).toBe(1)

    result.stop()
    stop()
  })

  it('drops a context it cannot express as an integer id', async () => {
    const { result, stop } = withScope(() => useLearningEvents())

    result.track('structure_selected', { contextType: 'structure', contextId: 'not-a-number' })

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS)

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body = JSON.parse(String(init.body)) as { events: Record<string, unknown>[] }

    expect(body.events[0]).not.toHaveProperty('contextId')
    expect(body.events[0]).not.toHaveProperty('contextType')

    result.stop()
    stop()
  })

  it('stops flushing once the scope is disposed', async () => {
    const { stop } = withScope(() => {
      const events = useLearningEvents()
      events.track('organ_viewed', { contextType: 'organ', contextId: 9 })

      return events
    })

    stop()

    // The dispose hook takes the last-chance flush through the beacon; the
    // interval must not fire again afterwards.
    expect(beaconMock).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(FLUSH_INTERVAL_MS * 2)

    expect(fetchMock).not.toHaveBeenCalled()
  })
})
