#!/usr/bin/env node
/**
 * Anatomy model encoding pipeline — handover 02 §3.
 *
 *   decimate to budget → EXT_meshopt_compression → KTX2/Basis textures
 *   → verify normalisation to FIT_SIZE → emit manifest row
 *
 * The verification is not advisory. A model that misses the payload budget,
 * the triangle budget, or the FIT_SIZE contract fails the run and is not
 * recorded in the manifest, because a manifest row is the claim that a file is
 * shippable.
 *
 * Usage:
 *   node scripts/encode-model.mjs <input.glb> --organ=heart --register-id=MDL-03
 *
 * See scripts/README.md for every option and for the KTX-Software prerequisite.
 */

import { existsSync, mkdirSync, statSync } from 'node:fs'
import { basename, dirname, relative, resolve } from 'node:path'
import { parseArgs } from 'node:util'

import { dedup, meshopt, prune, simplify, weld } from '@gltf-transform/functions'
import { MeshoptEncoder, MeshoptSimplifier } from 'meshoptimizer'

import { MANIFEST_PATH, MODEL_DIR, readBudgets, readFitSize, repoRoot } from './lib/config.mjs'
import { createIO } from './lib/io.mjs'
import { emptyManifest, readManifest, upsertModel, writeManifest } from './lib/manifest.mjs'
import {
  checkBudgets,
  checkNormalisation,
  formatBytes,
  hashFile,
  measureDocument,
} from './lib/measure.mjs'
import { normaliseScene } from './lib/normalise.mjs'
import { describeStructureExport } from './lib/structureNodes.mjs'
import { compressToKtx2, compressToWebp } from './lib/textures.mjs'

const PIPELINE_VERSION = '1.0.0'

/**
 * Simplification passes, tried in order until the triangle budget is met.
 *
 * Meshoptimizer treats `error` as a ceiling it will not exceed, so a single
 * aggressive ratio silently under-decimates rather than failing. Escalating
 * the error budget is how you actually reach a target, and starting tight
 * means a model that only needs a light pass gets one.
 */
const SIMPLIFY_ERROR_LADDER = [0.0005, 0.001, 0.005, 0.01, 0.02, 0.05]

function parseOptions() {
  const { values, positionals } = parseArgs({
    allowPositionals: true,
    options: {
      organ: { type: 'string' },
      'register-id': { type: 'string' },
      out: { type: 'string' },
      textures: { type: 'string', default: 'ktx2' },
      'texture-size': { type: 'string', default: '2048' },
      'texture-quality': { type: 'string', default: '90' },
      'uastc-quality': { type: 'string', default: '2' },
      zstd: { type: 'string', default: '18' },
      'triangle-budget': { type: 'string' },
      manifest: { type: 'string', default: MANIFEST_PATH },
      'no-manifest': { type: 'boolean', default: false },
      'dry-run': { type: 'boolean', default: false },
      help: { type: 'boolean', default: false },
    },
  })

  if (values.help || positionals.length === 0) {
    console.log(
      [
        'Usage: node scripts/encode-model.mjs <input.glb> --organ=<slug> --register-id=<id>',
        '',
        '  --out=<path>            default public/models/<organ>.glb',
        '  --textures=ktx2|webp|keep',
        '  --texture-size=<px>     square target, 1024 or 2048 (default 2048)',
        '  --texture-quality=<n>   WebP quality (default 90)',
        '  --uastc-quality=<0-4>   KTX2 UASTC quality for non-colour maps (default 2)',
        '  --zstd=<n>              KTX2 supercompression level (default 18)',
        '  --triangle-budget=<n>   default config/anatomy.php budgets.max_triangles',
        '  --manifest=<path>       default public/models/manifest.json',
        '  --no-manifest           encode and verify without recording a row',
        '  --dry-run               measure and report; write nothing',
        '',
        'See scripts/README.md.',
      ].join('\n'),
    )
    process.exit(values.help ? 0 : 1)
  }

  if (!values.organ) fail('--organ=<slug> is required; it keys the manifest row.')

  if (!values['register-id'] && !values['no-manifest'] && !values['dry-run']) {
    fail(
      '--register-id=<id> is required. No asset ships without a row in ' +
        'docs/asset-register.md (handover 02 §2).',
    )
  }

  if (!['ktx2', 'webp', 'keep'].includes(values.textures)) {
    fail(`--textures must be ktx2, webp or keep; got "${values.textures}".`)
  }

  return { values, input: resolve(positionals[0]) }
}

function fail(message) {
  console.error(`\n  ✗ ${message}\n`)
  process.exit(1)
}

function step(message) {
  console.log(`  · ${message}`)
}

async function decimate(document, budget) {
  await MeshoptSimplifier.ready

  const measure = () => measureDocument(document).triangles
  let triangles = measure()

  if (triangles <= budget) {
    step(`triangles ${triangles.toLocaleString('en-GB')} already within budget; no decimation`)

    return { applied: false, error: null, triangles }
  }

  for (const error of SIMPLIFY_ERROR_LADDER) {
    // Recomputed each pass: the previous pass already removed triangles, so a
    // ratio against the original count would over-decimate.
    const ratio = Math.min(1, (budget * 0.98) / triangles)

    await document.transform(simplify({ simplifier: MeshoptSimplifier, ratio, error }))

    const after = measure()
    step(
      `simplify error ${error}: ${triangles.toLocaleString('en-GB')} → ` +
        `${after.toLocaleString('en-GB')} triangles`,
    )
    triangles = after

    if (triangles <= budget) return { applied: true, error, triangles }
  }

  fail(
    `Could not reach ${budget.toLocaleString('en-GB')} triangles; stopped at ` +
      `${triangles.toLocaleString('en-GB')} at the loosest error in the ladder. ` +
      'The source mesh needs manual retopology.',
  )
}

async function main() {
  const { values, input } = parseOptions()

  if (!existsSync(input)) fail(`Input not found: ${input}`)

  const fitSize = readFitSize()
  const budgets = readBudgets()
  const triangleBudget = values['triangle-budget']
    ? Number.parseInt(values['triangle-budget'], 10)
    : budgets.maxTriangles

  const output = resolve(repoRoot, values.out ?? `${MODEL_DIR}/${values.organ}.glb`)

  console.log(`\nEncoding ${basename(input)} → ${relative(repoRoot, output)}`)
  console.log(
    `  budgets: ${formatBytes(budgets.maxModelBytes)}, ${triangleBudget.toLocaleString('en-GB')} triangles, FIT_SIZE ${fitSize}\n`,
  )

  const io = await createIO()
  const document = await io.read(input)

  const source = measureDocument(document)
  const sourceBytes = statSync(input).size
  step(
    `source: ${formatBytes(sourceBytes)}, ${source.triangles.toLocaleString('en-GB')} triangles, ` +
      `${source.textures.count} textures (${source.textures.codecs.join(', ') || 'none'})`,
  )

  // Before anything expensive. A naming or grouping mistake is fixed in Blender
  // and re-exported, so failing after a four-minute decimation run costs a round
  // trip for nothing. The authoritative check is on the shipped file below;
  // this one only saves time.
  const sourceStructures = describeStructureExport(document, values.organ)

  if (sourceStructures.failures.length > 0) {
    console.error(`\n  ✗ ${basename(input)} is not a usable per-structure export:`)
    for (const failure of sourceStructures.failures) console.error(`      ${failure}`)
    console.error('\n  Nothing was written. Fix the export and re-run.\n')
    process.exit(1)
  }

  step(
    sourceStructures.perStructure
      ? `${sourceStructures.structureNodes.length} structure nodes under root ` +
          `"${sourceStructures.rootNode}"`
      : 'single-mesh source: no per-structure nodes to preserve',
  )

  await document.transform(dedup(), prune(), weld())
  step('deduplicated, pruned and welded')

  const decimation = await decimate(document, triangleBudget)

  if (values.textures === 'ktx2') {
    const { ktxVersion } = await compressToKtx2(document, {
      size: Number.parseInt(values['texture-size'], 10),
      uastcQuality: Number.parseInt(values['uastc-quality'], 10),
      zstdLevel: Number.parseInt(values.zstd, 10),
    })
    step(`textures → KTX2 at ${values['texture-size']}² (${ktxVersion})`)
  } else if (values.textures === 'webp') {
    await compressToWebp(document, {
      size: Number.parseInt(values['texture-size'], 10),
      quality: Number.parseInt(values['texture-quality'], 10),
    })
    step(`textures → WebP at ${values['texture-size']}²`)
  } else {
    step('textures left as they are (--textures=keep)')
  }

  const normalisation = normaliseScene(document, fitSize)
  step(
    `normalised: scale ×${normalisation.scale.toFixed(6)}, longest axis ` +
      `${normalisation.before.longestAxis.toFixed(4)} → ${normalisation.after.longestAxis.toFixed(4)}`,
  )

  await document.transform(meshopt({ encoder: MeshoptEncoder, level: 'high' }))
  step('geometry → EXT_meshopt_compression')

  if (values['dry-run']) {
    console.log('\n  --dry-run: nothing written.\n')

    return
  }

  mkdirSync(dirname(output), { recursive: true })
  await io.write(output, document)

  // Re-read what was actually written. Verifying the in-memory document would
  // verify our intent; this verifies the artefact.
  const shipped = await io.read(output)
  const measured = measureDocument(shipped)
  const bytes = statSync(output).size
  const sha256 = await hashFile(output)

  const normalisationCheck = checkNormalisation(measured.bounds, fitSize)
  const budgetCheck = checkBudgets({ bytes, triangles: measured.triangles }, budgets)
  // Re-derived from the artefact rather than carried over from the source: dedup
  // and prune rewrite the node graph, and a pipeline that drops a structure node
  // must fail here rather than record a manifest row promising one.
  const structures = describeStructureExport(shipped, values.organ)

  console.log('')
  report(
    'payload',
    `${formatBytes(bytes)} of ${formatBytes(budgets.maxModelBytes)}`,
    budgetCheck.bytesOk,
  )
  report(
    'triangles',
    `${measured.triangles.toLocaleString('en-GB')} of ${budgets.maxTriangles.toLocaleString('en-GB')}`,
    budgetCheck.trianglesOk,
  )
  report(
    'normalised',
    `longest axis ${normalisationCheck.longestAxis.toFixed(6)}, centre offset ` +
      `${normalisationCheck.maxCentreOffset.toExponential(2)}`,
    normalisationCheck.ok,
  )

  if (structures.perStructure) {
    report(
      'structures',
      `${structures.structureNodes.length} nodes under "${structures.rootNode}", ` +
        `${structures.materials} material${structures.materials === 1 ? '' : 's'}`,
      structures.failures.length === 0,
    )
  }

  for (const warning of structures.warnings) console.log(`  ! ${warning}`)

  const failures = [...budgetCheck.failures, ...normalisationCheck.failures, ...structures.failures]

  if (failures.length > 0) {
    console.error(`\n  ✗ ${basename(output)} is not shippable:`)
    for (const failure of failures) console.error(`      ${failure}`)
    console.error('\n  The file was written so it can be inspected, but no manifest row was')
    console.error('  recorded — a manifest row is the claim that a file may ship.\n')
    process.exit(1)
  }

  if (values['no-manifest']) {
    console.log('\n  ✓ within budget. --no-manifest: no row recorded.\n')

    return
  }

  // modelPath is served to the client and seeded into organs.model_path, so a
  // row may only ever describe a file under public/. Encoding elsewhere is
  // legitimate — that is what evaluation runs do — but it is --no-manifest work.
  const modelPath = relative(resolve(repoRoot, 'public'), output)

  if (modelPath.startsWith('..')) {
    fail(
      `--out is outside public/ (${relative(repoRoot, output)}), so it has no servable path ` +
        'and cannot be recorded. Re-run with --no-manifest, or write into public/models/.',
    )
  }

  const manifestPath = resolve(repoRoot, values.manifest)
  const manifest = readManifest(manifestPath, emptyManifest({ fitSize, budgets }))

  writeManifest(
    manifestPath,
    upsertModel(manifest, {
      organSlug: values.organ,
      registerId: values['register-id'],
      fileName: basename(output),
      modelPath,
      modelFormat: 'glb',
      bytes,
      sha256,
      triangles: measured.triangles,
      vertices: measured.vertices,
      meshes: measured.meshes,
      materials: measured.materials,
      // The contract with handover 17 Branch B: MeshIdentitySeeder reads these
      // two keys and joins them onto anatomical_structures by slug. Omitted
      // entirely on a single-mesh organ, because a row carrying an empty list
      // would read as "this model has no structures" and clear every claim.
      ...(structures.perStructure
        ? { rootNode: structures.rootNode, structureNodes: structures.structureNodes }
        : {}),
      extensions: measured.extensions,
      textures: {
        count: measured.textures.count,
        bytes: measured.textures.bytes,
        maxDimension: measured.textures.maxDimension,
        codecs: measured.textures.codecs,
      },
      boundingBox: {
        min: measured.bounds.min,
        max: measured.bounds.max,
        size: measured.bounds.size,
        centre: measured.bounds.centre,
      },
      normalisation: {
        fitSize,
        longestAxis: normalisationCheck.longestAxis,
        maxCentreOffset: normalisationCheck.maxCentreOffset,
        tolerance: normalisationCheck.tolerance,
        ok: true,
      },
      budget: { bytesOk: true, trianglesOk: true, ok: true },
      source: {
        fileName: basename(input),
        bytes: sourceBytes,
        triangles: source.triangles,
        textureCodecs: source.textures.codecs,
      },
      encodedAt: new Date().toISOString(),
      encoder: {
        pipeline: `scripts/encode-model.mjs@${PIPELINE_VERSION}`,
        textures: values.textures,
        textureSize: Number.parseInt(values['texture-size'], 10),
        simplifyError: decimation.error,
      },
    }),
  )

  console.log(`\n  ✓ ${basename(output)} recorded in ${relative(repoRoot, manifestPath)}\n`)
}

function report(label, value, ok) {
  console.log(`  ${ok ? '✓' : '✗'} ${label.padEnd(11)} ${value}`)
}

main().catch((error) => {
  // The message is the actionable part; a glTF-Transform stack tells the reader
  // nothing they can act on. DEBUG=1 brings it back when it is genuinely a bug.
  fail(process.env.DEBUG ? (error.stack ?? error.message) : error.message)
})
