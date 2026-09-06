/**
 * The per-structure half of an organ: which mesh is which structure.
 *
 * Handover 17 Branch C. `AnatomyViewer` already owns the scene, the camera, the
 * loop and the input; this owns the one thing per-structure geometry adds —
 * a two-way map between `StructureDto.modelObjectName` and the `Object3D` it
 * names — plus the two material effects that map makes possible.
 *
 * It is empty for a single-mesh organ, and every method is a no-op in that
 * state. That is the whole compatibility story: the viewer asks this first, gets
 * nothing, and falls through to the dot path it has always had. Mixed-mode
 * organs — some structures with a mesh node, some without — are an expected
 * migration state and fall out of the same code with no branch of their own.
 */

import { Color, Mesh, type Material, type Object3D } from 'three'
import type { StructureDto, StructureId } from './types'

/**
 * How far a hovered mesh moves toward the organ accent.
 *
 * Deliberately below the 0.45 `#setTint` uses for a whole-organ tint: hover is a
 * highlight, not a selection, and it has to stay legible next to the selected
 * structure's marker without competing with it.
 */
const HOVER_TINT = 0.22

/** Emissive lift on hover — enough to read on a dark region of the map. */
const HOVER_EMISSIVE = 0.18

/** What a hovered mesh's material was before we swapped it. */
interface HoverSwap {
  readonly mesh: Mesh
  readonly original: Material | Material[]
  readonly clone: Material
}

export class StructureMeshes {
  readonly #byStructure = new Map<StructureId, Object3D>()
  readonly #byObject = new Map<Object3D, StructureId>()

  #hover: HoverSwap | null = null
  #isolatedId: StructureId | null = null

  /**
   * Binds structures to the nodes they name.
   *
   * A structure whose `modelObjectName` is null, or names a node this model does
   * not contain, is simply absent from the map — it keeps its dot and nothing
   * here applies to it. That is not an error worth throwing over at runtime:
   * `php artisan anatomy:verify-mesh-identity` is where an orphaned name is
   * meant to be caught, before a student ever loads the organ.
   */
  attach(structures: readonly StructureDto[], root: Object3D): void {
    this.clear()

    for (const structure of structures) {
      const name = structure.modelObjectName
      if (name === null || name === '') continue

      const object = root.getObjectByName(name)
      if (object === undefined) continue

      this.#byStructure.set(structure.id, object)
      this.#byObject.set(object, structure.id)
    }
  }

  /** True once at least one structure resolved to real geometry. */
  get isPerStructure(): boolean {
    return this.#byStructure.size > 0
  }

  get size(): number {
    return this.#byStructure.size
  }

  /** The objects a raycast should test. Empty for a single-mesh organ. */
  objects(): Object3D[] {
    return [...this.#byStructure.keys()].map((id) => this.#byStructure.get(id) as Object3D)
  }

  /**
   * Which structure a raycast hit belongs to.
   *
   * Walks up the parents, because a hit reports the `Mesh` it struck and an
   * export may nest that mesh under the node carrying the structure's name.
   */
  resolve(object: Object3D | null): StructureId | null {
    let current: Object3D | null = object

    while (current !== null) {
      const id = this.#byObject.get(current)
      if (id !== undefined) return id

      current = current.parent
    }

    return null
  }

  has(id: StructureId): boolean {
    return this.#byStructure.has(id)
  }

  /**
   * Emissive lift and a tint toward the organ accent on one mesh.
   *
   * The material is **cloned**, never mutated in place. Handover 17 Branch A
   * exports one shared material per organ by default, so tinting the hovered
   * mesh's own material would tint every structure at once — the bug that makes
   * a hover highlight look like a whole-organ flash.
   *
   * Returns true when something changed, so the caller knows whether the frame
   * needs redrawing rather than requesting one on every pointer move.
   */
  setHovered(id: StructureId | null, accentColor: string): boolean {
    const target = id === null ? null : (this.#byStructure.get(id) ?? null)

    if (this.#hover !== null && (target === null || !this.#isWithin(this.#hover.mesh, target))) {
      this.#clearHover()

      if (target === null) return true
    }

    if (target === null || this.#hover !== null) return false

    const mesh = this.#firstMesh(target)
    if (mesh === null || Array.isArray(mesh.material)) return false

    const clone = mesh.material.clone()
    this.#applyHoverTint(clone, accentColor)

    this.#hover = { mesh, original: mesh.material, clone }
    mesh.material = clone

    return true
  }

  /**
   * Isolation, for real: every other structure's mesh is hidden.
   *
   * This is the behaviour `isolateStructure` always promised and could not keep
   * against a single mesh, where there was no second object to hide
   * (docs/project-context.md §2.2). Geometry belonging to no structure is left
   * alone — in a per-structure export that is the organ root and the
   * normalisation pivot, and hiding either would take the whole organ with it.
   */
  setIsolated(id: StructureId | null): void {
    this.#isolatedId = id

    for (const [structureId, object] of this.#byStructure) {
      object.visible = id === null || structureId === id
    }
  }

  get isolatedId(): StructureId | null {
    return this.#isolatedId
  }

  /**
   * Drops every reference and disposes what this class created.
   *
   * Only the hover clone: every other material belongs to the loaded model and
   * is the asset manager's to dispose (docs/architecture.md §5.4 rule 6).
   */
  clear(): void {
    this.#clearHover()
    this.setIsolated(null)
    this.#byStructure.clear()
    this.#byObject.clear()
    this.#isolatedId = null
  }

  #clearHover(): void {
    const hover = this.#hover
    if (hover === null) return

    hover.mesh.material = hover.original
    hover.clone.dispose()
    this.#hover = null
  }

  #applyHoverTint(material: Material, accentColor: string): void {
    const tinted = material as Material & { color?: Color; emissive?: Color }
    const accent = new Color(accentColor)

    tinted.color?.lerp(accent, HOVER_TINT)
    tinted.emissive?.copy(accent).multiplyScalar(HOVER_EMISSIVE)
    material.needsUpdate = true
  }

  /** The first mesh at or under a node — a structure node may be a wrapper. */
  #firstMesh(object: Object3D): Mesh | null {
    if (object instanceof Mesh) return object

    let found: Mesh | null = null
    object.traverse((child) => {
      if (found === null && child instanceof Mesh) found = child
    })

    return found
  }

  #isWithin(mesh: Mesh, root: Object3D): boolean {
    let current: Object3D | null = mesh

    while (current !== null) {
      if (current === root) return true
      current = current.parent
    }

    return false
  }
}
