#!/usr/bin/env node
/**
 * Re-verifies the whole shipped model set against the manifest — handover 02
 * "Tests", and the check handover 14 runs before the release gate.
 *
 * encode-model.mjs verifies a model at the moment it is produced. This verifies
 * the set as it stands now: files can be replaced by hand, a budget in
 * config/anatomy.php can be tightened, and a manifest row can be edited. None
 * of those should be able to put an unshippable asset in front of a student.
 *
 * Usage:
 *   node scripts/verify-models.mjs            verify whatever is present
 *   node scripts/verify-models.mjs --release  additionally require the licence
 *                                             gate to be closed and the set complete
 */

import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { statSync } from 'node:fs'
import { relative, resolve } from 'node:path'
import { parseArgs } from 'node:util'

import { MANIFEST_PATH, MODEL_DIR, readBudgets, readFitSize, repoRoot } from './lib/config.mjs'
import { createIO } from './lib/io.mjs'
import { readManifest } from './lib/manifest.mjs'
import {
  checkBudgets,
  checkNormalisation,
  formatBytes,
  hashFile,
  measureDocument,
} from './lib/measure.mjs'

const ASSET_REGISTER = 'docs/asset-register.md'

/** Decoder payloads the audit found shipped but unreferenced (handover 02 §4). */
const FORBIDDEN_PAYLOAD_DIRS = ['public/draco', 'public/basis']

const failures = []
const warnings = []

function check(ok, message) {
  if (!ok) failures.push(message)

  return ok
}

/**
 * Register ids as they appear in docs/asset-register.md — MDL-01, TEX-03.
 * The register is prose for humans; this is the one thing a machine reads out
 * of it, so the format is deliberately narrow.
 */
function readRegisterIds() {
  const path = resolve(repoRoot, ASSET_REGISTER)

  if (!existsSync(path)) {
    failures.push(`${ASSET_REGISTER} is missing. No asset ships without a register row.`)

    return new Set()
  }

  return new Set(readFileSync(path, 'utf8').match(/\b[A-Z]{3}-\d{2,}\b/g) ?? [])
}

async function verifyModel(io, model, { fitSize, budgets, registerIds }) {
  const label = model.organSlug
  const path = resolve(repoRoot, 'public', model.modelPath)

  if (!check(existsSync(path), `${label}: ${model.modelPath} is in the manifest but not on disk`)) {
    return { label, ok: false, bytes: 0, triangles: 0 }
  }

  check(
    registerIds.has(model.registerId),
    `${label}: registerId "${model.registerId}" has no row in ${ASSET_REGISTER}`,
  )

  const bytes = statSync(path).size
  const sha256 = await hashFile(path)

  check(
    sha256 === model.sha256,
    `${label}: sha256 does not match the manifest — the file changed without being re-encoded`,
  )

  const document = await io.read(path)
  const measured = measureDocument(document)

  const budgetCheck = checkBudgets({ bytes, triangles: measured.triangles }, budgets)
  const normalisationCheck = checkNormalisation(measured.bounds, fitSize)

  for (const failure of budgetCheck.failures) failures.push(`${label}: ${failure}`)
  for (const failure of normalisationCheck.failures) failures.push(`${label}: ${failure}`)

  check(
    measured.triangles === model.triangles,
    `${label}: ${measured.triangles} triangles on disk, ${model.triangles} recorded in the manifest`,
  )

  return {
    label,
    ok: budgetCheck.ok && normalisationCheck.ok,
    bytes,
    triangles: measured.triangles,
    longestAxis: normalisationCheck.longestAxis,
  }
}

async function main() {
  const { values } = parseArgs({ options: { release: { type: 'boolean', default: false } } })

  const fitSize = readFitSize()
  const budgets = readBudgets()
  const manifestPath = resolve(repoRoot, MANIFEST_PATH)
  const manifest = readManifest(manifestPath, null)

  console.log(`\nVerifying ${MODEL_DIR} against ${MANIFEST_PATH}`)
  console.log(
    `  budgets: ${formatBytes(budgets.maxModelBytes)}, ` +
      `${budgets.maxTriangles.toLocaleString('en-GB')} triangles, FIT_SIZE ${fitSize}\n`,
  )

  if (!manifest) {
    failures.push(`${MANIFEST_PATH} is missing. Run scripts/encode-model.mjs to create it.`)

    return finish()
  }

  check(
    manifest.fitSize === fitSize,
    `manifest fitSize is ${manifest.fitSize} but resources/js/anatomy/constants.ts says ${fitSize}. ` +
      'Every anchor_position was authored against one of these; re-encode before trusting either.',
  )
  check(
    manifest.budgets?.maxModelBytes === budgets.maxModelBytes &&
      manifest.budgets?.maxTriangles === budgets.maxTriangles,
    'manifest budgets differ from config/anatomy.php — the set was encoded against different limits',
  )

  for (const dir of FORBIDDEN_PAYLOAD_DIRS) {
    check(
      !existsSync(resolve(repoRoot, dir)),
      `${dir}/ exists. The audited loader referenced no decoder there; it is pure payload ` +
        '(handover 02 §4). Reintroduce one only when a loader actually uses it.',
    )
  }

  const registerIds = readRegisterIds()
  const io = await createIO()
  const rows = []

  for (const model of manifest.models) {
    rows.push(await verifyModel(io, model, { fitSize, budgets, registerIds }))
  }

  const modelDir = resolve(repoRoot, MODEL_DIR)
  const onDisk = existsSync(modelDir)
    ? readdirSync(modelDir).filter((name) => /\.(glb|gltf)$/.test(name))
    : []
  const manifested = new Set(manifest.models.map((model) => model.fileName))

  for (const orphan of onDisk.filter((name) => !manifested.has(name))) {
    failures.push(`${MODEL_DIR}/${orphan} has no manifest row. No asset ships without a row.`)
  }

  if (rows.length > 0) {
    console.log(
      `  ${'organ'.padEnd(12)}${'payload'.padStart(10)}${'triangles'.padStart(12)}${'longest axis'.padStart(15)}`,
    )
    for (const row of rows) {
      console.log(
        `  ${row.ok ? '✓' : '✗'} ${row.label.padEnd(10)}` +
          `${formatBytes(row.bytes).padStart(10)}` +
          `${row.triangles.toLocaleString('en-GB').padStart(12)}` +
          `${(row.longestAxis?.toFixed(4) ?? '—').padStart(15)}`,
      )
    }
    console.log('')
  }

  if (values.release) {
    check(
      manifest.status === 'cleared',
      `manifest status is "${manifest.status}". The licence gate is open — see ` +
        `${ASSET_REGISTER}. Nothing deploys publicly until it closes (handover 02).`,
    )
    check(manifest.models.length > 0, 'the manifest lists no models; there is nothing to ship')
  } else if (manifest.models.length === 0) {
    warnings.push(
      `${MANIFEST_PATH} lists no models. Nothing to verify — see ${ASSET_REGISTER} for why.`,
    )
  }

  finish()
}

function finish() {
  for (const warning of warnings) console.log(`  ! ${warning}`)

  if (failures.length === 0) {
    console.log(`\n  ✓ ${warnings.length > 0 ? 'no failures' : 'model set verified'}\n`)
    process.exit(0)
  }

  console.error(`\n  ✗ ${failures.length} failure${failures.length === 1 ? '' : 's'}:`)
  for (const failure of failures) console.error(`      ${failure}`)
  console.error('')
  process.exit(1)
}

main().catch((error) => {
  console.error(`\n  ✗ ${error.stack ?? error.message}\n`)
  process.exit(1)
})
