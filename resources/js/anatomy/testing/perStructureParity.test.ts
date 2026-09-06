import { describe, expect, it } from 'vitest'
import { Mesh, type Object3D } from 'three'
import {
  PER_STRUCTURE_FIXTURE,
  createPerStructureFixtureModel,
  createPerStructureOrgan,
  withoutMeshIdentity,
} from './fixtures'

/**
 * Holds the two halves of the per-structure fixture to each other.
 *
 * `scripts/make-structure-fixture.mjs` writes a real GLB for the Pest side and a
 * manifest describing it; `createPerStructureFixtureModel` builds the in-memory
 * scene graph the Vitest side raycasts against. If those two ever describe
 * different organs, every handover 17 test keeps passing while testing nothing —
 * which is the failure this file exists to make impossible.
 *
 * Same idea as `fitSizeParity.test.ts`: the value is asserted where it is used,
 * against the file that produces it, rather than trusted to stay in step.
 */
function meshesOf(scene: Object3D): Mesh[] {
  const found: Mesh[] = []

  scene.traverse((object) => {
    if (object instanceof Mesh) found.push(object)
  })

  return found
}

describe('the in-memory fixture mirrors the generated GLB', () => {
  it('has exactly the structure nodes the manifest lists', () => {
    const names = meshesOf(createPerStructureFixtureModel()).map((mesh) => mesh.name)

    expect(names).toEqual([...PER_STRUCTURE_FIXTURE.structureNodes])
  })

  it('puts every structure at the anchor the manifest records', () => {
    const scene = createPerStructureFixtureModel()

    for (const name of PER_STRUCTURE_FIXTURE.structureNodes) {
      const mesh = scene.getObjectByName(name)
      const anchor = PER_STRUCTURE_FIXTURE.anchors[name] as readonly number[]

      expect(mesh, `${name} is missing from the in-memory fixture`).toBeDefined()
      expect(mesh?.position.toArray()).toEqual([anchor[0], anchor[1], anchor[2]])
    }
  })

  it('groups every structure under one organ root', () => {
    // Handover 17 Branch A: structures hang off one root so the organ
    // transforms as a unit and FIT_SIZE normalisation applies to the root.
    const scene = createPerStructureFixtureModel()
    const root = scene.getObjectByName(PER_STRUCTURE_FIXTURE.rootNode)

    expect(root?.children).toHaveLength(PER_STRUCTURE_FIXTURE.structureNodes.length)
  })

  it('shares one material across the organ', () => {
    // Sixty materials per organ would destroy the draw-call budget, and a spec
    // that quietly used nine would not catch a highlight that mutates the
    // shared one instead of cloning it.
    const materials = new Set(meshesOf(createPerStructureFixtureModel()).map((m) => m.material))

    expect(materials.size).toBe(1)
  })

  it('stays inside the FIT_SIZE cube', () => {
    // Not a restatement of the pipeline check: this asserts the *in-memory*
    // half is in the same space, since it is built from manifest numbers rather
    // than measured.
    for (const anchor of Object.values(PER_STRUCTURE_FIXTURE.anchors)) {
      for (const axis of anchor) {
        expect(Math.abs(axis)).toBeLessThanOrEqual(1.9)
      }
    }
  })
})

describe('the DTO the fixture pairs with', () => {
  it('carries a mesh identity for every structure', () => {
    const organ = createPerStructureOrgan()

    expect(organ.structures).toHaveLength(PER_STRUCTURE_FIXTURE.structureNodes.length)

    for (const structure of organ.structures) {
      expect(structure.modelObjectName).not.toBeNull()
      expect(PER_STRUCTURE_FIXTURE.structureNodes).toContain(structure.modelObjectName)
    }
  })

  it('names a node that exists in the model, for every structure', () => {
    // The orphan check, in miniature. Handover 17 calls a rename that silently
    // orphans forty structures the single most likely failure in the lane;
    // `php artisan anatomy:verify-mesh-identity` is the shipped form of this.
    const scene = createPerStructureFixtureModel()

    for (const structure of createPerStructureOrgan().structures) {
      expect(
        scene.getObjectByName(structure.modelObjectName as string),
        `${structure.slug} names a node the model does not contain`,
      ).toBeDefined()
    }
  })

  it('keeps the anchor alongside the mesh identity, never instead of it', () => {
    // anchor_position remains the permanent fallback (handover 17 Branch B).
    for (const structure of createPerStructureOrgan().structures) {
      expect(structure.anchorPosition).toHaveLength(3)
    }
  })

  it('can drop one structure back to dot-only, for the mixed-mode case', () => {
    const organ = withoutMeshIdentity(createPerStructureOrgan(), 'aorta')
    const aorta = organ.structures.find((structure) => structure.slug === 'aorta')

    expect(aorta?.modelObjectName).toBeNull()
    expect(aorta?.anchorPosition).toHaveLength(3)
    expect(organ.structures.filter((s) => s.modelObjectName !== null)).toHaveLength(
      PER_STRUCTURE_FIXTURE.structureNodes.length - 1,
    )
  })
})
