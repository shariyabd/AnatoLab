/**
 * Deep disposal for a Three.js subtree.
 *
 * Three.js allocates GPU resources outside the JavaScript heap, so dropping the
 * last reference to a Mesh frees nothing — the VBO and the texture stay resident
 * until `dispose()` is called on each one. A page that mounts and unmounts a
 * viewer per organ leaks a whole model every time without this, which on a
 * 150k-triangle budget is tens of megabytes per navigation.
 *
 * docs/architecture.md §5.4 rule 6 makes disposal mandatory and requires a test
 * that renderer, geometry, and texture counts return to zero. See
 * `AnatomyViewer.test.ts`.
 */

import type { Material, Object3D, Texture } from 'three'
import { Mesh, Points, Line, Sprite, SkinnedMesh } from 'three'

/**
 * Three does not expose "every texture on this material" — the slot names differ
 * per material class and change between releases. Walking the enumerable
 * properties and disposing anything that looks like a Texture is the only
 * version-proof way to catch `map`, `normalMap`, `aoMap`, `emissiveMap`, and the
 * KHR extension slots alike.
 */
function isTexture(value: unknown): value is Texture {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { isTexture?: boolean }).isTexture === true
  )
}

export function disposeMaterial(material: Material): void {
  for (const value of Object.values(material)) {
    if (isTexture(value)) {
      value.dispose()
    }
  }
  material.dispose()
}

function hasGeometry(
  object: Object3D,
): object is Object3D & { geometry: { dispose(): void }; material: Material | Material[] } {
  return (
    object instanceof Mesh ||
    object instanceof SkinnedMesh ||
    object instanceof Points ||
    object instanceof Line ||
    object instanceof Sprite
  )
}

/**
 * Disposes every geometry, material, and texture under `root`, then detaches
 * `root` from its parent. Safe to call twice — `dispose()` on an already
 * disposed resource is a no-op in Three.
 */
export function disposeObject3D(root: Object3D): void {
  const materials = new Set<Material>()

  root.traverse((object) => {
    if (!hasGeometry(object)) return

    object.geometry.dispose()

    if (Array.isArray(object.material)) {
      for (const material of object.material) materials.add(material)
    } else {
      materials.add(object.material)
    }
  })

  // Materials are shared across primitives in a glTF far more often than
  // geometries are, so collect first and dispose once — disposing the same
  // material n times would fire n dispose events and confuse the renderer's
  // memory counters, which is exactly what the disposal test reads.
  for (const material of materials) disposeMaterial(material)

  root.removeFromParent()
  root.clear()
}
