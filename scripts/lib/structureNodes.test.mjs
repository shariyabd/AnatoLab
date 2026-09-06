import { describe, expect, it } from 'vitest'
import { Document } from '@gltf-transform/core'

import {
  NODE_SEPARATOR,
  auditStructureNodes,
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
