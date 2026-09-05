/**
 * Data contract between the Laravel API and the 3D viewer.
 *
 * FROZEN after Handover 01 (docs/feature-plan.md §7.5).
 *
 * This file is one half of a two-way contract: it MIRRORS the API Resources
 * that Handover 03 owns. Changing one without the other is a silent runtime
 * break — PHP will happily emit a key Vue never reads, and TypeScript will
 * happily type a key PHP never sends. Change both in one commit, or neither
 * (docs/feature-plan.md §7.8).
 *
 * Field names are camelCase. The API Resources are responsible for the
 * conversion from the database's snake_case; nothing on this side transforms
 * keys at runtime.
 *
 * Types only — no implementation, no imports. This file is consumed by the
 * framework-free viewer, which may not import Vue, Inertia, or any HTTP
 * client (invariant 3).
 */

/**
 * An opaque structure identifier.
 *
 * The viewer never parses, derives, compares by substring, or constructs one.
 * It arrives from the API and goes back unchanged (docs/architecture.md §5.4
 * rule 4). Aliased rather than left as `string` so that intent is visible at
 * every call site.
 */
export type StructureId = string

/** A point in FIT_SIZE-normalised pivot space. See constants.ts. */
export type Vec3 = readonly [x: number, y: number, z: number]

/**
 * One labelled anatomical structure within an organ.
 *
 * Carries no correctness data of any kind. Quiz targets, correct answers, and
 * mission sequences are stripped by the API Resource and validated server-side
 * (invariant 4, docs/architecture.md §5.4 rule 2).
 */
export interface StructureDto {
  /** Opaque; see StructureId. */
  readonly id: StructureId
  readonly slug: string
  readonly name: string
  /**
   * Terminologia Anatomica term — the canonical, language-independent identity
   * of this structure. Present because the audited asset set identified
   * structures this way (docs/project-context.md §2.2).
   */
  readonly taTerm: string | null
  readonly scientificName: string | null
  readonly description: string | null
  /** What it does. Rendered in the callout; never used for scoring. */
  readonly function: string | null
  /** 1 (introductory) to 5 (specialist). */
  readonly difficulty: number
  /**
   * Where the hotspot marker sits, in FIT_SIZE pivot space.
   *
   * This is the working selection mechanism. The audited models are a single
   * mesh with no named sub-objects, so there is no per-structure geometry to
   * raycast against (docs/project-context.md §2.2).
   */
  readonly anchorPosition: Vec3
  /**
   * Name of the mesh inside the GLB, when one exists.
   *
   * Always null today and reserved for the per-structure-mesh upgrade. Present
   * in the contract now so that upgrade needs no schema, API, or type change
   * (docs/architecture.md §5.3).
   */
  readonly modelObjectName: string | null
  /** CSS colour for the marker; falls back to the organ accent when null. */
  readonly markerColor: string | null
}

/** An organ, its model, and everything labelled on it. */
export interface OrganDto {
  readonly id: string
  readonly slug: string
  readonly name: string
  readonly scientificName: string | null
  readonly description: string | null
  /**
   * Fully-resolved URL to the GLB. The viewer loads this and nothing else — it
   * never constructs a URL and never fetches JSON (docs/architecture.md §5.4
   * rule 3).
   */
  readonly modelUrl: string
  readonly modelFormat: 'glb' | 'gltf'
  /** CSS colour driving markers, highlights, and UI accents for this organ. */
  readonly accentColor: string
  readonly structures: readonly StructureDto[]
}

/** Which interaction rules the viewer is currently operating under. */
export type ViewerMode = 'explore' | 'quiz' | 'mission' | 'author'

/** Rendering treatment. See docs/architecture.md §5.3 for honest semantics. */
export type ViewerLayer = 'solid' | 'wireframe' | 'section'

/**
 * Why a request could not be fully honoured.
 *
 * Emitted so the UI can avoid advertising a control that will not do what its
 * label says — the specific failure the audit found upstream, where "Isolate"
 * faded only the plinth (docs/architecture.md §5.3).
 */
export interface CapabilityDegraded {
  readonly capability: 'isolate' | 'layers' | 'animation' | 'crossSection'
  readonly reason: string
}

/** Why the 3D layer is unusable, so the page can fall back to text. */
export type ViewerFailureReason =
  'webgl-unavailable' | 'model-not-found' | 'decode-failed' | 'network'

/**
 * Everything the viewer emits, as an event-name → payload map.
 *
 * A map rather than a union so `on()` can be typed such that the handler's
 * parameter is inferred from the event name, replacing the audited callback
 * bag (docs/architecture.md §5.2).
 */
export interface ViewerEventMap {
  /** Selection changed, from any cause. null means deselected. */
  'structure:selected': { structure: StructureDto | null }
  /** The user clicked a marker. Distinct from selected: quiz mode consumes
   *  the click without changing selection. */
  'structure:picked': { structure: StructureDto; pointer: { x: number; y: number } }
  'structure:hovered': { structure: StructureDto | null }
  'organ:loaded': { organ: OrganDto; triangles: number; loadMs: number }
  'load:progress': { loaded: number; total: number }
  /** Every load path has a failure path (docs/architecture.md §5.4 rule 5). */
  'load:failed': { reason: ViewerFailureReason; detail: string }
  /** Authoring tool: the admin clicked a point on the surface. */
  'author:point': { position: Vec3 }
  'webgl:unavailable': { detail: string }
  /** A method ran with reduced semantics rather than failing silently. */
  'capability:degraded': CapabilityDegraded
}

export type ViewerEventName = keyof ViewerEventMap

/** Payload for a given event name. */
export type ViewerEvent<E extends ViewerEventName = ViewerEventName> = ViewerEventMap[E]

/** Returned by `on()`; call it to detach. Disposal is mandatory (rule 6). */
export type Unsubscribe = () => void

/** Host-supplied hints. */
export interface ViewerOptions {
  /** Honour prefers-reduced-motion: no auto-rotate, no fly-to easing. */
  readonly reducedMotion?: boolean
  /** Device hint: lower pixel ratio, cheaper lighting. */
  readonly lowPower?: boolean
  readonly initialMode?: ViewerMode
}
