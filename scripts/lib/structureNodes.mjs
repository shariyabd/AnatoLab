/**
 * The per-structure node naming convention — handover 17 Branch A.
 *
 * `<organ-slug>__<structure-slug>` → `heart__left-ventricle`. Lowercase, kebab,
 * double underscore separator, no spaces and no Latin diacritics: the TA term
 * lives in `anatomical_structures.ta_term`, not in the mesh.
 *
 * It is a module rather than a sentence in a handover because it is the contract
 * between three branches that land at different times. A names the nodes on
 * export, B seeds `model_object_name` from them, C raycasts against them. A
 * regex written out three times is three regexes that can disagree, and the
 * failure mode when they do — forty structures silently orphaned by a rename in
 * Blender — is the one handover 17 calls the most likely in the whole lane.
 *
 * The viewer does not import this. It never parses a node name: it receives
 * `modelObjectName` in the DTO and hands it to `getObjectByName`, so the string
 * is opaque on that side (docs/engineering.md §4). This module is build-time
 * only, which is why one copy is enough.
 */

/** Separates the organ from the structure. Two, so a slug's own `-` is safe. */
export const NODE_SEPARATOR = '__'

/**
 * A slug as the database stores one: lowercase, digits, single hyphens, and
 * neither a leading nor a trailing hyphen.
 *
 * Deliberately the same shape the `organs.slug` and `anatomical_structures.slug`
 * columns hold, so a node name is always reversible to a row.
 */
export const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export function isSlug(value) {
  return typeof value === 'string' && SLUG_PATTERN.test(value)
}

/**
 * Builds the node name for one structure, or throws.
 *
 * Throwing rather than sanitising is deliberate. A slug that needs cleaning up
 * is a slug that does not match the database row it is supposed to address, and
 * quietly repairing it here produces a model whose nodes look right and join to
 * nothing.
 */
export function structureNodeName(organSlug, structureSlug) {
  if (!isSlug(organSlug)) {
    throw new Error(
      `"${organSlug}" is not a usable organ slug. Node names are lowercase kebab-case ` +
        '(scripts/lib/structureNodes.mjs).',
    )
  }

  if (!isSlug(structureSlug)) {
    throw new Error(
      `"${structureSlug}" is not a usable structure slug. Node names are lowercase ` +
        'kebab-case, and the Latin term belongs in the database, not the mesh.',
    )
  }

  return `${organSlug}${NODE_SEPARATOR}${structureSlug}`
}

/** The inverse, or null if the name does not follow the convention. */
export function parseStructureNodeName(name) {
  if (typeof name !== 'string') return null

  const index = name.indexOf(NODE_SEPARATOR)

  if (index <= 0) return null

  const organSlug = name.slice(0, index)
  const structureSlug = name.slice(index + NODE_SEPARATOR.length)

  if (!isSlug(organSlug) || !isSlug(structureSlug)) return null

  return { organSlug, structureSlug }
}

/**
 * Every structure node in a glTF document, in scene order.
 *
 * Returns the names rather than the nodes: this is what a manifest row carries
 * and what Branch B seeds from, and handing back live glTF-Transform objects
 * would tie the manifest writer to the document's lifetime.
 */
export function listStructureNodes(document, organSlug) {
  const found = []

  for (const node of document.getRoot().listNodes()) {
    const parsed = parseStructureNodeName(node.getName())

    if (parsed === null) continue
    if (organSlug !== undefined && parsed.organSlug !== organSlug) continue

    found.push(node.getName())
  }

  return found
}

/**
 * Fails an export whose node names cannot be trusted.
 *
 * Three separate failures, reported together rather than one at a time, because
 * an export is fixed in Blender and a round trip per problem is a round trip too
 * many:
 *
 * - a node named for a different organ than the file claims to be
 * - the same structure named twice, which makes `getObjectByName` a coin toss
 * - a mesh node that follows no convention at all, which is either a stray from
 *   the source file or a structure that will silently never be selectable
 */
export function auditStructureNodes(document, organSlug) {
  const foreign = []
  const duplicates = []
  const unconventional = []
  const seen = new Set()

  for (const node of document.getRoot().listNodes()) {
    const name = node.getName()
    const parsed = parseStructureNodeName(name)

    if (parsed === null) {
      // A node with no mesh is scaffolding — the organ root, the normalisation
      // pivot — and is expected not to follow the convention.
      if (node.getMesh() !== null) unconventional.push(name)

      continue
    }

    if (parsed.organSlug !== organSlug) {
      foreign.push(name)

      continue
    }

    if (seen.has(name)) {
      duplicates.push(name)

      continue
    }

    seen.add(name)
  }

  return {
    ok: foreign.length === 0 && duplicates.length === 0 && unconventional.length === 0,
    foreign,
    duplicates,
    unconventional,
    structureNodes: [...seen],
  }
}
