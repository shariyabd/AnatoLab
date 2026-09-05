/**
 * The model manifest — the contract handover 03 seeds `organs.model_path` from.
 *
 * It is the join between a shipped binary and its row in docs/asset-register.md:
 * every entry carries a registerId, and verify-models.mjs refuses a manifest
 * whose registerId has no register row. That is what "no asset ships without a
 * row" looks like when a machine enforces it.
 */

import { readFileSync, writeFileSync } from 'node:fs'

export const MANIFEST_VERSION = 1

/**
 * Whether the model set may be deployed publicly.
 *
 *   pending-licence — the grant-vs-replace decision is open; nothing ships
 *   cleared         — every entry has a register row permitting deployment
 */
export const MANIFEST_STATUSES = ['pending-licence', 'cleared']

export function emptyManifest({ fitSize, budgets }) {
  return {
    version: MANIFEST_VERSION,
    status: 'pending-licence',
    generatedAt: null,
    fitSize,
    budgets,
    models: [],
  }
}

export function readManifest(path, fallback) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'))
  } catch (error) {
    if (error.code === 'ENOENT') return fallback

    throw new Error(`${path} is not readable JSON: ${error.message}`)
  }
}

export function writeManifest(path, manifest) {
  writeFileSync(path, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8')
}

/** Replaces the row for an organ, or appends it, keeping the file sorted. */
export function upsertModel(manifest, row) {
  const models = manifest.models.filter((model) => model.organSlug !== row.organSlug)

  models.push(row)
  models.sort((a, b) => a.organSlug.localeCompare(b.organSlug))

  return { ...manifest, generatedAt: new Date().toISOString(), models }
}
