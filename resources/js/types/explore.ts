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

/**
 * One inert row in the coverage roadmap — handover 16, handover 15 Phase 7.
 *
 * Mirrors `App\Http\Resources\Explore\UpcomingOrganResource`. The three keys
 * `ExploreOrganCard` has that this does not — `modelUrl`, `thumbnailUrl`,
 * `structureCount` — are missing on purpose and are documented on that
 * Resource. The type is the enforcement: a row that cannot be given a
 * `modelUrl` cannot be handed to `prefetchOrgan`, and one that cannot be
 * prefetched is one the panel cannot accidentally make clickable.
 */
export interface UpcomingOrganCard {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly scientificName: string | null
  readonly description: string | null
  readonly accentColor: string
  readonly bodySystem?: { readonly slug: string; readonly name: string }
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

/**
 * One row of the information panel's `KEY FACTS` block.
 *
 * `icon` is a name resolved by `Components/Atelier/Icon.vue`; an unknown name
 * renders nothing rather than a broken glyph.
 */
export interface OrganKeyFact {
  readonly icon: string
  readonly label: string
  readonly value: string
}

/**
 * The editorial copy the information panel is built for — handover 15 Phase 5.
 *
 * **None of this exists in the database yet.** `organs` carries `name`,
 * `scientific_name`, `description`, `accent_color`, the model columns and
 * `status`, and nothing else. Handover 15 is not allowed to migrate a table
 * F03 owns, so this is the typed shape the panel is written against: every
 * section that reads it is `v-if`-guarded and simply does not render until the
 * columns land and `OrganResource` carries them.
 *
 * The request to F03 is four columns on `organs` — `tagline` (string),
 * `key_facts` (JSON array of the shape above), `medical_importance` (text) and
 * `did_you_know` (text) — plus their keys on the resource.
 */
export interface OrganEditorial {
  readonly tagline: string | null
  readonly keyFacts: readonly OrganKeyFact[]
  readonly medicalImportance: string | null
  readonly didYouKnow: string | null
}
