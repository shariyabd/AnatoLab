import { beforeEach, afterEach, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import Show from './Show.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { MissionDto, MissionOutcome } from '@/types/missions'

/**
 * The page's contract with the rest of the platform.
 *
 * Four things here are why handover 09 exists: the viewer is put in mission
 * mode, it is told nothing about correctness while the run is in progress, the
 * per-step feedback walks the student back through the pathway afterwards, and
 * the whole run is playable with no 3D at all.
 *
 * The page adds no viewer method. `setMode`, `focusStructure` and
 * `flashStructure` are all in handover 04's merged interface, which is what the
 * assertions below are written against (docs/architecture.md §5.2).
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

/**
 * Numeric-string ids, because that is what the API emits: every Resource
 * stringifies its integer key (`(string) $this->id`), and the composable posts
 * them back as numbers for a FormRequest that validates `integer`. A fixture
 * with a symbolic id would test a payload shape the server never sends.
 */
const ATRIUM = createStructure({
  id: '101',
  slug: 'left-atrium',
  name: 'Left Atrium',
  anchorPosition: [1, 0, 0],
})
const VENTRICLE = createStructure({
  id: '102',
  slug: 'left-ventricle',
  name: 'Left Ventricle',
  anchorPosition: [0, -1, 0],
})
const AORTA = createStructure({
  id: '103',
  slug: 'aorta',
  name: 'Aorta',
  anchorPosition: [0, 1, 0],
})

const MISSION: MissionDto = {
  slug: 'trace-the-blood',
  title: 'Trace the Blood',
  description: 'Follow one drop of blood.',
  type: 'trace_pathway',
  typeLabel: 'Trace the pathway',
  ordered: true,
  difficulty: 2,
  stepCount: 2,
  maxScore: 20,
  scoring: { correct: 10, afterHint: 6, wrong: 0 },
  organ: createOrgan({ structures: [ATRIUM, VENTRICLE, AORTA] }),
  steps: [
    { index: 0, prompt: 'Where does the blood arrive?', hint: 'A receiving chamber.' },
    { index: 1, prompt: 'Where does it go next?', hint: null },
  ],
}

function respondWith(outcome: Partial<MissionOutcome>): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: () =>
        Promise.resolve({
          data: {
            missionAttemptId: '1',
            score: 0,
            maxScore: 20,
            completed: false,
            correctCount: 0,
            firstMissedStep: null,
            feedback: 'Run recorded.',
            perStep: [],
            ...outcome,
          },
        }),
    }),
  )
}

/** Flush the submission round-trip: two microtask turns plus a render. */
async function settle(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  await nextTick()
}

beforeEach(() => {
  resetFakeViewers()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

it('mounts the viewer in mission mode', () => {
  // Mission mode is what makes a click report `structure:picked` without
  // leaving a sticky selection a student could read backwards
  // (AnatomyViewer.setMode).
  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })

  expect(lastFakeViewer().options.initialMode).toBe('mission')

  page.unmount()
})

it('tells the viewer nothing about correctness while the run is in progress', async () => {
  // The page has no target to compare against — `MissionDto` carries none —
  // and this asserts it never behaves as though it does.
  respondWith({})
  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await nextTick()

  expect(viewer.calls.filter((call) => call.startsWith('flashStructure'))).toEqual([])
  expect(page.text()).toContain('Where does it go next?')

  page.unmount()
})

it('submits the whole run once, on the last step', async () => {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: () =>
      Promise.resolve({
        data: {
          missionAttemptId: '1',
          score: 20,
          maxScore: 20,
          completed: true,
          correctCount: 2,
          firstMissedStep: null,
          feedback: 'Perfect trace — all 2 steps in the right order.',
          perStep: [],
        },
      }),
  })
  vi.stubGlobal('fetch', fetchMock)

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: ATRIUM, pointer: { x: 0, y: 0 } })
  await nextTick()

  // Nothing posted yet: a step-at-a-time endpoint would let a student read the
  // pathway out of the responses one 200 at a time.
  expect(fetchMock).not.toHaveBeenCalled()

  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await settle()

  expect(fetchMock).toHaveBeenCalledTimes(1)

  const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
  expect(url).toBe('/api/v1/missions/trace-the-blood/attempt')

  const body = JSON.parse(String(init.body)) as {
    steps: { selectedStructureId: number | null; hintUsed: boolean }[]
  }
  expect(body.steps).toHaveLength(2)
  expect(body.steps[0]?.selectedStructureId).toBe(Number(ATRIUM.id))

  page.unmount()
})

it('flashes the pick red and the revealed structure green on the step that broke', async () => {
  // The audited behaviour, and the reason it exists: a student told only that
  // they were wrong has learned nothing (docs/project-context.md §2.4).
  respondWith({
    score: 10,
    correctCount: 1,
    firstMissedStep: 0,
    feedback: '1 of 2 steps traced correctly. The pathway breaks at step 1.',
    perStep: [
      {
        index: 0,
        outcome: 'wrong',
        outcomeLabel: 'Not this one',
        points: 0,
        prompt: 'Where does the blood arrive?',
        explanation: 'The left atrium.',
        selectedStructureId: VENTRICLE.id,
        revealedStructureId: ATRIUM.id,
      },
      {
        index: 1,
        outcome: 'correct',
        outcomeLabel: 'Correct',
        points: 10,
        prompt: 'Where does it go next?',
        explanation: null,
        selectedStructureId: VENTRICLE.id,
        revealedStructureId: null,
      },
    ],
  })

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await nextTick()
  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await settle()

  expect(viewer.calls).toContain(`flashStructure:${VENTRICLE.id}:false`)
  expect(viewer.calls).toContain(`flashStructure:${ATRIUM.id}:true`)
  // Both are focused, which is the choreography the merged interface already
  // provides — this lane adds no viewer method (docs/architecture.md §5.2).
  expect(viewer.calls).toContain(`focusStructure:${ATRIUM.id}`)

  page.unmount()
})

it('walks back through a step chosen from the trail', async () => {
  respondWith({
    score: 10,
    correctCount: 1,
    firstMissedStep: 0,
    feedback: '1 of 2 steps traced correctly. The pathway breaks at step 1.',
    perStep: [
      {
        index: 0,
        outcome: 'wrong',
        outcomeLabel: 'Not this one',
        points: 0,
        prompt: 'Where does the blood arrive?',
        explanation: null,
        selectedStructureId: VENTRICLE.id,
        revealedStructureId: ATRIUM.id,
      },
      {
        index: 1,
        outcome: 'correct',
        outcomeLabel: 'Correct',
        points: 10,
        prompt: 'Where does it go next?',
        explanation: null,
        selectedStructureId: AORTA.id,
        revealedStructureId: null,
      },
    ],
  })

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await nextTick()
  viewer.emit('structure:picked', { structure: AORTA, pointer: { x: 0, y: 0 } })
  await settle()

  const second = page.findAll('button').find((button) => button.text().includes('Step 2'))
  expect(second).toBeDefined()

  await second?.trigger('click')
  await nextTick()

  expect(viewer.calls).toContain(`focusStructure:${AORTA.id}`)
  expect(viewer.calls).toContain(`flashStructure:${AORTA.id}:true`)

  page.unmount()
})

it('names the revealed structure in text, not only as a coloured ring', async () => {
  // A ring is invisible to a screen reader and to anyone whose model 404'd
  // (docs/architecture.md §5.4 rule 5).
  respondWith({
    firstMissedStep: 0,
    feedback: '0 of 2 steps traced correctly. The pathway breaks at step 1.',
    perStep: [
      {
        index: 0,
        outcome: 'wrong',
        outcomeLabel: 'Not this one',
        points: 0,
        prompt: 'Where does the blood arrive?',
        explanation: 'Four pulmonary veins open into it.',
        selectedStructureId: VENTRICLE.id,
        revealedStructureId: ATRIUM.id,
      },
      {
        index: 1,
        outcome: 'wrong',
        outcomeLabel: 'Not this one',
        points: 0,
        prompt: 'Where does it go next?',
        explanation: null,
        selectedStructureId: null,
        revealedStructureId: null,
      },
    ],
  })

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await nextTick()
  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await settle()

  expect(page.text()).toContain('It was the Left Atrium.')
  expect(page.text()).toContain('Four pulmonary veins open into it.')
  expect(page.text()).toContain('The pathway breaks at step 1.')

  page.unmount()
})

it('runs a mission from the keyboard with no canvas involved', async () => {
  respondWith({
    score: 20,
    completed: true,
    correctCount: 2,
    feedback: 'Perfect trace — all 2 steps in the right order.',
    perStep: [],
  })

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })

  for (const structure of [ATRIUM, VENTRICLE]) {
    const button = page.findAll('button').find((candidate) => candidate.text() === structure.name)
    expect(button).toBeDefined()
    await button?.trigger('click')
    await nextTick()
  }

  await settle()

  expect(page.text()).toContain('Mission complete')
  expect(page.text()).toContain('Perfect trace')

  page.unmount()
})

it('lets the student try again without recording anything when the run fails to send', async () => {
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

  const page = mount(Show, { props: { mission: MISSION }, attachTo: document.body })
  const viewer = lastFakeViewer()

  viewer.emit('structure:picked', { structure: ATRIUM, pointer: { x: 0, y: 0 } })
  await nextTick()
  viewer.emit('structure:picked', { structure: VENTRICLE, pointer: { x: 0, y: 0 } })
  await settle()

  expect(page.text()).toContain('nothing has been recorded')
  // Back on the last step rather than at a dead end.
  expect(page.text()).toContain('Where does it go next?')

  page.unmount()
})
