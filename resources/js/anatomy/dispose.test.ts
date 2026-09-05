import { describe, expect, it, vi } from 'vitest'
import { BoxGeometry, DataTexture, Group, Mesh, MeshStandardMaterial } from 'three'
import { disposeMaterial, disposeObject3D } from './dispose'

function createTexturedMesh(): { mesh: Mesh; material: MeshStandardMaterial } {
  const material = new MeshStandardMaterial({
    map: new DataTexture(new Uint8Array([1, 2, 3, 4]), 1, 1),
    normalMap: new DataTexture(new Uint8Array([1, 2, 3, 4]), 1, 1),
  })
  return { mesh: new Mesh(new BoxGeometry(), material), material }
}

describe('disposeMaterial', () => {
  it('disposes every texture slot, not just the colour map', () => {
    const { material } = createTexturedMesh()
    const map = vi.spyOn(material.map!, 'dispose')
    const normal = vi.spyOn(material.normalMap!, 'dispose')
    const self = vi.spyOn(material, 'dispose')

    disposeMaterial(material)

    expect(map).toHaveBeenCalledOnce()
    expect(normal).toHaveBeenCalledOnce()
    expect(self).toHaveBeenCalledOnce()
  })
})

describe('disposeObject3D', () => {
  it('disposes geometries and materials through the whole subtree', () => {
    const root = new Group()
    const parent = createTexturedMesh()
    const child = createTexturedMesh()
    parent.mesh.add(child.mesh)
    root.add(parent.mesh)

    const geometries = [parent.mesh.geometry, child.mesh.geometry].map((geometry) =>
      vi.spyOn(geometry, 'dispose'),
    )
    const materials = [parent.material, child.material].map((material) =>
      vi.spyOn(material, 'dispose'),
    )

    disposeObject3D(root)

    for (const spy of [...geometries, ...materials]) expect(spy).toHaveBeenCalledOnce()
  })

  it('disposes a shared material once, not once per mesh', () => {
    // Every model in the set is one material across every primitive
    // (docs/project-context.md §2.2); disposing it n times would fire n dispose
    // events and drive the renderer's memory counters negative.
    const material = new MeshStandardMaterial()
    const root = new Group()
    root.add(new Mesh(new BoxGeometry(), material), new Mesh(new BoxGeometry(), material))

    const spy = vi.spyOn(material, 'dispose')
    disposeObject3D(root)

    expect(spy).toHaveBeenCalledOnce()
  })

  it('detaches the subtree from its parent', () => {
    const scene = new Group()
    const root = new Group()
    scene.add(root)

    disposeObject3D(root)

    expect(root.parent).toBeNull()
    expect(scene.children).toHaveLength(0)
  })

  it('is safe to call twice', () => {
    const root = new Group()
    root.add(createTexturedMesh().mesh)

    disposeObject3D(root)
    expect(() => disposeObject3D(root)).not.toThrow()
  })
})
