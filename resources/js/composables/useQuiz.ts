/**
 * One round of a quiz: the order, the timing, the answer round-trip, the score.
 *
 * All HTTP lives here so no component queries anything (invariant 8), and —
 * more importantly — so there is exactly one place that could be tempted to
 * decide whether an answer was right. It never does: `answer()` posts what the
 * student picked and waits for the server's verdict
 * (docs/architecture.md §5.4 rule 2). Nothing in this file compares an id to
 * an answer, because nothing in this file has ever seen one.
 *
 * Three behaviours are reproduced from the audited implementation, which got
 * the interaction design right even though its scoring was client-side and
 * ephemeral (docs/project-context.md §2.4):
 *
 * - **Fisher–Yates**, so every question is asked once per round in a fresh
 *   order. A `sort(() => Math.random() - 0.5)` is not a shuffle: it is biased
 *   and, with some comparison sorts, not even a permutation.
 * - **A wrong answer dwells longer than a right one** — 2.4 s against 1.2 s —
 *   because there is an explanation to read, and being marched on before
 *   finishing it is how a learner stops reading them.
 * - **Timing starts when the question appears**, not when the round does.
 */

import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import type { AttemptVerdict, QuizDto, QuizQuestion } from '@/types/quiz'

/** Long enough to register the green ring, short enough not to stall a run. */
const CORRECT_DWELL_MS = 1_200

/** Twice as long: there is an explanation, and a revealed answer, to take in. */
const INCORRECT_DWELL_MS = 2_400

export type QuizPhase = 'answering' | 'revealing' | 'finished'

export interface QuizRoundOptions {
  /**
   * Milliseconds elapsed, injected for tests.
   *
   * `performance.now()` in a jsdom test with fake timers does not advance, and
   * a test that cannot control the clock ends up asserting nothing about it.
   */
  readonly now?: () => number
}

export function useQuiz(quiz: QuizDto, options: QuizRoundOptions = {}) {
  const now = options.now ?? (() => performance.now())

  /** `shallowRef`: questions are plain frozen objects from a page prop. */
  const order = shallowRef<readonly QuizQuestion[]>(shuffle(quiz.questions))
  const index = ref(0)
  const phase = ref<QuizPhase>(quiz.questions.length === 0 ? 'finished' : 'answering')
  const verdict = shallowRef<AttemptVerdict | null>(null)
  const error = ref<string | null>(null)
  const correctCount = ref(0)
  const answeredCount = ref(0)
  const hintShown = ref(false)
  const hintUsed = ref(false)

  let startedAt = now()
  let dwellTimer: ReturnType<typeof setTimeout> | null = null

  const total = computed(() => order.value.length)
  /**
   * Null once the round is over, not "the last question, still".
   *
   * The page branches on this to swap the question card for the summary, so a
   * finished round that still reports a current question renders a quiz the
   * student cannot answer and no summary at all.
   */
  const current = computed<QuizQuestion | null>(() =>
    phase.value === 'finished' ? null : (order.value[index.value] ?? null),
  )
  const isLast = computed(() => index.value >= total.value - 1)

  /** What the student clicked on the model, for the red flash on a miss. */
  const lastPickedStructureId = shallowRef<string | null>(null)

  /**
   * The structure the viewer should reveal in green, if any.
   *
   * Exposed as state rather than fired as a callback so the page can drive the
   * viewer from a `watch` and a test can assert the value without a spy.
   */
  const reveal = computed<{ picked: string | null; correct: string | null } | null>(() => {
    if (verdict.value === null) return null
    return { picked: lastPickedStructureId.value, correct: verdict.value.correctStructureId }
  })

  function clearDwell(): void {
    if (dwellTimer === null) return
    clearTimeout(dwellTimer)
    dwellTimer = null
  }

  function beginQuestion(): void {
    verdict.value = null
    lastPickedStructureId.value = null
    hintShown.value = false
    hintUsed.value = false
    error.value = null
    startedAt = now()
  }

  function showHint(): void {
    hintShown.value = true
    // Sticky for the rest of the question: a hint that has been read cannot be
    // un-read, and mastery penalises hinted attempts (docs/architecture.md §10).
    hintUsed.value = true
  }

  function advance(): void {
    clearDwell()

    if (isLast.value) {
      phase.value = 'finished'
      return
    }

    index.value += 1
    phase.value = 'answering'
    beginQuestion()
  }

  function restart(): void {
    clearDwell()
    order.value = shuffle(quiz.questions)
    index.value = 0
    correctCount.value = 0
    answeredCount.value = 0
    phase.value = quiz.questions.length === 0 ? 'finished' : 'answering'
    beginQuestion()
  }

  /**
   * Post one answer and reveal what the server says about it.
   *
   * `selectedStructureId` and `selectedOptionId` are sent as given. Which of
   * them is meaningful for this question is the server's business — sending
   * only the field the client thinks applies would make the client's idea of
   * the question type load-bearing.
   */
  async function answer(pick: {
    structureId?: string | null
    optionId?: string | null
    text?: string
  }): Promise<void> {
    const question = current.value

    if (question === null || phase.value !== 'answering') return

    phase.value = 'revealing'
    lastPickedStructureId.value = pick.structureId ?? null
    error.value = null

    try {
      const response = await fetch(`/api/v1/quizzes/${quiz.slug}/attempt`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          questionId: Number(question.id),
          selectedStructureId: pick.structureId == null ? null : Number(pick.structureId),
          selectedOptionId: pick.optionId == null ? null : Number(pick.optionId),
          answerText: pick.text ?? null,
          timeSpentMs: Math.max(0, Math.round(now() - startedAt)),
          hintUsed: hintUsed.value,
        }),
      })

      if (!response.ok) {
        error.value = await messageFor(response)
        // Back to answering: the attempt was never recorded, so the student
        // has not spent it and may try again.
        phase.value = 'answering'
        return
      }

      const payload = (await response.json()) as { data: AttemptVerdict }
      verdict.value = payload.data
      answeredCount.value += 1
      if (payload.data.isCorrect) correctCount.value += 1

      dwellTimer = setTimeout(
        advance,
        payload.data.isCorrect ? CORRECT_DWELL_MS : INCORRECT_DWELL_MS,
      )
    } catch {
      // fetch rejects only for a transport failure — it resolves on 4xx and
      // 5xx. So this really is "the network went away".
      error.value =
        'We could not send that answer. Check your connection and try again — nothing ' +
        'has been recorded.'
      phase.value = 'answering'
    }
  }

  onScopeDispose(clearDwell)

  return {
    // state
    order,
    index,
    total,
    current,
    phase,
    verdict,
    reveal,
    error,
    correctCount,
    answeredCount,
    hintShown,
    hintUsed,

    // commands
    answer,
    showHint,
    advance,
    restart,
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Fisher–Yates, in place on a copy.
 *
 * Every element has an equal chance of every position, which a comparator
 * shuffle does not give you (docs/project-context.md §2.4).
 */
function shuffle<T>(items: readonly T[]): readonly T[] {
  const shuffled = [...items]

  for (let i = shuffled.length - 1; i > 0; i -= 1) {
    const j = Math.floor(Math.random() * (i + 1))
    const a = shuffled[i]
    const b = shuffled[j]
    // `noUncheckedIndexedAccess` is on: both reads are in range by
    // construction, and this is how that is proved rather than asserted.
    if (a !== undefined && b !== undefined) {
      shuffled[i] = b
      shuffled[j] = a
    }
  }

  return shuffled
}

/** Turns a failed response into something a student can act on. */
async function messageFor(response: Response): Promise<string> {
  if (response.status === 429) {
    return 'That is a lot of answers very quickly — give it a moment and try again.'
  }

  if (response.status === 401 || response.status === 419) {
    return 'Your session expired. Reload the page and log in again to keep going.'
  }

  if (response.status === 404) {
    return 'That question is no longer part of this quiz. Reload the page for a fresh round.'
  }

  let message: string | null = null

  try {
    const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> }
    message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? null
  } catch {
    message = null
  }

  return message ?? 'That answer could not be sent. Try again in a moment.'
}
