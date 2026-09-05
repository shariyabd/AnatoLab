/**
 * One run of a simulation: the action round-trip, the current step, the log.
 *
 * All HTTP lives here so no component queries anything (invariant 8), and —
 * more importantly — so there is exactly one place that could be tempted to
 * predict a state. It never does. `apply()` posts an action id and waits for
 * the server's numbers; nothing in this file adds a delta, clamps a value, or
 * evaluates a threshold, because nothing in this file has ever seen the
 * simulation's effects table (`@/types/simulations` explains why it is not
 * sent).
 *
 * That is the same shape `useQuiz` has for the same reason: a client that can
 * compute the answer is a client that can disagree with the server about it.
 * Here the consequence is not cheating but incoherence — a readout that says
 * one thing and a 3D view driven by another.
 *
 * The composable is deliberately not responsible for the viewer. It exposes
 * `step`, and the page watches it and calls `applySimulationState`. Driving the
 * viewer from here would put a Three.js command inside a module that also holds
 * reactive state, which is the coupling `ViewerStage` exists to prevent.
 */

import { computed, ref, shallowRef } from 'vue'
import type { SimulationDto, SimulationLogEntry, SimulationStep } from '@/types/simulations'

export type SimulationPhase = 'idle' | 'applying'

export function useSimulation(
  simulation: SimulationDto,
  initialStep: SimulationStep,
  initialEvents: readonly SimulationLogEntry[] = [],
) {
  /** `shallowRef`: steps are plain frozen objects straight from the server. */
  const step = shallowRef<SimulationStep>(initialStep)
  const events = shallowRef<readonly SimulationLogEntry[]>(initialEvents)
  const phase = ref<SimulationPhase>('idle')
  const error = ref<string | null>(null)

  const isBusy = computed(() => phase.value === 'applying')

  /**
   * A terminal state is the end of *this* run, not of the page. The actions
   * stay enabled — a student who has driven a simulation into its worst state
   * should be able to undo it and watch it recover, which is half of what
   * "what happens if" teaches.
   */
  const isTerminal = computed(() => step.value.isTerminal)

  async function post(path: string, body?: Record<string, unknown>): Promise<void> {
    if (phase.value === 'applying') return

    phase.value = 'applying'
    error.value = null

    try {
      const response = await fetch(path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body ?? {}),
      })

      if (!response.ok) {
        error.value = await messageFor(response)
        return
      }

      const payload = (await response.json()) as { data: SimulationStep }
      step.value = payload.data
      events.value = appendTo(events.value, payload.data)
    } catch {
      // fetch rejects only for a transport failure — it resolves on 4xx and
      // 5xx. So this really is "the network went away".
      error.value =
        'We could not run that step. Check your connection and try again — the simulation ' +
        'has not moved.'
    } finally {
      phase.value = 'idle'
    }
  }

  async function apply(actionId: string): Promise<void> {
    await post(`/api/v1/simulations/${simulation.slug}/event`, { actionId })
  }

  async function reset(): Promise<void> {
    await post(`/api/v1/simulations/${simulation.slug}/reset`)
  }

  return {
    // state
    step,
    events,
    phase,
    error,
    isBusy,
    isTerminal,

    // commands
    apply,
    reset,
  }
}

/**
 * Mirror the server's log locally so the panel updates without a page visit.
 *
 * Sequence 0 empties it: that is a reset, and the server has just done the
 * same. Otherwise the step is appended at its own sequence, which also repairs
 * the list if a response ever arrived out of order.
 */
function appendTo(
  events: readonly SimulationLogEntry[],
  step: SimulationStep,
): readonly SimulationLogEntry[] {
  if (step.sequence === 0) return []

  const entry: SimulationLogEntry = {
    sequence: step.sequence,
    action_id: step.actionId,
    action_label: step.actionLabel,
    state: step.variables,
    state_key: step.stateKey,
    outcomes: step.outcomes.map((outcome) => outcome.key),
    explanation: step.explanation,
    explanation_source: step.explanationSource,
  }

  return [...events.slice(0, step.sequence - 1), entry]
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/** Turns a failed response into something a student can act on. */
async function messageFor(response: Response): Promise<string> {
  if (response.status === 429) {
    return 'That is a lot of steps very quickly — give it a moment and try again.'
  }

  if (response.status === 401 || response.status === 419) {
    return 'Your session expired. Reload the page and log in again to keep going.'
  }

  if (response.status === 404) {
    return 'That action is no longer part of this simulation. Reload the page for a fresh run.'
  }

  let message: string | null = null

  try {
    const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> }
    message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? null
  } catch {
    message = null
  }

  return message ?? 'That step could not be run. Try again in a moment.'
}
