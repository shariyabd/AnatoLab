/**
 * Page-side types for simulations (PRD §14, docs/architecture.md §12).
 *
 * The anatomy DTOs are re-exported from `@/anatomy/types` rather than restated,
 * for the reason `types/explore.ts` documents: that file is the frozen contract
 * the API Resources mirror, and a second declaration of the same shape is a
 * second thing to keep in sync (docs/feature-plan.md §7.8).
 *
 * `VisualDirectives` and `SimulationState` come from `@/anatomy/simulation` for
 * the same reason and a stronger one: `SimulationStep.visualDirectives` below
 * *is* the viewer's `VisualDirectives`, so the object the API sends is handed
 * to `applySimulationState()` with no transform and no mapping table. If the
 * two shapes ever drifted, TypeScript would say so at the call site rather than
 * the viewer quietly ignoring a key.
 *
 * **Nothing here carries the mechanism.** There is no `effects` field, no
 * `thresholds`, no `explanations`, because `App\Http\Resources\Simulations\SimulationResource`
 * emits none. The browser knows what it may *do* and is told what happened; it
 * is never in a position to compute a state itself (invariant 4 applied to
 * simulations — see that Resource).
 */

export type { OrganDto, StructureDto, StructureId } from '@/anatomy/types'
export type { SimulationState, VisualDirectives } from '@/anatomy/simulation'

import type { OrganDto } from '@/anatomy/types'
import type { VisualDirectives } from '@/anatomy/simulation'

/** Where an explanation came from. Shown to the student, not just logged. */
export type ExplanationSource = 'curated' | 'tutor' | 'fallback'

/** One thing the student can do. What it does is deliberately not here. */
export interface SimulationActionDto {
  readonly id: string
  readonly label: string
  readonly description: string | null
}

/** One dial on the readout panel. Fixed for the life of the simulation. */
export interface SimulationReadout {
  readonly key: string
  readonly label: string
  readonly min: number
  readonly max: number
  readonly precision: number
  readonly unit: string | null
}

/** One threshold the run has crossed. */
export interface SimulationOutcome {
  readonly key: string
  readonly label: string
  /** The authored condition, e.g. `output < 0.8`. Shown so the rule is visible. */
  readonly condition: string
  readonly terminal: boolean
}

/**
 * One computed step. Mirrors `SimulationStepResource`.
 *
 * Every number in here was produced in PHP. The page renders it and passes
 * `visualDirectives` to the viewer; it derives none of it.
 */
export interface SimulationStep {
  readonly sequence: number
  readonly actionId: string | null
  readonly actionLabel: string | null
  readonly stateKey: string
  readonly label: string
  readonly isTerminal: boolean
  readonly variables: Readonly<Record<string, number>>
  readonly affectedStructureIds: readonly string[]
  readonly outcomes: readonly SimulationOutcome[]
  readonly visualDirectives: VisualDirectives
  readonly explanation: string | null
  readonly explanationSource: ExplanationSource | null
  /** Educational framing. Present on every step, never dismissible. */
  readonly notice: string
}

/**
 * A runnable simulation.
 *
 * `organ` is a plain `OrganDto` — the same object Explore and the quiz hand the
 * viewer — so the page mounts `ViewerStage` with no transform step.
 */
export interface SimulationDto {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly premise: string
  readonly organ: OrganDto
  readonly actions: readonly SimulationActionDto[]
  readonly readouts: readonly SimulationReadout[]
  readonly notice: string
}

/** One card in the picker. Mirrors `SimulationSummaryResource`. */
export interface SimulationCard {
  readonly id: string
  readonly slug: string
  readonly title: string
  readonly description: string | null
  readonly premise: string
  readonly organ: {
    readonly slug: string
    readonly name: string
    readonly accentColor: string
  }
}

/**
 * One entry in the stored event log, as the page receives it.
 *
 * snake_case, unlike everything else the API sends, and deliberately: this is
 * the `simulation_sessions.events` column verbatim rather than a Resource's
 * output. Shaping it would mean a second definition of the log's shape, and the
 * log is the one thing in this lane that must have exactly one.
 */
export interface SimulationLogEntry {
  readonly sequence: number
  readonly action_id: string | null
  readonly action_label: string | null
  readonly state: Readonly<Record<string, number>>
  readonly state_key: string
  readonly outcomes: readonly string[]
  readonly explanation: string | null
  readonly explanation_source: ExplanationSource | null
}
