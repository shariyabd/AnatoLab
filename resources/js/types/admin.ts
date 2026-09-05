/**
 * Page-side types for the admin area.
 *
 * Mirrors `App\Http\Resources\Admin\*`.
 *
 * Two of these carry data no student-facing type may ever contain — the answer
 * key on `AdminQuestion` and the target sequence on `AdminMission`. Both fields
 * are **optional**, and that is the contract, not laziness: the Resource omits
 * them when the policy denies the ability, so a page that renders one has to
 * handle its absence rather than assuming the admin area implies the field
 * (invariant 4, docs/architecture.md §14).
 */

import type { OrganDto } from '@/types/explore'

export type { OrganDto, StructureDto, StructureId } from '@/types/explore'

/** What Laravel's paginated Resource collections deliver as a page prop. */
export interface Paginated<T> {
  readonly data: readonly T[]
  readonly links: {
    readonly first: string | null
    readonly last: string | null
    readonly prev: string | null
    readonly next: string | null
  }
  readonly meta: {
    readonly current_page: number
    readonly from: number | null
    readonly to: number | null
    readonly last_page: number
    readonly per_page: number
    readonly total: number
  }
}

export interface OrganOption {
  readonly id: string
  readonly name: string
}

export type OrganStatus = 'draft' | 'published'
export type LessonStatus = 'draft' | 'published'
export type MissionStatus = 'draft' | 'published'
export type QuestionStatus = 'draft' | 'review' | 'published'
export type QuestionType = 'mcq' | 'spatial' | 'short_answer'
export type KnowledgeStatus = 'pending' | 'processing' | 'indexed' | 'failed'

export interface AdminOrgan {
  readonly id: string
  readonly bodySystemId: string
  readonly slug: string
  readonly name: string
  readonly scientificName: string | null
  readonly description: string | null
  readonly modelPath: string
  readonly modelFormat: string
  readonly thumbnailPath: string | null
  readonly accentColor: string
  readonly status: OrganStatus
  readonly statusLabel: string
  readonly bodySystem?: { readonly slug: string; readonly name: string }
  readonly structureCount?: number
  readonly updatedAt: string | null
}

/**
 * Where an anchor came from.
 *
 * A coordinate is only meaningful in the `FIT_SIZE` pivot space of a specific
 * model file (invariant 5), so the authoring UI shows this and warns when
 * `anchorMatchesCurrentModel` is false.
 */
export interface AuthoredAgainst {
  readonly modelPath: string | null
  readonly fitSize: number | null
  readonly authoredAt: string | null
}

export interface AdminStructure {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly taTerm: string | null
  readonly scientificName: string | null
  readonly description: string | null
  readonly function: string | null
  readonly location: string | null
  readonly difficulty: number
  readonly markerColor: string | null
  readonly isPublished: boolean
  readonly anchorPosition: readonly [number, number, number]
  readonly authoredAgainst: AuthoredAgainst | null
  /** False for anchors authored before provenance was recorded. Unknown is not verified. */
  readonly anchorMatchesCurrentModel: boolean
}

export interface AdminLesson {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly objective: string
  readonly difficulty: string
  readonly estimatedMinutes: number
  readonly status: LessonStatus
  readonly statusLabel: string
  readonly content: { readonly steps?: readonly Record<string, unknown>[] }
  readonly stepCount: number
  readonly organ?: { readonly slug: string; readonly name: string; readonly status: OrganStatus }
  readonly updatedAt: string | null
}

export interface AdminQuestionOption {
  readonly id: string
  readonly label: string
  readonly value: string
  readonly isCorrect: boolean
}

export interface AdminQuestion {
  readonly id: string
  readonly type: QuestionType
  readonly typeLabel: string
  readonly question: string
  readonly difficulty: number
  readonly status: QuestionStatus
  readonly statusLabel: string
  readonly generatedByAi: boolean
  readonly explanation: string | null
  readonly organ?: { readonly slug: string; readonly name: string }
  readonly updatedAt: string | null

  /** Present only when `QuestionPolicy::viewAnswerKey` allows it. */
  readonly correctStructureId?: string | null
  readonly correctStructureName?: string | null
  readonly options?: readonly AdminQuestionOption[]
}

export interface AdminMission {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly type: string
  readonly difficulty: number
  readonly status: MissionStatus
  readonly statusLabel: string
  readonly stepCount: number
  readonly organ?: { readonly slug: string; readonly name: string }
  readonly updatedAt: string | null

  /** Present only when `MissionPolicy::viewTargetSequence` allows it. */
  readonly configuration?: {
    readonly steps?: readonly {
      readonly structure_id: string
      readonly prompt: string
      readonly hint?: string | null
      readonly explanation?: string | null
    }[]
  }
}

export interface AdminKnowledgeDocument {
  readonly id: string
  readonly title: string
  readonly source: string
  readonly sourceType: string
  readonly version: number
  readonly status: KnowledgeStatus
  readonly statusLabel: string
  readonly originalFilename: string | null
  readonly mimeType: string | null
  readonly sizeBytes: number | null
  readonly chunkCount?: number
  readonly failureReason: string | null
  readonly updatedAt: string | null
}

/** The authoring canvas's page props. */
export interface AuthoringContext {
  readonly fitSize: number
  readonly currentModel: {
    readonly model_path: string
    readonly model_fingerprint: string | null
    readonly fit_size: number
    readonly authored_at: string
  }
}

export interface AnalyticsOverview {
  readonly since: string
  readonly until: string
  readonly activeLearners: number
  readonly totalEvents: number
  readonly eventCounts: Readonly<Record<string, number>>
  readonly dailyActivity: readonly { readonly date: string; readonly events: number }[]
  readonly masteryByOrgan: readonly {
    readonly organ: string
    readonly mastery: number
    readonly learners: number
  }[]
  readonly questionsAwaitingReview: number
}

export type { OrganDto as AuthoringOrgan }
