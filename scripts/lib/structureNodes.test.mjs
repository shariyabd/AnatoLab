import { describe, expect, it } from 'vitest'
import { Document } from '@gltf-transform/core'

import { PIVOT_NODE_NAME } from './normalise.mjs'
import {
  NODE_SEPARATOR,
  auditStructureNodes,
  describeStructureExport,
  diffStructureNodes,
  isSlug,
  listStructureNodes,
  parseStructureNodeName,
  structureNodeName,
} from './structureNodes.mjs'

/**
 * The naming convention handover 17 Branch A must "establish in the first commit
 * and never deviate" from. These are what "never deviate" looks like when a
 * machine enforces it.
 */
describe('structureNodeName', () => {
  it('joins an organ and a structure the one agreed way', () => {
    expect(structureNodeName('heart', 'left-ventricle')).toBe('heart__left-ventricle')
    expect(NODE_SEPARATOR).toBe('__')
  })

  it('refuses a Latin term rather than sanitising it', () => {
    // The TA term lives in the database, not the mesh. Quietly slugifying it
    // here would produce a node that looks right and joins to no row.
    expect(() => structureNodeName('heart', 'Ventriculus sinister')).toThrow(/kebab-case/)
  })

  it('refuses the shapes a hand-typed slug actually takes', () => {
    expect(() => structureNodeName('Heart', 'aorta')).toThrow()
    expect(() => structureNodeName('heart', 'left_ventricle')).toThrow()
    expect(() => structureNodeName('heart', '-aorta')).toThrow()
    expect(() => structureNodeName('heart', 'aorta-')).toThrow()
    expect(() => structureNodeName('heart', 'left--ventricle')).toThrow()
    expect(() => structureNodeName('heart', '')).toThrow()
  })

  it('accepts the slugs the seeder actually holds', () => {
    for (const slug of ['aorta', 'left-ventricle', 'superior-vena-cava', 'stratum-corneum']) {
      expect(isSlug(slug)).toBe(true)
    }
  })
})

describe('parseStructureNodeName', () => {
  it('round-trips', () => {
    expect(parseStructureNodeName(structureNodeName('heart', 'superior-vena-cava'))).toEqual({
      organSlug: 'heart',
      structureSlug: 'superior-vena-cava',
    })
  })

  it('splits on the first separator, so a structure slug keeps its own hyphens', () => {
    expect(parseStructureNodeName('heart__left-ventricle')?.structureSlug).toBe('left-ventricle')
  })

  it('returns null for a name that follows no convention', () => {
    // The single-mesh case: this is exactly what the upstream nodes look like,
    // and treating one as a structure is how a raycast starts returning "the
    // heart" for every hit.
    expect(parseStructureNodeName('tripo_node_9c16954f')).toBeNull()
    expect(parseStructureNodeName('heart')).toBeNull()
    expect(parseStructureNodeName('__aorta')).toBeNull()
    expect(parseStructureNodeName('heart__')).toBeNull()
    expect(parseStructureNodeName(undefined)).toBeNull()
  })
})

/** A document with one mesh node per given name, plus an unnamed root. */
function documentWith(names, { extraMeshNode } = {}) {
  const document = new Document()
  const scene = document.createScene()
  const root = document.createNode('heart')
  scene.addChild(root)

  const mesh = document.createMesh('shared')

  for (const name of names) {
    root.addChild(document.createNode(name).setMesh(mesh))
  }

  if (extraMeshNode !== undefined) {
    root.addChild(document.createNode(extraMeshNode).setMesh(mesh))
  }

  return document
}

describe('auditStructureNodes', () => {
  it('passes a clean export and lists what it found', () => {
    const audit = auditStructureNodes(
      documentWith(['heart__aorta', 'heart__left-ventricle']),
      'heart',
    )

    expect(audit.ok).toBe(true)
    expect(audit.structureNodes).toEqual(['heart__aorta', 'heart__left-ventricle'])
  })

  it('catches a node named for a different organ', () => {
    const audit = auditStructureNodes(documentWith(['heart__aorta', 'lungs__trachea']), 'heart')

    expect(audit.ok).toBe(false)
    expect(audit.foreign).toEqual(['lungs__trachea'])
  })

  it('catches a mesh node following no convention', () => {
    // The stray that would otherwise ship as a structure nobody can ever select.
    const audit = auditStructureNodes(
      documentWith(['heart__aorta'], { extraMeshNode: 'Cube.003' }),
      'heart',
    )

    expect(audit.ok).toBe(false)
    expect(audit.unconventional).toEqual(['Cube.003'])
  })

  it('does not mind scaffolding nodes that carry no mesh', () => {
    // The organ root and the normalisation pivot are both like this.
    const document = documentWith(['heart__aorta'])
    document.createNode('anatolab_normalised_pivot')

    expect(auditStructureNodes(document, 'heart').ok).toBe(true)
  })

  it('filters by organ when listing', () => {
    const document = documentWith(['heart__aorta', 'lungs__trachea'])

    expect(listStructureNodes(document, 'heart')).toEqual(['heart__aorta'])
    expect(listStructureNodes(document)).toHaveLength(2)
  })
})

/**
 * A per-structure export as Blender produces one: mesh nodes parented under a
 * single organ root, inside a scene.
 *
 * `documentWith` above builds the same shape; this returns the pieces too, so a
 * test can re-parent one node and ask what the pipeline then says.
 */
function exportedDocument(names, { rootName = 'heart', materials = 1 } = {}) {
  const document = new Document()
  const scene = document.createScene()
  const organRoot = document.createNode(rootName)
  scene.addChild(organRoot)

  const mesh = document.createMesh('shared')
  const nodes = new Map()

  for (const name of names) {
    const node = document.createNode(name).setMesh(mesh)
    organRoot.addChild(node)
    nodes.set(name, node)
  }

  for (let index = 0; index < materials; index += 1) {
    document.createMaterial(`material-${index}`)
  }

  return { document, scene, organRoot, nodes }
}

describe('describeStructureExport', () => {
  it('reports a clean per-structure export and the root it hangs from', () => {
    const { document } = exportedDocument(['heart__aorta', 'heart__left-ventricle'])

    const described = describeStructureExport(document, 'heart')

    expect(described).toMatchObject({
      perStructure: true,
      rootNode: 'heart',
      structureNodes: ['heart__aorta', 'heart__left-ventricle'],
      meshNodes: 2,
      materials: 1,
      failures: [],
      warnings: [],
    })
  })

  it('exempts a genuine single-mesh organ, which is what the nine Tripo models are', () => {
    // The migration state handover 17 explicitly expects to keep working. One
    // mesh node, named nothing in particular, claiming no structures.
    const document = new Document()
    const scene = document.createScene()
    scene.addChild(document.createNode('tripo_node_9c16954f').setMesh(document.createMesh('m')))

    expect(describeStructureExport(document, 'heart')).toMatchObject({
      perStructure: false,
      rootNode: null,
      structureNodes: [],
      failures: [],
    })
  })

  it('fails an export whose names were all mangled rather than passing it as single-mesh', () => {
    // The silent pass this whole audit exists to prevent: rename the objects in
    // Blender, export, and every structure is unselectable — but nothing claims
    // to be a structure, so a naming-only audit would find nothing to complain
    // about. More than one mesh node is what makes it a per-structure export.
    const { document } = exportedDocument(['Cube.001', 'Cube.002', 'Cube.003'])

    const described = describeStructureExport(document, 'heart')

    expect(described.perStructure).toBe(true)
    expect(described.structureNodes).toEqual([])
    expect(described.failures.join(' ')).toMatch(/follow no naming convention/)
  })

  it('fails structures that were never grouped under an organ root', () => {
    // FIT_SIZE normalisation applies to the root. Without one, the pivot ends up
    // wrapping the structures directly and the organ has no single transform.
    const { document, scene, organRoot, nodes } = exportedDocument(['heart__aorta', 'heart__apex'])

    for (const node of nodes.values()) {
      organRoot.removeChild(node)
      scene.addChild(node)
    }

    expect(describeStructureExport(document, 'heart').failures.join(' ')).toMatch(
      /share no common ancestor/,
    )
  })

  it('fails structures parented straight onto the normalisation pivot', () => {
    const { document } = exportedDocument(['heart__aorta', 'heart__apex'], {
      rootName: PIVOT_NODE_NAME,
    })

    expect(describeStructureExport(document, 'heart').failures.join(' ')).toMatch(
      /no organ root node/,
    )
  })

  it('accepts a deeper outliner, because grouping further is still grouping', () => {
    // heart → heart-valves → the valve meshes. An empty grouping node carries no
    // mesh, so the naming audit never sees it; requiring a shared *parent*
    // rather than a shared ancestor would fail a perfectly good export.
    const { document, organRoot, nodes } = exportedDocument(['heart__aorta', 'heart__mitral-valve'])
    const valves = document.createNode('valves')
    organRoot.addChild(valves)

    const valve = nodes.get('heart__mitral-valve')
    organRoot.removeChild(valve)
    valves.addChild(valve)

    expect(describeStructureExport(document, 'heart')).toMatchObject({
      rootNode: 'heart',
      failures: [],
    })
  })

  it('names the organ it was asked about when a node belongs to another', () => {
    const { document } = exportedDocument(['heart__aorta', 'lungs__trachea'])

    const failures = describeStructureExport(document, 'heart').failures.join(' ')

    expect(failures).toMatch(/named for another organ/)
    expect(failures).toMatch(/lungs__trachea/)
  })

  it('warns, but does not fail, on more material slots than structures', () => {
    // Not a budget — §15.1 states none for materials — so it cannot fail a run.
    // It is an internal contradiction worth saying out loud, because each slot
    // is a draw call and per-structure slots cannot outnumber the structures.
    const { document } = exportedDocument(['heart__aorta'], { materials: 4 })

    const described = describeStructureExport(document, 'heart')

    expect(described.failures).toEqual([])
    expect(described.warnings.join(' ')).toMatch(/4 materials for 1 structures/)
  })
})

describe('diffStructureNodes', () => {
  it('says nothing when the file is what the manifest promised', () => {
    expect(diffStructureNodes(['heart__aorta'], ['heart__aorta'])).toEqual({
      missing: [],
      extra: [],
    })
  })

  it('reports a node the manifest promises and the file has lost', () => {
    // The orphan: model_object_name was seeded from this list, so the structure
    // now names a mesh that is not in the file.
    expect(diffStructureNodes(['heart__aorta', 'heart__apex'], ['heart__aorta'])).toMatchObject({
      missing: ['heart__apex'],
      extra: [],
    })
  })

  it('reports a node the file gained without a re-encode', () => {
    // Not an orphan, but not harmless either: the seeder reads the manifest, so
    // this structure would stay a dot on a model that can render it properly.
    expect(diffStructureNodes(['heart__aorta'], ['heart__aorta', 'heart__apex'])).toMatchObject({
      missing: [],
      extra: ['heart__apex'],
    })
  })

  it('is order-insensitive, because a manifest is sorted and a scene is not', () => {
    expect(
      diffStructureNodes(['heart__apex', 'heart__aorta'], ['heart__aorta', 'heart__apex']),
    ).toEqual({ missing: [], extra: [] })
  })
})
