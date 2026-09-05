/**
 * Page-side types for missions.
 *
 * The anatomy DTOs are re-exported from `@/anatomy/types` rather than restated,
 * for the reason `types/quiz.ts` documents: that file is the frozen contract
 * the API Resources mirror, and a second declaration of the same shape is a
 * second thing to keep in sync (docs/feature-plan.md §7.8).
 *
 * **Nothing here carries a target.** `MissionStep` has three fields and none of
 * them names a structure, because
 * `App\Http\Resources\Missions\MissionStepResource` emits three keys and none
 * of them does (invariant 4). There is no type in this file a client-side
 * validator could be written against, which is the point — order is checked on
 * the server or not at all (docs/handovers/09-missions.md, constraints).
 *
 * The one type that carries correctness is `MissionOutcome`, and it is the
 * *response* to a run the student has already committed to: it arrives only
 * after the run has been scored and written server-side, as a
 * `mission_attempts` row plus one `attempts` row per step
 * (docs/architecture.md §9).
 */

export type { OrganDto, StructureDto, StructureId } from '@/anatomy/types'

import type { OrganDto } from '@/anatomy/types'

/** Mirrors App\Enums\MissionType. */
export type MissionType = 'trace_pathway' | 'identify'

/** Mirrors App\Enums\MissionStepOutcome. */
export type MissionStepOutcomeName = 'correct' | 'correct_after_hint' | 'wrong'

/**
 * One step, as the browser is allowed to see it.
 *
 * `index` is its position, which the client needs in order to submit
 * positionally. It reveals nothing: knowing a step is third does not tell you
 * which structure belongs there.
 */
export interface MissionStep {
  readonly index: number
  readonly prompt: string
  /** Authored hint, when the step has one. Using it is recorded and costs points. */
  readonly hint: string | null
}

/** What each outcome is worth. Not an answer key — the points on offer. */
export interface MissionScoring {
  readonly correct: number
  readonly afterHint: number
  readonly wrong: number
}

/**
 * A mission: an organ to work on, and the steps to work through it.
 *
 * `organ` is a plain `OrganDto` — the same object Explore hands the viewer —
 * so the mission page mounts `ViewerStage` with no transform step.
 */
export interface MissionDto {
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly type: MissionType
  readonly typeLabel: string
  /**
   * Whether order counts. Used for wording only. There is nothing on this
   * object it could be used to validate.
   */
  readonly ordered: boolean
  readonly difficulty: number
  readonly stepCount: number
  readonly maxScore: number
  readonly scoring: MissionScoring
  readonly organ: OrganDto
  readonly steps: readonly MissionStep[]
}

/** One card in the mission picker. Mirrors MissionSummaryResource. */
export interface MissionCard {
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly type: MissionType
  readonly typeLabel: string
  readonly difficulty: number
  readonly stepCount: number
  readonly maxScore: number
  readonly organ: {
    readonly id: string
    readonly slug: string
    readonly name: string
    readonly accentColor: string
    readonly thumbnailUrl: string | null
    readonly bodySystem?: { readonly slug: string; readonly name: string }
  }
}

/**
 * The server's verdict on one graded step.
 *
 * `revealedStructureId` is non-null on **at most one step per run** — the first
 * one that went wrong. A mission grades a whole sequence in one request, so
 * naming every target would sell the sequence for a single throwaway run; one
 * reveal keeps the economics of a quiz answer, where a probe costs a recorded
 * attempt (App\Services\Assessment\MissionStepResult).
 */
export interface MissionStepOutcome {
  readonly index: number
  readonly outcome: MissionStepOutcomeName
  readonly outcomeLabel: string
  readonly points: number
  readonly prompt: string
  /** Names the target in prose, which is why it arrives only after grading. */
  readonly explanation: string | null
  readonly selectedStructureId: string | null
  readonly revealedStructureId: string | null
}

/** The verdict on a whole run. */
export interface MissionOutcome {
  readonly missionAttemptId: string
  readonly score: number
  readonly maxScore: number
  readonly completed: boolean
  readonly correctCount: number
  /** Zero-based, and null when nothing was missed. */
  readonly firstMissedStep: number | null
  readonly feedback: string
  readonly perStep: readonly MissionStepOutcome[]
}

/** What the student did on one step, before it has been judged by anyone. */
export interface MissionPick {
  readonly structureId: string | null
  readonly hintUsed: boolean
  readonly timeSpentMs: number
}
