import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import Show from './Show.vue'
import { createOrgan, createStructure } from '@/anatomy/testing/fixtures'
import { fakeViewers, lastFakeViewer, resetFakeViewers } from '@/testing/fakeAnatomyViewer'
import type { LessonDto, LessonProgressDto } from '@/types/lessons'

/**
 * The lesson page's contract.
 *
 * Three things here are the reason this file exists: the viewer is built once
 * for the whole lesson and never remounted between steps, it is disposed when
 * the page goes away, and progress is reported as a step index the server
 * turns into a percentage.
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

const ORGAN = createOrgan({
  structures: [
    createStructure({ id: 'str_lv', slug: 'left-ventricle', name: 'Left ventricle' }),
    createStructure({ id: 'str_aorta', slug: 'aorta', name: 'Aorta' }),
  ],
})

function createLesson(progress: LessonProgressDto | null = null): LessonDto {
  return {
    id: 'les_1',
    slug: 'blood-circulation',
    title: 'Blood Circulation Through the Heart',
    description: null,
    objective: 'Understand how blood moves through the heart.',
    difficulty: 'beginner',
    estimatedMinutes: 15,
    stepCount: 4,
    organ: ORGAN,
    progress,
    steps: [
      {
        index: 0,
        type: 'objective',
        label: 'Objective',
        title: 'What you will learn',
        payload: { body: 'Twice, not once.', outcomes: [] },
      },
      {
        index: 1,
        type: 'exploration',
        label: '3D exploration',
        title: 'Find the chambers',
        payload: { instruction: 'Rotate the heart.', structures: ['left-ventricle', 'aorta'] },
      },
      {
        index: 2,
        type: 'explanation',
        label: 'Explanation',
        title: 'Two circuits',
        payload: { blocks: [{ heading: null, body: 'One to the lungs, one to the body.' }] },
      },
      {
        index: 3,
        type: 'reflection',
        label: 'Reflection',
        title: 'Think it through',
        payload: { prompt: 'What if the septum leaked?', placeholder: 'A sentence.' },
      },
    ],
  }
}

let fetchMock: ReturnType<typeof vi.fn>

function mountPage(progress: LessonProgressDto | null = null) {
  return mount(Show, { props: { lesson: createLesson(progress) } })
}

/** A button by the text it contains, with a real message when there is none. */
function buttonContaining(page: ReturnType<typeof mountPage>, text: string) {
  const found = page.findAll('button').find((candidate) => candidate.text().includes(text))
  if (found === undefined) throw new Error(`No button containing "${text}".`)
  return found
}

/** The nav buttons, by their visible label. */
function button(page: ReturnType<typeof mountPage>, label: string) {
  const found = page.findAll('button').find((candidate) => candidate.text().trim() === label)
  if (found === undefined) throw new Error(`No button labelled "${label}".`)
  return found
}

beforeEach(() => {
  resetFakeViewers()

  fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    json: () =>
      Promise.resolve({ data: { status: 'in_progress', progressPercent: 50, completedAt: null } }),
  })

  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the viewer mount', () => {
  it('builds exactly one viewer for the whole lesson', () => {
    mountPage()

    expect(fakeViewers).toHaveLength(1)
  })

  it('does not rebuild the viewer when the student moves between steps', async () => {
    // A step that owned its own viewer, or a `v-if` on the step type, would
    // drop the WebGL context and re-decode the model on every "next"
    // (docs/architecture.md §5.4 rules 6 and 7).
    const page = mountPage()

    await button(page, 'Next').trigger('click')
    await button(page, 'Next').trigger('click')

    expect(fakeViewers).toHaveLength(1)
    expect(lastFakeViewer().disposeCount).toBe(0)
  })

  it('disposes the viewer when the page goes away', () => {
    const page = mountPage()

    page.unmount()

    expect(lastFakeViewer().disposeCount).toBe(1)
  })

  it('relays a structure chosen in a step to the viewer', async () => {
    const page = mountPage()

    await button(page, 'Next').trigger('click')
    await buttonContaining(page, 'Left ventricle').trigger('click')

    expect(lastFakeViewer().calls).toContain('focusStructure:str_lv')
  })

  it('clears the highlight when the step changes', async () => {
    // The marker belongs to the step that asked for it; leaving it lit makes
    // the next step look wrong.
    const page = mountPage()

    await button(page, 'Next').trigger('click')

    expect(lastFakeViewer().calls).toContain('highlightStructure:null')
  })
})

describe('the lesson reads without 3D', () => {
  it('keeps every step readable when the viewer reports no WebGL', async () => {
    const page = mountPage()
    lastFakeViewer().isAvailable = false
    await nextTick()

    expect(page.text()).toContain('What you will learn')

    await button(page, 'Next').trigger('click')

    // The structure list is a real list of buttons whether or not there is a
    // canvas (docs/architecture.md §5.4 rule 5).
    expect(page.text()).toContain('Left ventricle')
    expect(page.text()).toContain('Aorta')
  })
})

describe('progress', () => {
  it('records the opening step on mount, so an opened lesson leaves an honest row', () => {
    mountPage()

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/lessons/blood-circulation/progress',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ stepIndex: 0 }) }),
    )
  })

  it('sends the step index and never a percentage', async () => {
    // The server owns the percentage (LessonService::recordStepProgress); a
    // client that could send one could claim 100% on step one.
    const page = mountPage()
    fetchMock.mockClear()

    await button(page, 'Next').trigger('click')

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const body = String(init.body)

    expect(JSON.parse(body)).toEqual({ stepIndex: 1 })
    expect(body).not.toContain('progressPercent')
  })

  it('does not re-post a step the student has already reached', async () => {
    const page = mountPage()

    await button(page, 'Next').trigger('click')
    fetchMock.mockClear()

    await button(page, 'Previous').trigger('click')
    await button(page, 'Next').trigger('click')

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('resumes a part-finished lesson at the furthest step reached', () => {
    // Acceptance criterion 2: progress persists and survives a refresh.
    const page = mountPage({ status: 'in_progress', progressPercent: 75, completedAt: null })

    expect(page.text()).toContain('Step 3 of 4')
  })

  it('opens a completed lesson at the beginning rather than at its end', () => {
    const page = mountPage({
      status: 'completed',
      progressPercent: 100,
      completedAt: '2026-09-06T09:00:00+00:00',
    })

    expect(page.text()).toContain('Step 1 of 4')
  })

  it('posts to complete on the last step and reports the lesson finished', async () => {
    // Per endpoint, not blanket: only /complete returns a completed row. A
    // /progress call that answered "completed" would mean the page reported
    // the lesson finished before the student had finished it.
    fetchMock.mockImplementation((url: string) => ({
      ok: true,
      json: () =>
        Promise.resolve({
          data: url.endsWith('/complete')
            ? { status: 'completed', progressPercent: 100, completedAt: 'now' }
            : { status: 'in_progress', progressPercent: 25, completedAt: null },
        }),
    }))

    const page = mountPage()

    await button(page, 'Next').trigger('click')
    await button(page, 'Next').trigger('click')
    await button(page, 'Next').trigger('click')
    await button(page, 'Mark as complete').trigger('click')
    await nextTick()

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/lessons/blood-circulation/complete',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(page.text()).toContain('Finished')
  })

  it('keeps the lesson usable when a save fails', async () => {
    // A student mid-lesson can do nothing about a failed save, so it is a
    // quiet note rather than an error surface (docs/engineering.md §11 item 7).
    fetchMock.mockRejectedValue(new Error('offline'))

    const page = mountPage()
    await nextTick()
    await nextTick()

    await button(page, 'Next').trigger('click')

    expect(page.text()).toContain('Find the chambers')
    expect(page.text()).toContain('could not be saved')
  })
})
