/**
 * The tutor panel's only route to the server.
 *
 * All HTTP lives here so no component queries anything (invariant 8), and so
 * the three endpoints share one error path. That error path matters more than
 * usual: a provider outage must degrade into a sentence a 14-year-old can act
 * on, never a status code (docs/architecture.md §14, PRD §40).
 */

import { computed, ref, shallowRef } from 'vue'
import type { TutorReply, TutorSubject, TutorTask, TutorTurn } from '@/types/tutor'

/** What the server sends when something went wrong, in its two shapes. */
interface ErrorBody {
  message?: string
  errors?: Record<string, string[]>
}

const ENDPOINTS: Record<TutorTask, string> = {
  ask: '/api/v1/ai/tutor/ask',
  explain: '/api/v1/ai/tutor/explain',
  hint: '/api/v1/ai/tutor/hint',
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

export function useTutor(subject: () => TutorSubject) {
  const turns = ref<TutorTurn[]>([])
  const isThinking = ref(false)
  const error = ref<string | null>(null)
  /** `shallowRef`: the id is replaced wholesale, never mutated. */
  const conversationId = shallowRef<number | null>(null)

  const lastAssistantTurn = computed(() =>
    [...turns.value].reverse().find((turn) => turn.role === 'assistant'),
  )

  let turnCounter = 0

  function nextKey(): string {
    turnCounter += 1
    return `turn-${turnCounter}`
  }

  function reset(): void {
    turns.value = []
    conversationId.value = null
    error.value = null
  }

  async function send(task: TutorTask, question: string): Promise<void> {
    if (isThinking.value) return

    const { organSlug, structureId } = subject()
    error.value = null

    // The student's turn is shown immediately. Waiting for the round trip to
    // echo it back makes the panel feel broken on a slow connection.
    if (question !== '') {
      turns.value = [...turns.value, { key: nextKey(), role: 'user', content: question }]
    }

    isThinking.value = true

    try {
      const response = await fetch(ENDPOINTS[task], {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          question: question === '' ? undefined : question,
          organSlug: organSlug ?? undefined,
          structureId: structureId ?? undefined,
          conversationId: conversationId.value ?? undefined,
        }),
      })

      if (!response.ok) {
        error.value = await messageFor(response)
        return
      }

      const payload = (await response.json()) as { data: TutorReply }
      const reply = payload.data

      conversationId.value = reply.conversationId
      turns.value = [
        ...turns.value,
        {
          key: nextKey(),
          role: 'assistant',
          content: reply.answer,
          sources: reply.sources,
          sourceNote: reply.sourceNote,
          followUps: reply.followUpQuestions,
        },
      ]
    } catch {
      // Thrown only for a transport failure — fetch does not reject on a 4xx or
      // 5xx. So this really is "the network went away", not "the server said no".
      error.value =
        'We could not reach the tutor. Check your connection — the 3D model and the ' +
        'structure notes still work without it.'
    } finally {
      isThinking.value = false
    }
  }

  /**
   * Turns a failed response into something a student can act on.
   *
   * Never surfaces a status code or a provider name. The 503 body already
   * carries an educational-tone message written by the exception handler
   * (bootstrap/app.php), so that one is passed through; the rest are mapped
   * here because Laravel's defaults ("Too Many Attempts.") are not written for
   * this audience.
   */
  async function messageFor(response: Response): Promise<string> {
    let body: ErrorBody = {}

    try {
      body = (await response.json()) as ErrorBody
    } catch {
      body = {}
    }

    if (response.status === 429) {
      return (
        'That is a lot of questions in a short time — the tutor needs a minute. ' +
        'The structure notes and the 3D model are still available.'
      )
    }

    if (response.status === 422) {
      const first = Object.values(body.errors ?? {})[0]?.[0]
      return first ?? 'That question could not be sent. Try rephrasing it.'
    }

    if (response.status === 401 || response.status === 419) {
      return 'Your session expired. Reload the page and log in again to keep asking.'
    }

    return (
      body.message ??
      'The tutor is unavailable right now. Everything else still works — try the ' +
        'structure notes or the 3D model while we sort it out.'
    )
  }

  return {
    turns,
    isThinking,
    error,
    conversationId,
    lastAssistantTurn,
    reset,
    ask: (question: string): Promise<void> => send('ask', question),
    explain: (): Promise<void> => send('explain', ''),
    hint: (question = ''): Promise<void> => send('hint', question),
  }
}
