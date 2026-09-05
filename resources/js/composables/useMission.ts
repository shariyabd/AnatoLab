/**
 * One run at a mission: the walk through the steps, the timing, the submission.
 *
 * All HTTP lives here so no component queries anything (invariant 8), and —
 * more importantly — so there is exactly one place that could be tempted to
 * decide whether a step was right. It never does, and in this lane it *cannot*:
 * `MissionDto` carries no target for it to compare against
 * (docs/handovers/09-missions.md, "no client-side validation of order"). The
 * run is posted whole and the server answers with the outcomes.
 *
 * Two behaviours are worth stating up front:
 *
 * - **The run is submitted once, at the end.** A step-at-a-time endpoint would
 *   have to say right or wrong after each click, and a student clicking every
 *   structure in turn would read the pathway out of the responses one 200 at a
 *   time (App\Services\Assessment\MissionAttemptData).
 * - **Nothing is revealed during the walk.** The trail shows *what was picked*,
 *   never how it went, so the run cannot be steered by watching for a colour
 *   change. Feedback arrives in one piece, after submitting.
 *
 * Timing starts when a step appears, not when the run does — the same rule
 * `useQuiz` follows, and for the same reason: per-step timing is a mastery
 * signal, and a figure measured from the wrong moment is a wrong figure.
 */

import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import type { MissionDto, MissionOutcome, MissionPick, MissionStep } from '@/types/missions'

export type MissionPhase = 'running' | 'submitting' | 'reviewing'

export interface MissionRunOptions {
  /**
   * Milliseconds elapsed, injected for tests.
   *
   * `performance.now()` in a jsdom test with fake timers does not advance, and
   * a test that cannot control the clock ends up asserting nothing about it.
   */
  readonly now?: () => number
}

export function useMission(mission: MissionDto, options: MissionRunOptions = {}) {
  const now = options.now ?? (() => performance.now())

  const index = ref(0)
  const phase = ref<MissionPhase>(mission.steps.length === 0 ? 'reviewing' : 'running')
  /** `shallowRef`: picks are plain objects, and deep-tracking them buys nothing. */
  const picks = shallowRef<readonly (MissionPick | null)[]>(mission.steps.map(() => null))
  const outcome = shallowRef<MissionOutcome | null>(null)
  const error = ref<string | null>(null)
  const hintShown = ref(false)
  /** Which step the review is focused on, so the viewer can follow along. */
  const reviewIndex = ref<number | null>(null)

  let hintUsed = false
  let startedAt = now()
  const runStartedAt = now()

  const total = computed(() => mission.steps.length)

  /** Null once the walk is over, not "the last step, still". */
  const current = computed<MissionStep | null>(() =>
    phase.value === 'running' ? (mission.steps[index.value] ?? null) : null,
  )

  const isLast = computed(() => index.value >= total.value - 1)
  const answeredCount = computed(() => picks.value.filter((pick) => pick !== null).length)

  /**
   * The step being reviewed, with its outcome — what the trail and the viewer
   * are both driven from.
   */
  const reviewed = computed(() => {
    if (outcome.value === null || reviewIndex.value === null) return null
    return outcome.value.perStep[reviewIndex.value] ?? null
  })

  function beginStep(): void {
    hintShown.value = false
    hintUsed = false
    startedAt = now()
  }

  function showHint(): void {
    hintShown.value = true
    // Sticky for the rest of the step: a hint that has been read cannot be
    // un-read, and a hinted step is worth less (App\Enums\MissionStepOutcome).
    hintUsed = true
  }

  function recordPick(structureId: string | null): void {
    if (phase.value !== 'running') return

    const next = [...picks.value]
    next[index.value] = {
      structureId,
      hintUsed,
      timeSpentMs: Math.max(0, Math.round(now() - startedAt)),
    }
    picks.value = next
  }

  /**
   * Take a pick and move on. Nothing is judged here — the record is what the
   * student chose, and the walk continues either way.
   */
  function commit(structureId: string | null): void {
    if (phase.value !== 'running') return

    recordPick(structureId)

    if (isLast.value) {
      void submit()
      return
    }

    index.value += 1
    beginStep()
  }

  /** Move on without a pick. A skipped step is a miss, decided server-side. */
  function skip(): void {
    commit(null)
  }

  /** Step back to revisit a prompt. The earlier pick stays recorded until replaced. */
  function back(): void {
    if (phase.value !== 'running' || index.value === 0) return
    index.value -= 1
    beginStep()
  }

  async function submit(): Promise<void> {
    if (phase.value === 'submitting') return

    phase.value = 'submitting'
    error.value = null

    try {
      const response = await fetch(`/api/v1/missions/${mission.slug}/attempt`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          steps: picks.value.map((pick) => ({
            selectedStructureId: pick?.structureId == null ? null : Number(pick.structureId),
            hintUsed: pick?.hintUsed ?? false,
            timeSpentMs: pick?.timeSpentMs ?? null,
          })),
          durationMs: Math.max(0, Math.round(now() - runStartedAt)),
        }),
      })

      if (!response.ok) {
        error.value = await messageFor(response)
        // Back to the last step rather than to a dead end: the run was never
        // recorded, so the student has not spent it and may submit again.
        phase.value = 'running'
        index.value = Math.max(0, total.value - 1)
        return
      }

      const payload = (await response.json()) as { data: MissionOutcome }
      outcome.value = payload.data
      // Open the review on the step that broke, or on the first step when
      // nothing did — that is the one the student wants explained.
      reviewIndex.value = payload.data.firstMissedStep ?? 0
      phase.value = 'reviewing'
    } catch {
      // fetch rejects only for a transport failure — it resolves on 4xx and
      // 5xx. So this really is "the network went away".
      error.value =
        'We could not send that run. Check your connection and try again — nothing has ' +
        'been recorded.'
      phase.value = 'running'
      index.value = Math.max(0, total.value - 1)
    }
  }

  function review(stepIndex: number): void {
    if (outcome.value === null) return
    reviewIndex.value = stepIndex
  }

  function restart(): void {
    index.value = 0
    picks.value = mission.steps.map(() => null)
    outcome.value = null
    reviewIndex.value = null
    error.value = null
    phase.value = mission.steps.length === 0 ? 'reviewing' : 'running'
    beginStep()
  }

  onScopeDispose(() => {
    outcome.value = null
  })

  return {
    // state
    index,
    total,
    current,
    phase,
    picks,
    outcome,
    reviewIndex,
    reviewed,
    error,
    hintShown,
    answeredCount,
    isLast,

    // commands
    commit,
    skip,
    back,
    showHint,
    submit,
    review,
    restart,
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/** Turns a failed response into something a student can act on. */
async function messageFor(response: Response): Promise<string> {
  if (response.status === 429) {
    return 'That is a lot of runs very quickly — give it a moment and try again.'
  }

  if (response.status === 401 || response.status === 419) {
    return 'Your session expired. Reload the page and log in again to keep going.'
  }

  if (response.status === 404) {
    return 'This mission is no longer available. Reload the page for the current list.'
  }

  let message: string | null = null

  try {
    const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> }
    message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? null
  } catch {
    message = null
  }

  return message ?? 'That run could not be sent. Try again in a moment.'
}
