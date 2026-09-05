/**
 * Scripted choreography, because there is nothing to play.
 *
 * `gltf.animations.length` is 0 for every model in the asset set
 * (docs/project-context.md §2.2) — the upstream's `AnimationMixer` support never
 * fires, and its "Play animation" button opens a modal with a static image
 * (§2.5). docs/architecture.md §5.3 therefore defines `triggerAnimation` as a
 * camera and marker choreography driven by a step list, and requires the viewer
 * to emit `capability:degraded` so no UI advertises a clip that does not exist.
 *
 * When per-structure meshes and real `AnimationClip`s land, this becomes the
 * fallback rather than the implementation, with no interface change.
 */

import type { StructureDto, StructureId } from './types'

export interface AnimationStep {
  /** Structure to fly to and highlight. Omit for a pure pause. */
  readonly focus?: StructureId
  /** How long to hold after arriving, in milliseconds. */
  readonly holdMs?: number
  /** Optional caption for the host page; the viewer does not render text. */
  readonly caption?: string
}

/** The one choreography the viewer can build without being told a step list. */
export const GUIDED_TOUR_ID = 'tour'

const TOUR_HOLD_MS = 1_600

/**
 * A tour of every structure on the loaded organ, in the order the API sent them.
 *
 * Order is the server's business — F03 sorts by display order, and re-sorting
 * here would silently override an editorial decision made in the admin tool.
 */
export function buildGuidedTour(structures: readonly StructureDto[]): AnimationStep[] {
  return structures.map((structure) => ({
    focus: structure.id,
    holdMs: TOUR_HOLD_MS,
    caption: structure.name,
  }))
}
