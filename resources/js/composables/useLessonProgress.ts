import { ref, shallowRef } from 'vue'
import type { LessonProgressDto } from '@/types/lessons'

/**
 * The lesson page's only route to the server.
 *
 * All HTTP lives here so no component queries anything (invariant 8), and so
 * both writes share one error path. Neither write is allowed to interrupt the
 * lesson: a student who loses their connection halfway through should keep
 * reading, not meet a dialog. Failures therefore surface as a quiet flag the
 * page can mention, never as a thrown error.
 *
 * The client sends the step it reached, never a percentage. Percentages are
 * derived from the lesson's own step count server-side, which is what stops a
 * client claiming 100% on step one (docs/architecture.md §5.4 rule 2).
 */
export function useLessonProgress(slug: string) {
  const progress = shallowRef<LessonProgressDto | null>(null)
  const isSaving = ref(false)
  /** Non-fatal by construction: the lesson reads fine without a saved row. */
  const failed = ref(false)

  /**
   * Guards against an out-of-order response. Clicking quickly through four
   * steps must not leave step two's row on screen because it answered last.
   */
  let requestToken = 0

  /** The furthest step already sent, so re-reading a step is not a re-post. */
  let highWaterMark = -1

  function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  }

  async function post(url: string, body: Record<string, unknown>): Promise<void> {
    const token = ++requestToken
    isSaving.value = true

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
      })

      if (!response.ok) throw new Error(`HTTP ${String(response.status)}`)

      const payload = (await response.json()) as { data: LessonProgressDto }

      if (token === requestToken) {
        progress.value = payload.data
        failed.value = false
      }
    } catch {
      // Swallowed on purpose: the student is mid-lesson and there is nothing
      // for them to do about it. The page shows a quiet note instead
      // (docs/engineering.md §11 item 7).
      if (token === requestToken) failed.value = true
    } finally {
      if (token === requestToken) isSaving.value = false
    }
  }

  /** Fire-and-forget: the caller advances the step regardless of the result. */
  function reachStep(stepIndex: number): void {
    if (stepIndex <= highWaterMark) return
    highWaterMark = stepIndex

    void post(`/api/v1/lessons/${encodeURIComponent(slug)}/progress`, { stepIndex })
  }

  async function complete(): Promise<void> {
    await post(`/api/v1/lessons/${encodeURIComponent(slug)}/complete`, {})
  }

  /** Seeds the high-water mark from the row the page was rendered with. */
  function prime(existing: LessonProgressDto | null, stepCount: number): void {
    progress.value = existing

    if (existing === null || stepCount === 0) return

    highWaterMark = Math.round((existing.progressPercent / 100) * stepCount) - 1
  }

  return { progress, isSaving, failed, reachStep, complete, prime }
}
