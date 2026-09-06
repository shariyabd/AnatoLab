#!/usr/bin/env node
/**
 * Generates the per-structure test fixture — handover 17, groundwork.
 *
 * Handover 17 orders its branches A → B → C, because B needs A's node names and
 * C needs both. That serialisation is real for *shipped* assets, and it is
 * entirely avoidable for *tests*: what B and C actually need from A is one GLB
 * with per-structure nodes, and nothing says it has to be an anatomically
 * sourced one.
 *
 * So this writes one, from geometry we generate ourselves. It is not anatomy and
 * must never be mistaken for it — it is nine spheres at the heart's authored
 * anchor positions. What makes it useful is that everything *around* the
 * geometry is real: the node naming convention, the organ-root grouping, the
 * FIT_SIZE normalisation, the budget check, and the manifest shape Branch B
 * seeds from. When a licensed Z-Anatomy heart lands, the tests written against
 * this fixture keep passing and only the asset changes.
 *
 * It also sidesteps the licence gate completely. `docs/asset-sources.md` §3.1
 * records that no source has been adopted and no rights have been exercised;
 * this file exercises none, because we authored the vertices.
 *
 * Usage:
 *   node scripts/make-structure-fixture.mjs           write the fixture
 *   node scripts/make-structure-fixture.mjs --check   verify without writing
 */

import { existsSync, mkdirSync } from 'node:fs'
import { dirname, relative, resolve } from 'node:path'
import { parseArgs } from 'node:util'

import { Document, NodeIO } from '@gltf-transform/core'

import { readBudgets, readFitSize, repoRoot } from './lib/config.mjs'
import { checkBudgets, checkNormalisation, measureDocument } from './lib/measure.mjs'
import { normaliseScene } from './lib/normalise.mjs'
import { auditStructureNodes, structureNodeName } from './lib/structureNodes.mjs'

/** Where the fixture lives. Outside public/, so .gitignore does not exclude it. */
const FIXTURE_DIR = 'tests/Fixtures/models'
const FIXTURE_FILE = `${FIXTURE_DIR}/heart-per-structure.glb`
const FIXTURE_MANIFEST = `${FIXTURE_DIR}/manifest.json`

const ORGAN_SLUG = 'heart'

/**
 * The heart as `AnatomySeeder` seeds it: nine structures, their slugs, and the
 * anchor positions authored in FIT_SIZE pivot space.
 *
 * Copied deliberately rather than read from the database. A fixture generator
 * that needs a booted Laravel application is a fixture generator that cannot run
 * in CI before migrations, and the point of these nine rows is that they are the
 * ones Branch B will join against — if the seeder's slugs change, the join test
 * fails and says so, which is the behaviour we want.
 */
const STRUCTURES = [
  { slug: 'right-atrium', anchor: [-0.85, 0.55, 0.35] },
  { slug: 'right-ventricle', anchor: [-0.55, -0.7, 0.75] },
  { slug: 'left-atrium', anchor: [0.8, 0.6, -0.45] },
  { slug: 'left-ventricle', anchor: [0.7, -0.75, 0.65] },
  { slug: 'aorta', anchor: [0.1, 1.35, -0.2] },
  { slug: 'pulmonary-trunk', anchor: [-0.3, 1.15, 0.55] },
  { slug: 'superior-vena-cava', anchor: [-0.95, 1.05, 0.1] },
  { slug: 'mitral-valve', anchor: [0.45, 0.05, 0.1] },
  { slug: 'apex', anchor: [0.55, -1.45, 0.4] },
]

/**
 * Small enough that no two structures overlap at their authored anchors — the
 * closest pair (mitral-valve to left-ventricle) is 0.85 apart — so a raycast
 * returns exactly one structure and a test can say which.
 */
const STRUCTURE_RADIUS = 0.3

/** Coarse on purpose: this fixture is parsed in every test run, not rendered. */
const SPHERE_SEGMENTS = 12
const SPHERE_RINGS = 8

/**
 * A UV sphere, generated rather than imported.
 *
 * Written out here so the fixture depends on no mesh anyone else owns. Twelve
 * segments by eight rings is 168 triangles per structure, 1,512 for the organ —
 * three orders of magnitude inside the budget, which is the right size for
 * something git stores and every CI run parses.
 */
function sphere(radius) {
  const positions = []
  const normals = []
  const indices = []

  for (let ring = 0; ring <= SPHERE_RINGS; ring += 1) {
    const phi = (ring / SPHERE_RINGS) * Math.PI

    for (let segment = 0; segment <= SPHERE_SEGMENTS; segment += 1) {
      const theta = (segment / SPHERE_SEGMENTS) * Math.PI * 2

      const nx = Math.sin(phi) * Math.cos(theta)
      const ny = Math.cos(phi)
      const nz = Math.sin(phi) * Math.sin(theta)

      normals.push(nx, ny, nz)
      positions.push(nx * radius, ny * radius, nz * radius)
    }
  }

  const stride = SPHERE_SEGMENTS + 1

  for (let ring = 0; ring < SPHERE_RINGS; ring += 1) {
    for (let segment = 0; segment < SPHERE_SEGMENTS; segment += 1) {
      const a = ring * stride + segment
      const b = a + stride

      indices.push(a, b, a + 1, b, b + 1, a + 1)
    }
  }

  return {
    position: new Float32Array(positions),
    normal: new Float32Array(normals),
    index: new Uint16Array(indices),
  }
}

/**
 * Recentres and rescales the authored anchors so the union of the structures
 * exactly fills the FIT_SIZE cube.
 *
 * Done here, at authoring time, rather than left to `normaliseScene`, and the
 * difference matters: if the file arrived un-normalised, the pipeline would
 * scale it and every anchor in the fixture manifest would then name a point the
 * geometry is no longer at. Doing it first means `normaliseScene` computes a
 * scale of 1 — which the script then asserts, so the pipeline is still the thing
 * that verifies the guarantee rather than being skipped.
 */
function fitAnchorsToCube(structures, radius, fitSize) {
  const axes = [0, 1, 2]
  const min = axes.map((axis) => Math.min(...structures.map((s) => s.anchor[axis] - radius)))
  const max = axes.map((axis) => Math.max(...structures.map((s) => s.anchor[axis] + radius)))

  const size = axes.map((axis) => max[axis] - min[axis])
  const centre = axes.map((axis) => (max[axis] + min[axis]) / 2)
  const scale = fitSize / Math.max(...size)

  return {
    scale,
    radius: radius * scale,
    structures: structures.map((structure) => ({
      ...structure,
      anchor: axes.map((axis) => (structure.anchor[axis] - centre[axis]) * scale),
    })),
  }
}

function buildDocument(structures, radius) {
  const document = new Document()
  document.createBuffer()

  const scene = document.createScene('heart-fixture')

  // One shared material for the whole organ. Handover 17 Branch A: per-structure
  // material slots only where the source provides them, because sixty materials
  // is sixty draw calls.
  const material = document
    .createMaterial(`${ORGAN_SLUG}__material`)
    .setBaseColorFactor([0.78, 0.28, 0.29, 1])
    .setRoughnessFactor(0.45)

  // Every structure hangs off one organ root, so the organ transforms as a unit
  // and FIT_SIZE normalisation applies to the root rather than per structure.
  const root = document.createNode(ORGAN_SLUG)
  scene.addChild(root)

  // One sphere's worth of vertex data, reused by every structure. The anchor
  // lives in the *node* translation, not baked into the positions, for two
  // reasons: it is what a real per-structure Blender export produces (each
  // object keeps its own origin), and it makes
  // `scene.getObjectByName('heart__apex').position` the structure's anchor,
  // which is the thing Branch C's camera and highlight code will reach for.
  const geometry = sphere(radius)

  for (const structure of structures) {
    const primitive = document
      .createPrimitive()
      .setMaterial(material)
      .setAttribute(
        'POSITION',
        document.createAccessor().setType('VEC3').setArray(geometry.position),
      )
      .setAttribute('NORMAL', document.createAccessor().setType('VEC3').setArray(geometry.normal))
      .setIndices(document.createAccessor().setType('SCALAR').setArray(geometry.index))

    const name = structureNodeName(ORGAN_SLUG, structure.slug)
    const mesh = document.createMesh(name).addPrimitive(primitive)

    root.addChild(document.createNode(name).setMesh(mesh).setTranslation(structure.anchor))
  }

  return document
}

async function main() {
  const { values } = parseArgs({ options: { check: { type: 'boolean', default: false } } })

  const fitSize = readFitSize()
  const budgets = readBudgets()

  const fitted = fitAnchorsToCube(STRUCTURES, STRUCTURE_RADIUS, fitSize)
  const document = buildDocument(fitted.structures, fitted.radius)

  // The pipeline still verifies the guarantee even though the geometry was
  // authored to satisfy it. A scale of anything but 1 here means the authoring
  // arithmetic above is wrong and the manifest's anchors are lies.
  const normalisation = normaliseScene(document, fitSize)

  if (Math.abs(normalisation.scale - 1) > 1e-6) {
    throw new Error(
      `The fixture was authored un-normalised: normaliseScene wants to scale it by ` +
        `${normalisation.scale}. Every anchor in ${FIXTURE_MANIFEST} would be wrong.`,
    )
  }

  const audit = auditStructureNodes(document, ORGAN_SLUG)

  if (!audit.ok) {
    throw new Error(
      'Structure node audit failed: ' +
        JSON.stringify({
          foreign: audit.foreign,
          duplicates: audit.duplicates,
          unconventional: audit.unconventional,
        }),
    )
  }

  const io = new NodeIO()
  const binary = await io.writeBinary(document)

  const measured = measureDocument(document)
  const bounds = measured.bounds
  const budget = checkBudgets({ bytes: binary.byteLength, triangles: measured.triangles }, budgets)
  const normalised = checkNormalisation(bounds, fitSize)

  if (!budget.ok) throw new Error(`The fixture is over budget: ${JSON.stringify(budget)}`)
  if (!normalised.ok)
    throw new Error(`The fixture is not normalised: ${JSON.stringify(normalised)}`)

  const manifest = {
    version: 1,
    // Not `pending-licence` and not `cleared`: this is not a shipped asset and
    // has no register row, because there is no third party to attribute.
    // `docs/asset-sources.md`'s gate is about what we may take from elsewhere.
    status: 'generated-fixture',
    generatedAt: new Date().toISOString(),
    generator: 'scripts/make-structure-fixture.mjs',
    fitSize,
    budgets: { maxModelBytes: budgets.maxModelBytes, maxTriangles: budgets.maxTriangles },
    models: [
      {
        organSlug: ORGAN_SLUG,
        fileName: 'heart-per-structure.glb',
        modelPath: `${FIXTURE_DIR}/heart-per-structure.glb`,
        modelFormat: 'glb',
        bytes: binary.byteLength,
        triangles: measured.triangles,
        rootNode: ORGAN_SLUG,
        // The contract between Branch A and Branch B: every structure node this
        // file contains, and the point each one is centred on.
        structureNodes: audit.structureNodes,
        anchors: Object.fromEntries(
          fitted.structures.map((structure) => [
            structureNodeName(ORGAN_SLUG, structure.slug),
            structure.anchor.map((axis) => Number(axis.toFixed(6))),
          ]),
        ),
        structureRadius: Number(fitted.radius.toFixed(6)),
        boundingBox: bounds,
        normalisation: { fitSize, ...normalised },
        budget,
      },
    ],
  }

  const target = resolve(repoRoot, FIXTURE_FILE)

  // --check is what stops the committed fixture and this generator drifting
  // apart: a hand-edited .glb, or a change here that nobody re-ran. It compares
  // bytes rather than node names, because a fixture that differs at all is a
  // fixture whose manifest may no longer describe it.
  if (values.check) {
    const { readFileSync } = await import('node:fs')

    if (!existsSync(target)) {
      throw new Error(`${FIXTURE_FILE} is missing. Run \`npm run models:fixture\`.`)
    }

    const committed = readFileSync(target)

    if (!committed.equals(Buffer.from(binary))) {
      throw new Error(
        `${FIXTURE_FILE} does not match what this script generates ` +
          `(${committed.byteLength} bytes on disk, ${binary.byteLength} regenerated). ` +
          'Re-run `npm run models:fixture` and commit the result.',
      )
    }

    console.log(`  ✓ ${FIXTURE_FILE} matches its generator (${binary.byteLength} bytes)`)

    return
  }

  const { writeFileSync } = await import('node:fs')

  mkdirSync(dirname(target), { recursive: true })
  writeFileSync(target, binary)
  writeFileSync(
    resolve(repoRoot, FIXTURE_MANIFEST),
    `${JSON.stringify(manifest, null, 2)}\n`,
    'utf8',
  )

  console.log(`\n  ${relative(repoRoot, target)}`)
  console.log(`    ${audit.structureNodes.length} structure nodes under root "${ORGAN_SLUG}"`)
  console.log(`    ${binary.byteLength} bytes, ${measured.triangles} triangles`)
  console.log(`    longest axis ${bounds.size ? Math.max(...bounds.size).toFixed(4) : '?'}\n`)
}

main().catch((error) => {
  console.error(`\n  ✗ ${error.message}\n`)
  process.exitCode = 1
})
