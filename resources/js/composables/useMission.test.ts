import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useMission } from './useMission'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import type { MissionDto } from '@/types/missions'

/**
 * The run, without a page around it.
 *
 * The property under test throughout: nothing in this file judges anything.
 * `MissionDto` carries no target, so there is nothing to compare against — the
 * composable records picks, posts them once, and renders whatever the server
 * says (docs/handovers/09-missions.md, "no client-side validation of order").
 */

const ATRIUM = createStructure({ id: '101', slug: 'left-atrium', name: 'Left Atrium' })
const VENTRICLE = createStructure({ id: '102', slug: 'left-ventricle', name: 'Left Ventricle' })

const MISSION: MissionDto = {
  slug: 'trace-the-blood',
  title: 'Trace the Blood',
  description: null,
  type: 'trace_pathway',
  typeLabel: 'Trace the pathway',
  ordered: true,
  difficulty: 2,
  stepCount: 2,
  maxScore: 20,
  scoring: { correct: 10, afterHint: 6, wrong: 0 },
  organ: createOrgan({ structures: [ATRIUM, VENTRICLE] }),
  steps: [
    { index: 0, prompt: 'First?', hint: 'A receiving chamber.' },
    { index: 1, prompt: 'Second?', hint: null },
  ],
}

let clock = 0

function run() {
  const scope = effectScope()
  const mission = scope.run(() => useMission(MISSION, { now: () => clock }))
  if (mission === undefined) throw new Error('The scope produced nothing.')
  return { mission, scope }
}

function respond(data: Record<string, unknown>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({
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
          ...data,
        },
      }),
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

async function settle(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
}

beforeEach(() => {
  clock = 0
})

afterEach(() => {
  vi.unstubAllGlobals()
})

it('records what was picked without judging it', () => {
  const { mission, scope } = run()

  mission.commit(ATRIUM.id)

  expect(mission.picks.value[0]?.structureId).toBe(ATRIUM.id)
  expect(mission.index.value).toBe(1)
  // There is no outcome yet, and no field on the composable that could hold one
  // before the server has answered.
  expect(mission.outcome.value).toBeNull()

  scope.stop()
})

it('times each step from when it appeared, not from when the run started', async () => {
  const fetchMock = respond({})
  const { mission, scope } = run()

  clock = 4_000
  mission.commit(ATRIUM.id)

  clock = 4_600
  mission.commit(VENTRICLE.id)
  await settle()

  const body = JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body)) as {
    steps: { timeSpentMs: number }[]
    durationMs: number
  }

  expect(body.steps[0]?.timeSpentMs).toBe(4_000)
  expect(body.steps[1]?.timeSpentMs).toBe(600)
  expect(body.durationMs).toBe(4_600)

  scope.stop()
})

it('marks a hinted step as hinted, and only that step', async () => {
  const fetchMock = respond({})
  const { mission, scope } = run()

  mission.showHint()
  expect(mission.hintShown.value).toBe(true)
  mission.commit(ATRIUM.id)

  // A new step starts clean; a hint read on step one is not a hint on step two.
  expect(mission.hintShown.value).toBe(false)
  mission.commit(VENTRICLE.id)
  await settle()

  const body = JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body)) as {
    steps: { hintUsed: boolean }[]
  }

  expect(body.steps[0]?.hintUsed).toBe(true)
  expect(body.steps[1]?.hintUsed).toBe(false)

  scope.stop()
})

it('sends a skipped step as a pick of nothing rather than omitting it', async () => {
  // Omitting it would let a student bank a perfect score by submitting only the
  // steps they were sure of. The server scores it as a miss either way, but the
  // step has to be there to be scored.
  const fetchMock = respond({})
  const { mission, scope } = run()

  mission.skip()
  mission.commit(VENTRICLE.id)
  await settle()

  const body = JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body)) as {
    steps: { selectedStructureId: number | null }[]
  }

  expect(body.steps).toHaveLength(2)
  expect(body.steps[0]?.selectedStructureId).toBeNull()

  scope.stop()
})

it('lets a student step back and replace an earlier pick', async () => {
  const fetchMock = respond({})
  const { mission, scope } = run()

  mission.commit(ATRIUM.id)
  mission.back()

  expect(mission.index.value).toBe(0)
  // The earlier pick is still recorded until it is replaced — going back to
  // re-read a prompt should not cost the answer already given.
  expect(mission.picks.value[0]?.structureId).toBe(ATRIUM.id)

  mission.commit(VENTRICLE.id)
  mission.commit(ATRIUM.id)
  await settle()

  const body = JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body)) as {
    steps: { selectedStructureId: number | null }[]
  }

  expect(body.steps[0]?.selectedStructureId).toBe(Number(VENTRICLE.id))

  scope.stop()
})

it('opens the review on the step that broke', async () => {
  respond({ firstMissedStep: 1, feedback: 'The pathway breaks at step 2.' })
  const { mission, scope } = run()

  mission.commit(ATRIUM.id)
  mission.commit(ATRIUM.id)
  await settle()

  expect(mission.phase.value).toBe('reviewing')
  expect(mission.reviewIndex.value).toBe(1)

  scope.stop()
})

it('opens the review on the first step when nothing broke', async () => {
  respond({ completed: true, score: 20, correctCount: 2, firstMissedStep: null })
  const { mission, scope } = run()

  mission.commit(ATRIUM.id)
  mission.commit(VENTRICLE.id)
  await settle()

  expect(mission.reviewIndex.value).toBe(0)

  scope.stop()
})

it('leaves the run replayable when the submission fails', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: false,
      status: 429,
      json: () => Promise.resolve({}),
    }),
  )

  const { mission, scope } = run()

  mission.commit(ATRIUM.id)
  mission.commit(VENTRICLE.id)
  await settle()

  expect(mission.phase.value).toBe('running')
  expect(mission.index.value).toBe(1)
  expect(mission.error.value).toContain('give it a moment')
  // The picks survive, so submitting again does not mean walking it again.
  expect(mission.picks.value[0]?.structureId).toBe(ATRIUM.id)

  scope.stop()
})

it('clears everything on a restart', async () => {
  respond({ completed: true })
  const { mission, scope } = run()

  mission.commit(ATRIUM.id)
  mission.commit(VENTRICLE.id)
  await settle()

  mission.restart()

  expect(mission.phase.value).toBe('running')
  expect(mission.index.value).toBe(0)
  expect(mission.outcome.value).toBeNull()
  expect(mission.reviewIndex.value).toBeNull()
  expect(mission.picks.value).toEqual([null, null])

  scope.stop()
})
