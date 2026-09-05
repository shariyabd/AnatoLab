/**
 * What the viewer accepts from a simulation step (docs/architecture.md §12).
 *
 * Not in `types.ts` because that file is frozen and mirrors Handover 03's
 * anatomy Resources; this shape belongs to Handover 12's simulation endpoint.
 * It lives here so F12 has something to code against without reopening F04
 * (docs/handovers/parallel-execution-plan.md D6).
 *
 * **The vocabulary is closed.** Five directives, chosen because a single-mesh
 * model can actually perform them (docs/project-context.md §2.2). The viewer
 * ignores anything else rather than half-honouring it; a directive it cannot
 * perform is worse than no directive, because the UI then labels a control with
 * something it does not do (docs/architecture.md §5.3).
 *
 * Keys are camelCase, matching the contract convention in `types.ts`: the API
 * Resource converts `pulse_rate` → `pulseRate` and `cross_section` →
 * `crossSection` on the way out, and nothing on this side transforms keys at
 * runtime.
 */

import type { StructureId } from './types'

export interface CrossSectionDirective {
  readonly enabled: boolean
  readonly axis?: 'x' | 'y' | 'z'
  /** Plane offset in FIT_SIZE pivot space. */
  readonly offset?: number
}

export interface VisualDirectives {
  /** Transient emphasis on a structure's marker. Null clears it. */
  readonly highlight?: StructureId | null
  /** CSS colour blended into the organ's material. Null clears it. */
  readonly tint?: string | null
  /** Marker pulse speed multiplier. 1 is the resting rate; 0 stops it. */
  readonly pulseRate?: number | null
  /** Fly the camera to this structure and select it. */
  readonly focus?: StructureId | null
  readonly crossSection?: CrossSectionDirective | boolean | null
}

export interface SimulationState {
  /** The simulation's numeric variables, computed in PHP and reproducible. */
  readonly state: Readonly<Record<string, number>>
  readonly visualDirectives: VisualDirectives
  /** Educational prose from curated content or the tutor. Never rendered by the viewer. */
  readonly explanation?: string | null
}

/** The five permitted keys, exported so a test can assert nothing else is honoured. */
export const VISUAL_DIRECTIVE_KEYS = [
  'highlight',
  'tint',
  'pulseRate',
  'focus',
  'crossSection',
] as const
