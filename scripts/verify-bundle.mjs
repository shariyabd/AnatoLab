#!/usr/bin/env node
/**
 * Enforces the initial-JavaScript budget on the built client bundle —
 * docs/architecture.md §15.1, and one of handover 14's release checks.
 *
 * The budget is "initial page JS, excluding the Three.js chunk, under 200 KB
 * gzip". Three parts of that sentence are load-bearing, and this script is
 * mostly the business of getting them right:
 *
 * - **Initial** means what the browser must download and execute before the
 *   page it asked for can render: the Inertia entry, that page's own chunk,
 *   and everything either of them imports statically. A dynamic import is not
 *   initial — that is the whole reason for splitting one.
 * - **Excluding the Three.js chunk** is not "excluding whatever is biggest".
 *   The chunk is identified by the module that pulls Three.js in, and its
 *   transitive static imports go with it, so a page that shows a viewer is
 *   measured on everything except the 3D layer it loads to show one.
 * - **Gzip**, because that is what crosses the network. Measured at level 9,
 *   which is within a couple of percent of what a server will send and does
 *   not require guessing at one.
 *
 * The budget is read from config/anatomy.php, like the model budgets, so the
 * number lives in one place (scripts/lib/config.mjs).
 *
 * Usage:
 *   npm run build && node scripts/verify-bundle.mjs
 *   node scripts/verify-bundle.mjs --json    machine-readable, for a report
 */

import { existsSync, readFileSync } from 'node:fs'
import { gzipSync } from 'node:zlib'
import { resolve } from 'node:path'
import { parseArgs } from 'node:util'

import { BUILD_DIR, BUILD_MANIFEST_PATH, readBudgets, repoRoot } from './lib/config.mjs'

/**
 * How to recognise the Three.js chunk in the build manifest.
 *
 * It is shared by every page that shows a viewer, so Vite emits it as an
 * anonymous chunk keyed `_useAnatomyViewer-<hash>.js` rather than under its
 * source path — the hash changes on every build, the stem does not. Invariant 3
 * is what makes one name enough: `resources/js/anatomy/` is reachable only
 * through that composable, so whichever chunk Vite seeds from it is the one
 * with Three.js inside.
 *
 * Matched loosely enough to survive Vite emitting it under its source path
 * instead, which is what happens if only one page ever imports it.
 */
const THREE_CHUNK =
  /(^|\/)_?useAnatomyViewer[.-][^/]*\.js$|^resources\/js\/composables\/useAnatomyViewer\.ts$/

const failures = []

function gzippedBytes(file) {
  const path = resolve(repoRoot, BUILD_DIR, file)

  if (!existsSync(path)) {
    failures.push(`${file} is in the build manifest but not on disk`)

    return 0
  }

  return gzipSync(readFileSync(path), { level: 9 }).byteLength
}

/**
 * Every chunk the browser loads before `key` can run: the chunk itself and its
 * static imports, transitively. Dynamic imports are deliberately not followed.
 */
function staticClosure(manifest, key, seen = new Set()) {
  if (seen.has(key)) return seen

  const chunk = manifest[key]

  if (chunk === undefined) {
    failures.push(`the build manifest references "${key}", which it does not contain`)

    return seen
  }

  seen.add(key)

  for (const imported of chunk.imports ?? []) staticClosure(manifest, imported, seen)

  return seen
}

function formatBytes(bytes) {
  return `${(bytes / 1024).toFixed(1)} KB`
}

function main() {
  const { values } = parseArgs({ options: { json: { type: 'boolean', default: false } } })

  const manifestPath = resolve(repoRoot, BUILD_MANIFEST_PATH)

  if (!existsSync(manifestPath)) {
    console.error(
      `\n  ✗ ${BUILD_MANIFEST_PATH} is missing. Run \`npm run build\` before verifying the bundle.\n`,
    )
    process.exit(1)
  }

  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
  const budget = readBudgets().maxInitialJsGzipBytes

  const entry = Object.keys(manifest).find(
    (key) => manifest[key].isEntry === true && key.endsWith('.ts'),
  )

  if (entry === undefined) {
    console.error('\n  ✗ the build manifest has no JavaScript entry point.\n')
    process.exit(1)
  }

  // Everything the entry itself costs. Computed first because the Three.js
  // chunk statically imports the entry back — shared chunks in a Vite build
  // reference it for the module runtime — and excluding the entry along with
  // Three.js would credit every page with 96 KB it certainly does pay.
  const entryChunks = staticClosure(manifest, entry)

  const threeSeed = Object.keys(manifest).find((key) => THREE_CHUNK.test(key))

  const threeChunks = new Set(
    threeSeed === undefined
      ? []
      : [...staticClosure(manifest, threeSeed)].filter((key) => !entryChunks.has(key)),
  )

  if (threeChunks.size === 0) {
    failures.push(
      'no Three.js chunk in the build manifest. Either the viewer bridge moved — in which case ' +
        'update THREE_CHUNK here — or Three.js is no longer code-split, and this budget is ' +
        'measuring the wrong thing.',
    )
  }

  // Every page is measured, not just the heaviest: the budget is per page, and
  // "the average page is fine" is not the claim docs/architecture.md §15.1
  // makes.
  const pages = Object.keys(manifest).filter((key) => key.startsWith('resources/js/Pages/'))

  const rows = pages
    .map((page) => {
      const closure = staticClosure(manifest, page)
      for (const key of entryChunks) closure.add(key)

      const counted = [...closure].filter((key) => !threeChunks.has(key))
      const bytes = counted.reduce((total, key) => total + gzippedBytes(manifest[key].file), 0)

      return { page: page.replace('resources/js/Pages/', ''), bytes, chunks: counted.length }
    })
    .sort((a, b) => b.bytes - a.bytes)

  for (const row of rows) {
    if (row.bytes > budget) {
      failures.push(
        `${row.page}: ${formatBytes(row.bytes)} of initial JS, over the ` +
          `${formatBytes(budget)} budget`,
      )
    }
  }

  const threeBytes = [...threeChunks].reduce(
    (total, key) => total + gzippedBytes(manifest[key].file),
    0,
  )

  if (values.json) {
    console.log(
      JSON.stringify(
        {
          budgetBytes: budget,
          threeChunkBytes: threeBytes,
          worst: rows[0] ?? null,
          pages: rows,
        },
        null,
        2,
      ),
    )
  } else {
    console.log(`\nInitial JS per page, gzipped, excluding the Three.js chunk`)
    console.log(`  budget: ${formatBytes(budget)}`)
    console.log(`  Three.js chunk (on demand, not counted): ${formatBytes(threeBytes)}\n`)

    for (const row of rows.slice(0, 5)) {
      console.log(
        `  ${row.bytes > budget ? '✗' : '✓'} ${row.page.padEnd(28)}` +
          `${formatBytes(row.bytes).padStart(10)}  (${String(row.chunks)} chunks)`,
      )
    }

    if (rows.length > 5) console.log(`    … ${String(rows.length - 5)} more, all smaller\n`)
    else console.log('')
  }

  if (failures.length === 0) {
    console.log(`  ✓ ${String(rows.length)} pages within budget\n`)
    process.exit(0)
  }

  console.error(`\n  ✗ ${String(failures.length)} failure${failures.length === 1 ? '' : 's'}:`)
  for (const failure of failures) console.error(`      ${failure}`)
  console.error('')
  process.exit(1)
}

main()
