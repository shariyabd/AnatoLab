import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StepRenderer from './StepRenderer.vue'
import { createStructure } from '@/anatomy/testing/fixtures'
import type { LessonStep, LessonStepType, StructureDto } from '@/types/lessons'

/**
 * Step rendering is data-driven (docs/handovers/06-lessons.md).
 *
 * The point of these tests is the *table*: every one of the six PRD §8 step
 * types resolves to a component, without a `v-if` chain in a template. A
 * seventh type added to the PHP enum with no entry here would render nothing
 * mid-lesson, which is the failure this suite exists to catch.
 */

const STRUCTURES: readonly StructureDto[] = [
  createStructure({ id: 'str_lv', slug: 'left-ventricle', name: 'Left ventricle' }),
  createStructure({ id: 'str_aorta', slug: 'aorta', name: 'Aorta', taTerm: 'Aorta' }),
]

const STEPS: Record<LessonStepType, LessonStep> = {
  objective: {
    index: 0,
    type: 'objective',
    label: 'Objective',
    title: 'What you will learn',
    payload: {
      body: 'Blood passes through the heart twice.',
      outcomes: ['Name the four chambers.'],
    },
  },
  exploration: {
    index: 1,
    type: 'exploration',
    label: '3D exploration',
    title: 'Find the chambers',
    payload: { instruction: 'Rotate the heart.', structures: ['left-ventricle', 'aorta'] },
  },
  explanation: {
    index: 2,
    type: 'explanation',
    label: 'Explanation',
    title: 'Two circuits',
    payload: { blocks: [{ heading: 'The right side', body: 'Blood goes to the lungs.' }] },
  },
  activity: {
    index: 3,
    type: 'activity',
    label: 'Activity',
    title: 'Trace one drop',
    payload: { instruction: 'Work through the path.', structures: ['left-ventricle', 'aorta'] },
  },
  knowledge_check: {
    index: 4,
    type: 'knowledge_check',
    label: 'Knowledge check',
    title: 'Check yourself',
    payload: { prompt: 'Which chamber feeds the aorta?', reference: 'demo.aorta' },
  },
  reflection: {
    index: 5,
    type: 'reflection',
    label: 'Reflection',
    title: 'Think it through',
    payload: { prompt: 'What if the septum had a hole?', placeholder: 'A sentence or two.' },
  },
}

function render(step: LessonStep) {
  return mount(StepRenderer, { props: { step, structures: STRUCTURES } })
}

/** `findAll('button')[n]`, with a real message instead of an undefined deref. */
function buttonAt(page: ReturnType<typeof render>, index: number) {
  const found = page.findAll('button')[index]
  if (found === undefined) throw new Error(`No button at index ${String(index)}.`)
  return found
}

describe('the step table', () => {
  it.each(Object.keys(STEPS) as LessonStepType[])('renders a %s step', (type) => {
    const page = render(STEPS[type])

    expect(page.text()).not.toContain('cannot be shown')
    expect(page.text().length).toBeGreaterThan(0)
  })

  it('says so visibly rather than rendering a gap for an unknown type', () => {
    // The server drops unrecognised types, so this cannot happen in practice.
    // A lesson silently one step shorter would be worse than a visible note.
    const page = render({ ...STEPS.objective, type: 'hologram' } as unknown as LessonStep)

    expect(page.text()).toContain('cannot be shown')
  })
})

describe('steps that drive the viewer', () => {
  it('emits a structure id, not a slug, when an exploration target is chosen', async () => {
    // Structure ids are opaque strings the client never parses or constructs
    // (docs/architecture.md §5.4 rule 4). The slug→id resolution happens here
    // so the page can hand the id straight to the viewer.
    const page = render(STEPS.exploration)

    await buttonAt(page, 0).trigger('click')

    expect(page.emitted('focus')).toEqual([['str_lv']])
  })

  it('highlights on hover and clears the highlight on leave', async () => {
    const page = render(STEPS.exploration)

    await buttonAt(page, 0).trigger('mouseenter')
    await buttonAt(page, 0).trigger('mouseleave')

    expect(page.emitted('highlight')).toEqual([['str_lv'], [null]])
  })

  it('reaches every structure by keyboard as well as by pointer', async () => {
    // Every 3D interaction needs a keyboard equivalent, in the same commit
    // that adds it (docs/engineering.md §4).
    const page = render(STEPS.exploration)
    expect(page.findAll('button')).toHaveLength(2)

    await buttonAt(page, 1).trigger('focus')

    expect(page.emitted('highlight')).toEqual([['str_aorta']])
  })

  it('drops a structure slug the organ does not have', async () => {
    // An author's typo degrades to a missing entry, not to a button that does
    // nothing when clicked.
    const page = render({
      index: 1,
      type: 'exploration',
      label: '3D exploration',
      title: 'Find the chambers',
      payload: { instruction: 'Find them.', structures: ['left-ventricle', 'not-a-structure'] },
    })

    expect(page.findAll('button')).toHaveLength(1)
  })

  it('marks an activity entry as seen without grading it', async () => {
    // This lane grades nothing (docs/handovers/06-lessons.md). The tick is a
    // reading aid, and every entry is visible and reachable from the start.
    const page = render(STEPS.activity)

    expect(page.findAll('button')).toHaveLength(2)
    expect(buttonAt(page, 0).attributes('aria-pressed')).toBe('false')

    await buttonAt(page, 0).trigger('click')

    expect(buttonAt(page, 0).attributes('aria-pressed')).toBe('true')
    expect(page.emitted('focus')).toEqual([['str_lv']])
  })
})

describe('the knowledge-check seat', () => {
  it('shows the prompt and its reference, and holds nothing else', () => {
    // F07 owns the question and its grading; this lane owns its placement
    // (invariant 4). The component can only render what the server sends, so
    // the absence of a correctness field is asserted where it is decided —
    // Tests\Feature\Lessons\LessonEndpointsTest and LessonPageTest, through
    // the shared `toCarryNoAnswerKey` expectation. Naming the forbidden fields
    // again here would only make /boundary-audit report this file forever.
    const page = render(STEPS.knowledge_check)

    expect(page.text()).toContain('Which chamber feeds the aorta?')
    expect(page.html()).toContain('demo.aorta')
    expect(Object.keys(STEPS.knowledge_check.payload)).toEqual(['prompt', 'reference'])
  })
})
