/**
 * Page-side types for Explore.
 *
 * The anatomy DTOs and viewer enums are re-exported from `@/anatomy/types`
 * rather than restated here. That file is the frozen contract the API
 * Resources mirror (docs/feature-plan.md §7.8); a second declaration of the
 * same shape is a second thing to keep in sync.
 *
 * Importing from `@/anatomy/` is otherwise forbidden (invariant 3), and this
 * is the one narrow exception: `types.ts` is declarations only — no runtime
 * code, no imports of its own — so a `import type` from it is erased at build
 * and creates no dependency on the viewer library. Anything with behaviour
 * still comes through `useAnatomyViewer`, and only through it.
 */

export type {
  CapabilityDegraded,
  OrganDto,
  StructureDto,
  StructureId,
  ViewerLayer,
} from '@/anatomy/types'

import type { StructureDto } from '@/anatomy/types'

/**
 * One card in the organ library.
 *
 * Mirrors `App\Http\Resources\Explore\ExploreOrganResource` — deliberately not
 * an `OrganDto`: a card draws a thumbnail and a name, and shipping every
 * structure of every organ to do that is what `OrganSummaryResource` exists to
 * avoid. `modelUrl` is here only so hovering a card can warm the GLB.
 */
export interface ExploreOrganCard {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly scientificName: string | null
  readonly description: string | null
  readonly accentColor: string
  readonly thumbnailUrl: string | null
  readonly modelUrl: string
  readonly bodySystem?: { readonly slug: string; readonly name: string }
  readonly structureCount?: number
}

/** One entry in a structure's `relatedStructures`. */
export interface RelatedStructure {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly taTerm: string | null
  readonly relationType: string | null
  readonly relationLabel: string | null
}

/**
 * The info panel's payload for one structure.
 *
 * Mirrors `App\Http\Resources\Anatomy\StructureDetailResource`: a superset of
 * `StructureDto`, so the same object can be handed straight to the viewer
 * without a transform.
 */
export interface StructureDetail extends StructureDto {
  readonly location: string | null
  readonly metadata: Readonly<Record<string, unknown>>
  readonly organ: {
    readonly id: string
    readonly slug: string
    readonly name: string
    readonly accentColor: string
  }
  readonly relatedStructures?: readonly RelatedStructure[]
}
