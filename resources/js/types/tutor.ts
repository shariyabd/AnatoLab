/**
 * The tutor API's wire shapes.
 *
 * Mirrors app/Http/Resources/AI/* the same way resources/js/anatomy/types.ts
 * mirrors Handover 03's Resources: change one and change the other in the same
 * commit, or PHP emits a key nothing reads and TypeScript types a key nothing
 * sends (docs/feature-plan.md §7.8).
 *
 * `sources` is always an empty array until Handover 11 implements retrieval.
 * The type is not optional for that reason — the panel renders citations today
 * and simply has none to render, so landing retrieval changes no frontend code.
 */

export type TutorTask = 'ask' | 'explain' | 'hint'

export interface TutorSource {
  readonly id: string
  readonly title: string
  readonly excerpt: string
}

export interface TutorReply {
  readonly answer: string
  readonly followUpQuestions: readonly string[]
  readonly sources: readonly TutorSource[]
  /** Set whenever `sources` is empty: the tutor saying which grounding it used. */
  readonly sourceNote: string | null
  readonly conversationId: number
}

export interface TutorMessage {
  readonly id: number
  readonly role: 'user' | 'assistant'
  readonly content: string
  readonly createdAt: string | null
}

export interface TutorConversation {
  readonly id: number
  readonly title: string
  readonly contextType: string
  readonly contextId: number | null
  readonly updatedAt: string | null
  readonly messages?: readonly TutorMessage[]
}

/** What the panel is currently anchored to. Both may be absent. */
export interface TutorSubject {
  readonly organSlug?: string | null
  readonly organName?: string | null
  readonly structureId?: number | null
  readonly structureName?: string | null
}

/** A turn as the panel holds it, before it has an id from the server. */
export interface TutorTurn {
  readonly key: string
  readonly role: 'user' | 'assistant'
  readonly content: string
  readonly sources?: readonly TutorSource[]
  readonly sourceNote?: string | null
  readonly followUps?: readonly string[]
}
