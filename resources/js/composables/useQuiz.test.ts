import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useQuiz } from './useQuiz'
import type { AttemptVerdict, QuizDto, QuizQuestion } from '@/types/quiz'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'

/**
 * The round's own rules, independent of any component.
 *
 * The one that matters most is negative: nothing here decides whether an
 * answer was right. Every test below feeds the verdict in from a faked
 * response, because that is the only way it can ever arrive (invariant 4).
 */

const SPATIAL: QuizQuestion = {
  id: '1',
  type: 'spatial',
  question: 'Find the left ventricle.',
  difficulty: 1,
  hint: 'It has the thickest wall.',
  options: [],
}

const MCQ: QuizQuestion = {
  id: '2',
  type: 'mcq',
  question: 'Which chamber receives blood from the lungs?',
  difficulty: 1,
  hint: null,
  options: [
    { id: '10', label: 'The right atrium', value: 'ra' },
    { id: '11', label: 'The left atrium', value: 'la' },
  ],
}

function quizOf(questions: QuizQuestion[]): QuizDto {
  return {
    slug: 'heart',
    title: 'Heart quiz',
    questionCount: questions.length,
    organ: createOrgan({ structures: [createStructure()] }),
    questions,
  }
}

function verdictOf(overrides: Partial<AttemptVerdict> = {}): AttemptVerdict {
  return {
    attemptId: '1',
    isCorrect: true,
    correctStructureId: null,
    correctOptionId: null,
    explanation: null,
    masteryDelta: null,
    ...overrides,
  }
}

/** One faked `fetch` returning the given verdict. */
function respondWith(verdict: AttemptVerdict, status = 200): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve({ data: verdict }),
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/**
 * `onScopeDispose` needs an effect scope, and running each round inside one
 * also proves the dwell timer is cleaned up rather than leaking into the next
 * test's fake clock.
 */
function inScope<T>(build: () => T): { value: T; stop: () => void } {
  const scope = effectScope()
  const value = scope.run(build) as T
  return { value, stop: () => scope.stop() }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('order', () => {
  it('asks every question once per round', () => {
    const questions = Array.from({ length: 20 }, (_, i) => ({ ...SPATIAL, id: String(i) }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf(questions)))

    expect(round.total.value).toBe(20)
    expect(new Set(round.order.value.map((q) => q.id)).size).toBe(20)

    stop()
  })

  it('reshuffles on restart rather than replaying the same order', () => {
    // Fisher–Yates on 30 items: an identical permutation twice running is
    // possible but has probability 1/30!, which is not a flaky test.
    const questions = Array.from({ length: 30 }, (_, i) => ({ ...SPATIAL, id: String(i) }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf(questions)))

    const first = round.order.value.map((q) => q.id).join()
    round.restart()
    const second = round.order.value.map((q) => q.id).join()

    expect(second).not.toBe(first)
    expect(new Set(round.order.value.map((q) => q.id)).size).toBe(30)

    stop()
  })

  it('finishes immediately when there is nothing to ask', () => {
    const { value: round, stop } = inScope(() => useQuiz(quizOf([])))

    expect(round.phase.value).toBe('finished')
    expect(round.current.value).toBeNull()

    stop()
  })
})

describe('answering', () => {
  it('posts the pick and the timing, and never a verdict', async () => {
    const fetchMock = respondWith(verdictOf())
    let clock = 1_000
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL]), { now: () => clock }))

    clock = 4_500
    await round.answer({ structureId: '7' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/quizzes/heart/attempt')

    const body = JSON.parse(String(init.body)) as Record<string, unknown>
    expect(body).toEqual({
      questionId: 1,
      selectedStructureId: 7,
      selectedOptionId: null,
      answerText: null,
      timeSpentMs: 3_500,
      hintUsed: false,
    })
    expect(Object.keys(body)).not.toContain('isCorrect')

    stop()
  })

  it('takes the verdict from the server and counts it', async () => {
    respondWith(verdictOf({ isCorrect: true }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL, MCQ])))

    await round.answer({ structureId: '7' })

    expect(round.verdict.value?.isCorrect).toBe(true)
    expect(round.correctCount.value).toBe(1)
    expect(round.answeredCount.value).toBe(1)
    expect(round.phase.value).toBe('revealing')

    stop()
  })

  it('reports what to flash: the pick, and the answer on a miss', async () => {
    respondWith(verdictOf({ isCorrect: false, correctStructureId: '42' }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL])))

    await round.answer({ structureId: '7' })

    expect(round.reveal.value).toEqual({ picked: '7', correct: '42' })

    stop()
  })

  it('ignores a second answer while the first is being revealed', async () => {
    const fetchMock = respondWith(verdictOf())
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL, MCQ])))

    await round.answer({ structureId: '7' })
    await round.answer({ structureId: '8' })

    expect(fetchMock).toHaveBeenCalledTimes(1)

    stop()
  })
})

describe('dwell', () => {
  it('moves on after 1.2s when the answer was right', async () => {
    respondWith(verdictOf({ isCorrect: true }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL, MCQ])))

    await round.answer({ structureId: '7' })

    vi.advanceTimersByTime(1_199)
    expect(round.index.value).toBe(0)

    vi.advanceTimersByTime(1)
    expect(round.index.value).toBe(1)
    expect(round.phase.value).toBe('answering')
    expect(round.verdict.value).toBeNull()

    stop()
  })

  it('holds for 2.4s when it was wrong, because there is more to read', async () => {
    respondWith(verdictOf({ isCorrect: false, correctStructureId: '42' }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL, MCQ])))

    await round.answer({ structureId: '7' })

    vi.advanceTimersByTime(1_200)
    expect(round.index.value).toBe(0)

    vi.advanceTimersByTime(1_200)
    expect(round.index.value).toBe(1)

    stop()
  })

  it('finishes the round after the last question', async () => {
    respondWith(verdictOf({ isCorrect: true }))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL])))

    await round.answer({ structureId: '7' })
    vi.advanceTimersByTime(1_200)

    expect(round.phase.value).toBe('finished')
    expect(round.current.value).toBeNull()

    stop()
  })
})

describe('hints', () => {
  it('records that a hint was used, for the rest of the question', async () => {
    const fetchMock = respondWith(verdictOf())
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL])))

    round.showHint()
    expect(round.hintShown.value).toBe(true)

    await round.answer({ structureId: '7' })

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(JSON.parse(String(init.body)).hintUsed).toBe(true)

    stop()
  })
})

describe('failure', () => {
  it('lets the student try again when the answer could not be sent', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL])))

    await round.answer({ structureId: '7' })

    expect(round.phase.value).toBe('answering')
    expect(round.answeredCount.value).toBe(0)
    expect(round.error.value).toContain('nothing has been recorded')

    stop()
  })

  it('explains a rejected answer without showing a status code', async () => {
    respondWith(verdictOf(), 429)
    const { value: round, stop } = inScope(() => useQuiz(quizOf([SPATIAL])))

    await round.answer({ structureId: '7' })

    expect(round.phase.value).toBe('answering')
    expect(round.error.value).not.toContain('429')
    expect(round.verdict.value).toBeNull()

    stop()
  })
})
