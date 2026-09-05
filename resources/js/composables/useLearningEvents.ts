import { onScopeDispose } from 'vue'
import type { ClientEvent, ClientEventType, EventContextType } from '@/types/progress'

/**
 * Batches viewer interactions and flushes them to `POST /api/v1/events`.
 *
 * PRD §29 and docs/architecture.md §13: the four events the server cannot
 * observe — the student opened an organ, selected a structure, isolated it,
 * changed the layer — are reported by the browser, on an interval and on page
 * unload. Everything that feeds a score is recorded server-side and is
 * rejected by this endpoint, so nothing here can inflate a student's own
 * progress (App\Enums\LearningEventType).
 *
 * Three properties this has to have, and each one costs a few lines:
 *
 * - **The unload flush must survive the page.** `fetch` in a `pagehide`
 *   handler is cancelled when the document goes away; `navigator.sendBeacon`
 *   is the only transport the browser promises to finish. A beacon cannot set
 *   headers, so it carries the CSRF token as `_token` in the JSON body, which
 *   is where Laravel's VerifyCsrfToken looks next after the header. The
 *   interval flush uses `fetch`, which can send the header and can retry.
 * - **A failed flush must not lose the batch.** Events are put back at the
 *   front of the queue, bounded, so a network blip costs latency rather than a
 *   day's analytics.
 * - **It must not fetch from the viewer.** This is a Vue-layer composable;
 *   `resources/js/anatomy/` neither imports it nor knows it exists
 *   (invariant 3).
 *
 * `visibilitychange` as well as `pagehide`: a phone backgrounding the tab may
 * never fire `pagehide` at all, and a mobile session that flushes nothing is
 * the case PRD §30's engagement metrics would silently miss.
 */

/** How often a non-empty queue is flushed, in milliseconds. */
export const FLUSH_INTERVAL_MS = 15_000

/**
 * The cap the server enforces (AnalyticsService::MAX_BATCH). Flushing at the
 * same number means a batch is never rejected wholesale for being one event
 * over.
 */
export const MAX_BATCH = 50

/**
 * How many events may wait in the queue.
 *
 * Beyond this the oldest are dropped. An offline tab left open for an hour
 * should cost its most recent activity, not the browser's memory.
 */
export const MAX_QUEUE = 200

const ENDPOINT = '/api/v1/events'

interface TrackContext {
  readonly contextType?: EventContextType
  readonly contextId?: number | string | null
  readonly payload?: Readonly<Record<string, string>>
}

export function useLearningEvents() {
  let queue: ClientEvent[] = []
  let timer: ReturnType<typeof setInterval> | null = null

  function track(type: ClientEventType, context: TrackContext = {}): void {
    const id = normaliseId(context.contextId)

    queue.push({
      type,
      occurredAt: new Date().toISOString(),
      ...(context.contextType !== undefined && id !== null
        ? { contextType: context.contextType, contextId: id }
        : {}),
      ...(context.payload !== undefined ? { payload: context.payload } : {}),
    })

    if (queue.length > MAX_QUEUE) {
      queue = queue.slice(queue.length - MAX_QUEUE)
    }
  }

  /**
   * Send what is queued. Returns the events it took, so a caller can tell
   * whether anything went out.
   */
  function flush(): ClientEvent[] {
    if (queue.length === 0) return []

    const batch = queue.slice(0, MAX_BATCH)
    queue = queue.slice(batch.length)

    void post(batch).catch(() => {
      // Requeue at the front: order is not load-bearing for analytics, but
      // dropping a batch on a transient failure is a hole in the metrics.
      queue = [...batch, ...queue].slice(0, MAX_QUEUE)
    })

    return batch
  }

  /**
   * The last-chance flush. Uses `sendBeacon`, which the browser completes
   * after the document is gone; a `fetch` here would simply be cancelled.
   */
  function flushOnUnload(): void {
    if (queue.length === 0) return

    const batch = queue.slice(0, MAX_BATCH)

    // `_token` rather than an `X-CSRF-TOKEN` header: sendBeacon sets no
    // headers. Laravel reads the field from the parsed JSON body, and
    // RecordEventsRequest validates `events` alone, so it never reaches the
    // service.
    const body = JSON.stringify({ events: batch, _token: csrfToken() ?? '' })

    const sent =
      typeof navigator !== 'undefined' &&
      typeof navigator.sendBeacon === 'function' &&
      navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }))

    if (sent === true) {
      queue = queue.slice(batch.length)
    }
  }

  function onVisibilityChange(): void {
    if (document.visibilityState === 'hidden') flushOnUnload()
  }

  function start(): void {
    if (timer !== null) return

    timer = setInterval(flush, FLUSH_INTERVAL_MS)

    window.addEventListener('pagehide', flushOnUnload)
    document.addEventListener('visibilitychange', onVisibilityChange)
  }

  function stop(): void {
    if (timer !== null) {
      clearInterval(timer)
      timer = null
    }

    window.removeEventListener('pagehide', flushOnUnload)
    document.removeEventListener('visibilitychange', onVisibilityChange)

    flushOnUnload()
  }

  onScopeDispose(stop)

  start()

  return { track, flush, flushOnUnload, stop, pending: () => queue.length }
}

/**
 * Ids cross the wire as strings (docs/architecture.md §5.4 rule 4) and the
 * endpoint wants an integer. Anything that is not one is dropped along with
 * its context rather than sent as a guess.
 */
function normaliseId(id: number | string | null | undefined): number | null {
  if (id === null || id === undefined) return null

  const parsed = typeof id === 'number' ? id : Number.parseInt(id, 10)

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function csrfToken(): string | null {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.getAttribute('content') ??
    null
  )
}

async function post(events: readonly ClientEvent[]): Promise<void> {
  const token = csrfToken()

  const response = await fetch(ENDPOINT, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token != null ? { 'X-CSRF-TOKEN': token } : {}),
    },
    body: JSON.stringify({ events }),
  })

  if (!response.ok) throw new Error(`HTTP ${String(response.status)}`)
}
