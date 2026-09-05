import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import Show from './Show.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { AttemptVerdict, QuizDto } from '@/types/quiz'

/**
 * The page's contract with the rest of the platform.
 *
 * Three things here are why handover 07 exists: the viewer is put in quiz mode
 * and told nothing about correctness until the server has ruled, a miss
 * reveals the right structure in green, and the whole round is answerable with
 * no 3D at all.
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

const ANSWER = createStructure({
  id: 'str_answer',
  name: 'Left Ventricle',
  anchorPosition: [1, 0, 0],
})
const DECOY = createStructure({
  id: 'str_decoy',
  slug: 'right-atrium',
  name: 'Right Atrium',
  anchorPosition: [-1, 0, 0],
})

const QUIZ: QuizDto = {
  slug: 'heart',
  title: 'Heart quiz',
  questionCount: 1,
  organ: createOrgan({ structures: [ANSWER, DECOY] }),
  questions: [
    {
      id: '1',
      type: 'spatial',
      question: 'Find the left ventricle.',
      difficulty: 1,
      hint: null,
      options: [],
    },
  ],
}

function respondWith(verdict: Partial<AttemptVerdict>): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: () =>
        Promise.resolve({
          data: {
            attemptId: '1',
            isCorrect: false,
            correctStructureId: null,
            correctOptionId: null,
            explanation: null,
            masteryDelta: null,
            ...verdict,
          },
        }),
    }),
  )
}

/** Flush the answer round-trip: two microtask turns plus a render. */
async function settle(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  await nextTick()
}

beforeEach(() => {
  resetFakeViewers()
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

it('mounts the viewer in quiz mode', () => {
  // Quiz mode is what makes a click report `structure:picked` without leaving
  // a sticky selection a student could copy from (AnatomyViewer.setMode).
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })

  expect(lastFakeViewer().options.initialMode).toBe('quiz')

  page.unmount()
})

it('tells the viewer nothing about correctness before the server has ruled', async () => {
  respondWith({ isCorrect: false, correctStructureId: ANSWER.id })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: DECOY, pointer: { x: 0, y: 0 } })

  expect(viewer.calls.filter((call) => call.startsWith('flashStructure'))).toEqual([])

  await settle()
  page.unmount()
})

it('flashes the pick red and the answer green on a miss', async () => {
  // The audited behaviour, and the reason it exists: a student told only that
  // they were wrong has learned nothing (docs/project-context.md §2.4).
  respondWith({ isCorrect: false, correctStructureId: ANSWER.id })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: DECOY, pointer: { x: 0, y: 0 } })
  await settle()

  expect(viewer.calls).toContain(`flashStructure:${DECOY.id}:false`)
  expect(viewer.calls).toContain(`flashStructure:${ANSWER.id}:true`)

  page.unmount()
})

it('flashes only the pick when it was right', async () => {
  respondWith({ isCorrect: true, correctStructureId: ANSWER.id })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: ANSWER, pointer: { x: 0, y: 0 } })
  await settle()

  expect(viewer.calls.filter((call) => call.startsWith('flashStructure'))).toEqual([
    `flashStructure:${ANSWER.id}:true`,
  ])

  page.unmount()
})

it('names the correct structure in text, not only as a coloured ring', async () => {
  // A ring is invisible to a screen reader and to anyone whose model 404'd
  // (docs/architecture.md §5.4 rule 5).
  respondWith({
    isCorrect: false,
    correctStructureId: ANSWER.id,
    explanation: 'It drives the systemic circulation.',
  })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })

  lastFakeViewer().emit('structure:picked', { structure: DECOY, pointer: { x: 0, y: 0 } })
  await settle()

  expect(page.text()).toContain('Left Ventricle')
  expect(page.text()).toContain('It drives the systemic circulation.')

  page.unmount()
})

it('answers a spatial question from the keyboard with no canvas involved', async () => {
  respondWith({ isCorrect: true, correctStructureId: ANSWER.id })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })

  const button = page.findAll('button').find((candidate) => candidate.text() === ANSWER.name)

  expect(button).toBeDefined()
  await button?.trigger('click')
  await settle()

  expect(lastFakeViewer().calls).toContain(`flashStructure:${ANSWER.id}:true`)

  page.unmount()
})

it('shows the round summary once the last question is answered', async () => {
  respondWith({ isCorrect: true, correctStructureId: ANSWER.id })
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })

  lastFakeViewer().emit('structure:picked', { structure: ANSWER, pointer: { x: 0, y: 0 } })
  await settle()

  expect(page.text()).not.toContain('Round complete')

  vi.advanceTimersByTime(1_200)
  await nextTick()

  expect(page.text()).toContain('Round complete')
  expect(page.text()).toContain('1')

  page.unmount()
})

it('disposes the viewer when the page goes away', () => {
  const page = mount(Show, { props: { quiz: QUIZ }, attachTo: document.body })
  const viewer = lastFakeViewer()

  page.unmount()

  expect(viewer.disposeCount).toBe(1)
})
