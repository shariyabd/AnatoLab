/**
 * Normalisation — the hard contract of handover 02.
 *
 * Every model must fit a FIT_SIZE cube centred on the origin, because every
 * anchor_position in the database is authored in that pivot space. A model
 * that skips this does not fail loudly; it puts every hotspot in the wrong
 * place, and no migration can repair it.
 *
 * The audited upstream models normalise at load time instead (their root node
 * carries a scale of ~0.49), which leaves the on-disk file un-normalised and
 * the guarantee unverifiable. We bake it into the file so it can be checked
 * mechanically, here and again at release.
 */

import { measureBounds } from './measure.mjs'

/**
 * The single node normalisation wraps the scene in.
 *
 * Exported because `structureNodes.mjs` has to tell an organ root apart from
 * this: structures hanging directly off the pivot are structures that were
 * never grouped, and the two nodes are otherwise indistinguishable — both are
 * unnamed-by-convention parents carrying no mesh.
 */
export const PIVOT_NODE_NAME = 'anatolab_normalised_pivot'

/**
 * Scales and centres the default scene so its longest axis measures fitSize.
 *
 * Applied as a single pivot node wrapping the existing scene roots rather than
 * by rewriting vertex data: it is exact, it survives quantization, and running
 * it twice is a no-op because the second pass computes a scale of 1.
 */
export function normaliseScene(document, fitSize) {
  const root = document.getRoot()
  const scene = root.getDefaultScene() ?? root.listScenes()[0]

  if (!scene) {
    throw new Error('The file contains no scene to normalise.')
  }

  const before = measureBounds(document)

  if (before.longestAxis === 0) {
    throw new Error('The scene has zero extent — there is no geometry to normalise.')
  }

  const scale = fitSize / before.longestAxis
  const translation = before.centre.map((axis) => -axis * scale)

  const pivot = document
    .createNode(PIVOT_NODE_NAME)
    .setScale([scale, scale, scale])
    .setTranslation(translation)

  for (const child of scene.listChildren()) {
    scene.removeChild(child)
    pivot.addChild(child)
  }

  scene.addChild(pivot)

  return { scale, translation, before, after: measureBounds(document) }
}
