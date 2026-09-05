/**
 * Page-side types for lessons.
 *
 * Mirrors `App\Http\Resources\Lessons\*`. The organ is re-exported from
 * `@/types/explore` rather than restated: a lesson's exploration steps hand
 * `lesson.organ` straight to `ViewerStage`, so it must be the same `OrganDto`
 * the frozen contract in `resources/js/anatomy/types.ts` describes
 * (docs/feature-plan.md §7.8).
 */

import type { OrganDto } from '@/types/explore'

export type { OrganDto, StructureDto, StructureId } from '@/types/explore'

/** Mirrors `App\Enums\LessonStepType`. */
export type LessonStepType =
  'objective' | 'exploration' | 'explanation' | 'activity' | 'knowledge_check' | 'reflection'

/** Mirrors `App\Enums\DifficultyPreference`, which lessons are graded on. */
export type LessonDifficulty = 'beginner' | 'intermediate' | 'advanced'

/** Mirrors `App\Enums\LessonProgressStatus`. A student with no row has `null`. */
export type LessonProgressStatus = 'in_progress' | 'completed'

export interface LessonProgressDto {
  readonly status: LessonProgressStatus
  readonly progressPercent: number
  readonly completedAt: string | null
}

// ------------------------------------------------------------ step payloads

export interface ObjectivePayload {
  readonly body: string
  readonly outcomes: readonly string[]
}

/** `structures` holds structure *slugs*, resolved against `lesson.organ`. */
export interface ExplorationPayload {
  readonly instruction: string
  readonly structures: readonly string[]
}

export interface ExplanationPayload {
  readonly blocks: readonly { readonly heading: string | null; readonly body: string }[]
}

/** Same shape as exploration, but the order is the point. */
export interface ActivityPayload {
  readonly instruction: string
  readonly structures: readonly string[]
}

/**
 * A slot for one of F07's questions.
 *
 * Carries a prompt and a stable reference and never an answer — this lane owns
 * the step's placement in the sequence, not the question (invariant 4,
 * docs/handovers/06-lessons.md).
 */
export interface KnowledgeCheckPayload {
  readonly prompt: string
  readonly reference: string
}

export interface ReflectionPayload {
  readonly prompt: string
  readonly placeholder: string
}

interface LessonStepBase<TType extends LessonStepType, TPayload> {
  /** Authored server-side, and what `POST /lessons/{lesson}/progress` sends back. */
  readonly index: number
  readonly type: TType
  /** The step type's own display name, from the PHP enum. */
  readonly label: string
  /** The author's title for this step. */
  readonly title: string
  readonly payload: TPayload
}

/**
 * A discriminated union rather than a bag of optional keys.
 *
 * `LessonResource` drops any step whose type it does not recognise, so a step
 * that reaches the client is always one of these six.
 */
export type LessonStep =
  | LessonStepBase<'objective', ObjectivePayload>
  | LessonStepBase<'exploration', ExplorationPayload>
  | LessonStepBase<'explanation', ExplanationPayload>
  | LessonStepBase<'activity', ActivityPayload>
  | LessonStepBase<'knowledge_check', KnowledgeCheckPayload>
  | LessonStepBase<'reflection', ReflectionPayload>

// ------------------------------------------------------------------ lessons

export interface LessonOrganSummary {
  readonly slug: string
  readonly name: string
  readonly accentColor: string
  readonly bodySystem: { readonly slug: string; readonly name: string } | null
}

/** One card in the library. Mirrors `LessonSummaryResource` — no `steps`. */
export interface LessonCard {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly objective: string
  readonly difficulty: LessonDifficulty
  readonly estimatedMinutes: number
  readonly stepCount: number
  readonly organ?: LessonOrganSummary
  readonly progress: LessonProgressDto | null
}

/** One lesson in full. Mirrors `LessonResource`. */
export interface LessonDto {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly objective: string
  readonly difficulty: LessonDifficulty
  readonly estimatedMinutes: number
  readonly stepCount: number
  readonly steps: readonly LessonStep[]
  readonly organ: OrganDto
  readonly progress: LessonProgressDto | null
}

/** The library's active filters, echoed back by the controller. */
export interface LessonFilters {
  readonly organ: string | null
  readonly system: string | null
  readonly difficulty: LessonDifficulty | null
}
