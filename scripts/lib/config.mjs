/**
 * Single source of truth for every number the asset pipeline enforces.
 *
 * Nothing here is a literal. FIT_SIZE lives in resources/js/anatomy/constants.ts
 * and the budgets live in config/anatomy.php; both are read at run time so that
 * changing one of those files changes the pipeline and no third copy can drift
 * (docs/engineering.md §1 invariant 5).
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

const CONSTANTS_TS = resolve(repoRoot, 'resources/js/anatomy/constants.ts')
const ANATOMY_CONFIG = resolve(repoRoot, 'config/anatomy.php')

/**
 * The normalised model size every organ is scaled into.
 *
 * Read from the TypeScript file rather than mirrored, for the same reason
 * tests/Unit/FitSizeParityTest.php exists: a second literal is a second thing
 * that can silently disagree, and every anchor_position in the database is
 * authored against this number.
 */
export function readFitSize() {
  const source = readFileSync(CONSTANTS_TS, 'utf8')
  const matched = source.match(/export\s+const\s+FIT_SIZE\s*=\s*([0-9]*\.?[0-9]+)/)

  if (!matched) {
    throw new Error(
      `Could not find "export const FIT_SIZE = <number>" in ${CONSTANTS_TS}. ` +
        'The asset pipeline cannot normalise models without it.',
    )
  }

  return Number.parseFloat(matched[1])
}

/**
 * Evaluates the small arithmetic expressions config/anatomy.php uses for the
 * budgets — `2 * 1024 * 1024`, `150_000`. Deliberately not a PHP parser: the
 * grammar accepted is integers, underscores and `*`, and anything else throws
 * rather than guessing.
 */
function evaluateIntegerExpression(expression, key) {
  const cleaned = expression.replace(/_/g, '').trim()

  if (!/^\d+(\s*\*\s*\d+)*$/.test(cleaned)) {
    throw new Error(
      `config/anatomy.php: budgets.${key} is "${expression}", which this script cannot ` +
        'evaluate. Keep it to integers and `*`, or update scripts/lib/config.mjs.',
    )
  }

  return cleaned.split('*').reduce((product, term) => product * Number.parseInt(term, 10), 1)
}

/** Payload, geometry and bundle budgets (docs/architecture.md §15.1). */
export function readBudgets() {
  const source = readFileSync(ANATOMY_CONFIG, 'utf8')

  const read = (key) => {
    const matched = source.match(new RegExp(`'${key}'\\s*=>\\s*([^,\\n]+),`))

    if (!matched) {
      throw new Error(`Could not find budgets.${key} in ${ANATOMY_CONFIG}.`)
    }

    return evaluateIntegerExpression(matched[1], key)
  }

  return {
    maxModelBytes: read('max_model_bytes'),
    maxTriangles: read('max_triangles'),
    maxInitialJsGzipBytes: read('max_initial_js_gzip_bytes'),
  }
}

/**
 * How far a normalised model may sit from the ideal before the pipeline fails
 * it, as a fraction of FIT_SIZE.
 *
 * 0.2% ≈ 0.0076 world units at FIT_SIZE 3.8. That absorbs the ~0.00023 of
 * error 14-bit KHR_mesh_quantization introduces per axis, and is still three
 * orders of magnitude tighter than any genuinely un-normalised model.
 */
export const NORMALISATION_TOLERANCE_RATIO = 0.002

/** Where Vite writes the built client bundle, relative to the repo root. */
export const BUILD_DIR = 'public/build'
export const BUILD_MANIFEST_PATH = `${BUILD_DIR}/manifest.json`

/** Where the shipped models and their manifest live, relative to the repo root. */
export const MODEL_DIR = 'public/models'
export const MANIFEST_PATH = `${MODEL_DIR}/manifest.json`
