/**
 * Measurement and verification primitives shared by encode-model.mjs and
 * verify-models.mjs. Pure functions over a glTF-Transform Document — they
 * never write a file, so the encoder and the verifier can never disagree
 * about what a number means.
 */

import { createHash } from 'node:crypto'
import { createReadStream } from 'node:fs'
import { getBounds } from '@gltf-transform/core'

import { NORMALISATION_TOLERANCE_RATIO } from './config.mjs'

/** glTF primitive mode for TRIANGLES. Other modes carry no triangle cost. */
const MODE_TRIANGLES = 4

/**
 * Triangles as the GPU sees them: counted per node instance, not per mesh.
 *
 * A mesh referenced by three nodes is drawn three times and costs three times
 * as much, which is what the 150k budget is about. The audited models are one
 * node each, so this only matters for whatever replaces them.
 */
export function countTriangles(document) {
  const meshTriangles = new Map()

  for (const mesh of document.getRoot().listMeshes()) {
    let total = 0

    for (const primitive of mesh.listPrimitives()) {
      if (primitive.getMode() !== MODE_TRIANGLES) continue

      const indices = primitive.getIndices()
      const position = primitive.getAttribute('POSITION')
      const vertexCount = indices ? indices.getCount() : (position?.getCount() ?? 0)

      total += Math.floor(vertexCount / 3)
    }

    meshTriangles.set(mesh, total)
  }

  let instanced = 0

  for (const node of document.getRoot().listNodes()) {
    const mesh = node.getMesh()
    if (mesh) instanced += meshTriangles.get(mesh) ?? 0
  }

  return instanced
}

/** Unique vertices held in the file, which is what the download pays for. */
export function countVertices(document) {
  let vertices = 0

  for (const mesh of document.getRoot().listMeshes()) {
    for (const primitive of mesh.listPrimitives()) {
      vertices += primitive.getAttribute('POSITION')?.getCount() ?? 0
    }
  }

  return vertices
}

/** Every image in the file, with the codec and dimensions it actually ships at. */
export function inspectTextures(document) {
  return document
    .getRoot()
    .listTextures()
    .map((texture) => {
      const size = texture.getSize()

      return {
        name: texture.getName() || null,
        mimeType: texture.getMimeType() || null,
        width: size ? size[0] : null,
        height: size ? size[1] : null,
        bytes: texture.getImage()?.byteLength ?? 0,
      }
    })
}

/** Extensions the file requires a loader to understand. */
export function listExtensions(document) {
  return document
    .getRoot()
    .listExtensionsUsed()
    .map((extension) => extension.extensionName)
    .sort()
}

/** World-space bounding box of the default scene, after all node transforms. */
export function measureBounds(document) {
  const scene = document.getRoot().getDefaultScene() ?? document.getRoot().listScenes()[0]

  if (!scene) {
    throw new Error('The file contains no scene, so it has no bounding box to normalise.')
  }

  const { min, max } = getBounds(scene)
  const size = [max[0] - min[0], max[1] - min[1], max[2] - min[2]]
  const centre = [(max[0] + min[0]) / 2, (max[1] + min[1]) / 2, (max[2] + min[2]) / 2]

  return { min, max, size, centre, longestAxis: Math.max(...size) }
}

/**
 * The hard contract from handover 02: the model fits a FIT_SIZE cube centred
 * on the origin. Every anchor_position in the database is authored in that
 * space, so a model that fails this silently invalidates all of them.
 */
export function checkNormalisation(bounds, fitSize) {
  const tolerance = fitSize * NORMALISATION_TOLERANCE_RATIO
  const longestAxisError = Math.abs(bounds.longestAxis - fitSize)
  const maxCentreOffset = Math.max(...bounds.centre.map(Math.abs))

  const failures = []

  if (longestAxisError > tolerance) {
    failures.push(
      `longest axis is ${bounds.longestAxis.toFixed(6)}, expected ${fitSize} ` +
        `(±${tolerance.toFixed(6)})`,
    )
  }

  if (maxCentreOffset > tolerance) {
    failures.push(
      `centre is [${bounds.centre.map((n) => n.toFixed(6)).join(', ')}], expected the origin ` +
        `(±${tolerance.toFixed(6)})`,
    )
  }

  return {
    ok: failures.length === 0,
    fitSize,
    tolerance,
    longestAxis: bounds.longestAxis,
    maxCentreOffset,
    failures,
  }
}

/** Budget verdict for one model (docs/architecture.md §15.1). */
export function checkBudgets({ bytes, triangles }, budgets) {
  const failures = []

  if (bytes > budgets.maxModelBytes) {
    failures.push(
      `${formatBytes(bytes)} exceeds the ${formatBytes(budgets.maxModelBytes)} payload budget`,
    )
  }

  if (triangles > budgets.maxTriangles) {
    failures.push(
      `${triangles.toLocaleString('en-GB')} triangles exceeds the ` +
        `${budgets.maxTriangles.toLocaleString('en-GB')} budget`,
    )
  }

  return {
    ok: failures.length === 0,
    bytesOk: bytes <= budgets.maxModelBytes,
    trianglesOk: triangles <= budgets.maxTriangles,
    failures,
  }
}

/**
 * Everything measurable about a document in one call, so the manifest row and
 * the verifier's re-measurement are produced by identical code.
 */
export function measureDocument(document) {
  const textures = inspectTextures(document)
  const bounds = measureBounds(document)

  return {
    triangles: countTriangles(document),
    vertices: countVertices(document),
    meshes: document.getRoot().listMeshes().length,
    materials: document.getRoot().listMaterials().length,
    extensions: listExtensions(document),
    textures: {
      count: textures.length,
      bytes: textures.reduce((total, texture) => total + texture.bytes, 0),
      maxDimension: textures.reduce(
        (largest, texture) => Math.max(largest, texture.width ?? 0, texture.height ?? 0),
        0,
      ),
      codecs: [...new Set(textures.map((texture) => texture.mimeType).filter(Boolean))].sort(),
      images: textures,
    },
    bounds,
  }
}

export function hashFile(path) {
  return new Promise((resolve, reject) => {
    const hash = createHash('sha256')

    createReadStream(path)
      .on('error', reject)
      .on('data', (chunk) => hash.update(chunk))
      .on('end', () => resolve(hash.digest('hex')))
  })
}

export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`
}
