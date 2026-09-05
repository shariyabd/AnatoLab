/**
 * Page-side types for the assessment engine.
 *
 * The anatomy DTOs are re-exported from `@/anatomy/types` rather than restated,
 * for the reason `types/explore.ts` documents: that file is the frozen contract
 * the API Resources mirror, and a second declaration of the same shape is a
 * second thing to keep in sync (docs/feature-plan.md §7.8).
 *
 * **Nothing here carries correctness for a question.** `QuizQuestion` has no
 * answer field of any kind, because `App\Http\Resources\Assessment\QuestionResource`
 * emits none (invariant 4). The one type that does — `AttemptVerdict` — is the
 * *response* to an answer the student has already committed to, and it arrives
 * only after the attempt has been graded and written server-side
 * (docs/architecture.md §9).
 */

export type { OrganDto, StructureDto, StructureId } from '@/anatomy/types'

import type { OrganDto } from '@/anatomy/types'

/** Mirrors App\Enums\QuestionType. */
export type QuestionType = 'mcq' | 'spatial' | 'short_answer'

/**
 * One MCQ choice.
 *
 * Three keys, and there is no fourth. `value` is the authored, stable key —
 * useful for analytics and for the seeder's idempotency — and says nothing
 * about which option is right.
 */
export interface QuizOption {
  readonly id: string
  readonly label: string
  readonly value: string
}

/**
 * One question, as the browser is allowed to see it.
 *
 * No explanation: it names the right answer in prose, so it travels with the
 * verdict instead. No rubric, no correct structure, no per-option flag.
 */
export interface QuizQuestion {
  readonly id: string
  readonly type: QuestionType
  readonly question: string
  /** 1 (introductory) to 5 (specialist). */
  readonly difficulty: number
  /** Authored hint, when the question has one. Using it is recorded. */
  readonly hint: string | null
  /** Empty for spatial and short-answer questions. */
  readonly options: readonly QuizOption[]
}

/**
 * A quiz: an organ to look at, and the questions asked about it.
 *
 * `organ` is a plain `OrganDto` — the same object Explore hands the viewer —
 * so the quiz page mounts `ViewerStage` with no transform step.
 */
export interface QuizDto {
  readonly slug: string
  readonly title: string
  readonly questionCount: number
  readonly organ: OrganDto
  readonly questions: readonly QuizQuestion[]
}

/** One card in the quiz picker. Mirrors QuizSummaryResource. */
export interface QuizCard {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly scientificName: string | null
  readonly description: string | null
  readonly accentColor: string
  readonly thumbnailUrl: string | null
  readonly bodySystem?: { readonly slug: string; readonly name: string }
  readonly structureCount?: number
  readonly questionCount: number
}

/**
 * The server's verdict on one recorded attempt.
 *
 * `correctStructureId` is what the viewer flashes green on a miss — the audited
 * behaviour this lane reproduces (docs/project-context.md §2.4). It is null
 * unless the question was spatial.
 *
 * `masteryDelta` is null until Handover 10 implements `RecalculateMastery`;
 * mastery is recomputed in a queued job after this response has been sent
 * (docs/architecture.md §10), so the UI shows nothing rather than a zero it
 * would have to explain.
 */
export interface AttemptVerdict {
  readonly attemptId: string
  readonly isCorrect: boolean
  readonly correctStructureId: string | null
  readonly correctOptionId: string | null
  readonly explanation: string | null
  readonly masteryDelta: number | null
}
