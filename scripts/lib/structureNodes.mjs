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

import { PIVOT_NODE_NAME } from './normalise.mjs'

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

/**
 * Parent of every node reachable from the scene, so a subtree question can be
 * answered without walking the whole document per node.
 *
 * Scene roots map to `null` rather than being absent, so "has no parent" and
 * "is not in the scene at all" stay distinguishable — an orphaned node is a
 * node the renderer never sees, and it should not pass as a grouped structure.
 */
function parentsByNode(document) {
  const root = document.getRoot()
  const scene = root.getDefaultScene() ?? root.listScenes()[0]
  const parents = new Map()

  if (!scene) return parents

  const walk = (node) => {
    for (const child of node.listChildren()) {
      parents.set(child, node)
      walk(child)
    }
  }

  for (const child of scene.listChildren()) {
    parents.set(child, null)
    walk(child)
  }

  return parents
}

/** Ancestors of a node, nearest first. */
function ancestry(node, parents) {
  const chain = []

  for (let current = parents.get(node) ?? null; current; current = parents.get(current) ?? null) {
    chain.push(current)
  }

  return chain
}

/**
 * The deepest node every given node hangs beneath, or null if they do not share
 * one.
 *
 * Nearest common *ancestor* rather than a shared parent: an export is allowed to
 * group structures further — `heart` → `heart-valves` → the valve meshes is a
 * perfectly reasonable Blender outliner, and an empty grouping node carries no
 * mesh, so it is invisible to the naming audit. What matters is that one node
 * exists above all of them to transform the organ as a unit.
 */
export function nearestCommonAncestor(nodes, parents) {
  if (nodes.length === 0) return null

  const [first, ...rest] = nodes.map((node) => ancestry(node, parents))

  for (const candidate of first) {
    if (rest.every((chain) => chain.includes(candidate))) return candidate
  }

  return null
}

/**
 * Everything the pipeline needs to know about an export's structure identity,
 * and every reason it is not shippable — handover 17 Branch A.
 *
 * Two questions, answered together because the answer to the second depends on
 * the first: is this a per-structure export at all, and if so does it hold up?
 *
 * The trigger is deliberately not a flag. A file is judged as a per-structure
 * export when it either names structures or carries more than one mesh node,
 * which means an export whose node names were *all* mangled in Blender fails
 * here rather than sailing through as "single-mesh, nothing to check" — that
 * silent pass is the failure this whole audit exists to prevent. A genuine
 * single-mesh organ has exactly one mesh node and claims nothing, so it is
 * exempt, which is what keeps the nine Tripo models encodable during migration.
 *
 * @returns {{
 *   perStructure: boolean,
 *   rootNode: string | null,
 *   structureNodes: string[],
 *   meshNodes: number,
 *   materials: number,
 *   failures: string[],
 *   warnings: string[],
 * }}
 */
export function describeStructureExport(document, organSlug) {
  const root = document.getRoot()
  const meshNodes = root.listNodes().filter((node) => node.getMesh() !== null)
  const materials = root.listMaterials().length
  const audit = auditStructureNodes(document, organSlug)

  if (audit.structureNodes.length === 0 && meshNodes.length <= 1) {
    return {
      perStructure: false,
      rootNode: null,
      structureNodes: [],
      meshNodes: meshNodes.length,
      materials,
      failures: [],
      warnings: [],
    }
  }

  const failures = []
  const warnings = []

  if (audit.foreign.length > 0) {
    failures.push(
      `${audit.foreign.length} node(s) are named for another organ: ` +
        `${audit.foreign.join(', ')}. This model is "${organSlug}".`,
    )
  }

  if (audit.duplicates.length > 0) {
    failures.push(
      `${audit.duplicates.join(', ')} appears more than once. getObjectByName returns ` +
        'whichever comes first, so the structure would highlight at random.',
    )
  }

  if (audit.unconventional.length > 0) {
    failures.push(
      `${audit.unconventional.length} mesh node(s) follow no naming convention: ` +
        `${audit.unconventional.join(', ')}. In a per-structure export every mesh must be ` +
        `named <organ-slug>${NODE_SEPARATOR}<structure-slug>; one that is not can never be ` +
        'selected, hovered, or isolated.',
    )
  }

  const parents = parentsByNode(document)
  // This organ's own structures only. A foreign node is already reported above,
  // and letting one drag the common ancestor up to the scene root would bury
  // that message under a second, wronger one about grouping.
  const structureNodeObjects = root
    .listNodes()
    .filter((node) => parseStructureNodeName(node.getName())?.organSlug === organSlug)
  const organRoot = nearestCommonAncestor(structureNodeObjects, parents)

  // Skipped when none of this organ's structures are present: there is nothing
  // to group, the reason is already reported above, and a second failure about
  // a missing root would only bury it.
  if (structureNodeObjects.length > 0) {
    if (organRoot === null) {
      failures.push(
        'The structure nodes share no common ancestor, so the organ has no root node and ' +
          'cannot transform as a unit. Parent them all under one node in Blender.',
      )
    } else if (organRoot.getName() === PIVOT_NODE_NAME) {
      failures.push(
        'The structures hang directly off the normalisation pivot, so there is no organ ' +
          `root node. Group them under a single node — conventionally "${organSlug}" — ` +
          'before exporting; FIT_SIZE normalisation applies to that root, not per structure.',
      )
    } else if (organRoot.getName() === '') {
      failures.push(
        'The organ root node is unnamed, so the manifest has nothing to record and nothing ' +
          `downstream can address it. Name it "${organSlug}".`,
      )
    }
  }

  // Not a budget — §15.1 states none for materials — but an internal
  // contradiction worth saying out loud. Per-structure material slots come from
  // the source; more slots than structures means they are not per-structure at
  // all, and every one of them is a draw call.
  if (materials > audit.structureNodes.length) {
    warnings.push(
      `${materials} materials for ${audit.structureNodes.length} structures. One shared ` +
        'material per organ is the default; each extra slot is another draw call.',
    )
  }

  return {
    perStructure: true,
    rootNode: organRoot?.getName() || null,
    structureNodes: audit.structureNodes,
    meshNodes: meshNodes.length,
    materials,
    failures,
    warnings,
  }
}

/**
 * What a manifest row promises against what a file actually contains.
 *
 * Both directions matter and they are different failures. A *missing* node is a
 * structure whose `model_object_name` was seeded from the manifest and now
 * addresses nothing — the orphaning handover 17 calls the most likely failure in
 * the lane. An *extra* node is a structure the model could teach and the
 * database will never know about, because the seeder reads the manifest, not the
 * GLB.
 */
export function diffStructureNodes(promised, found) {
  const onDisk = new Set(found)
  const recorded = new Set(promised)

  return {
    missing: promised.filter((name) => !onDisk.has(name)),
    extra: found.filter((name) => !recorded.has(name)),
  }
}
