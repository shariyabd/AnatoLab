/**
 * Fixtures for the viewer suite.
 *
 * The organ shape mirrors Handover 03's `OrganResource` through the frozen
 * `types.ts` contract, so a test that passes here is a test against the real
 * payload shape (docs/feature-plan.md §7.8).
 */

import { DataTexture, Group, Mesh, MeshStandardMaterial, RGBAFormat, SphereGeometry } from 'three'
import type { ModelLoader } from '../AssetManager'
import type { OrganDto, StructureDto } from '../types'
import fixtureManifest from '../../../../tests/Fixtures/models/manifest.json'

/**
 * A unit sphere: normalisation scales it to the FIT_SIZE cube, giving a radius of
 * FIT_SIZE / 2, and its vertex normals point straight out of the origin — which
 * is what makes surface snapping and the occlusion facing test checkable by hand.
 */
export function createFixtureModel(): Group {
  const texture = new DataTexture(new Uint8Array([220, 90, 80, 255]), 1, 1, RGBAFormat)
  texture.needsUpdate = true

  const material = new MeshStandardMaterial({ map: texture, roughness: 0.6 })
  const mesh = new Mesh(new SphereGeometry(1, 24, 16), material)
  mesh.name = 'tripo_mesh_fixture'

  const root = new Group()
  root.add(mesh)
  return root
}

/** Radius of the fixture model once normalised into the FIT_SIZE cube. */
export const FIXTURE_RADIUS = 1.9

export function createStructure(overrides: Partial<StructureDto> = {}): StructureDto {
  return {
    id: 'str_left_ventricle',
    slug: 'left-ventricle',
    name: 'Left ventricle',
    taTerm: 'Ventriculus sinister',
    scientificName: null,
    description: null,
    function: null,
    difficulty: 2,
    anchorPosition: [FIXTURE_RADIUS, 0, 0],
    modelObjectName: null,
    markerColor: null,
    ...overrides,
  }
}

export function createOrgan(overrides: Partial<OrganDto> = {}): OrganDto {
  return {
    id: 'org_heart',
    slug: 'heart',
    name: 'Heart',
    scientificName: 'Cor',
    description: null,
    modelUrl: '/models/heart.glb',
    modelFormat: 'glb',
    accentColor: '#d1584f',
    // Front, back, and top relative to the default camera, so the occlusion
    // facing test has something on each side to be right about.
    structures: [
      createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] }),
      createStructure({
        id: 'str_right_atrium',
        slug: 'right-atrium',
        name: 'Right atrium',
        anchorPosition: [0, 0, -FIXTURE_RADIUS],
      }),
      createStructure({
        id: 'str_aorta',
        slug: 'aorta',
        name: 'Aorta',
        anchorPosition: [0, FIXTURE_RADIUS, 0],
      }),
    ],
    ...overrides,
  }
}

export interface FixtureLoader extends ModelLoader {
  /** Every URL this loader was asked for, in order — proves cache and de-dup behaviour. */
  readonly requests: string[]
  /** Every model handed out, so a test can reach the single material each carries. */
  readonly models: Group[]
}

/**
 * A `ModelLoader` that resolves on a timer instead of over the network.
 *
 * `delayMs` exists so the in-flight de-duplication test has a window in which two
 * callers can overlap; `failWith` drives the failure-classification paths.
 */
export function createFixtureLoader(
  options: { delayMs?: number; failWith?: Error; createModel?: () => Group } = {},
): FixtureLoader {
  const requests: string[] = []
  const models: Group[] = []

  return {
    requests,
    models,
    load(url, onLoad, onProgress, onError) {
      requests.push(url)
      setTimeout(() => {
        if (options.failWith !== undefined) {
          onError?.(options.failWith)
          return
        }
        onProgress?.({ loaded: 512, total: 1024 } as ProgressEvent)
        const scene = (options.createModel ?? createFixtureModel)()
        models.push(scene)
        onLoad({ scene })
      }, options.delayMs ?? 0)
    },
  }
}

/*
|-------------------------------------------------------------------------------
| Per-structure fixtures — handover 17
|-------------------------------------------------------------------------------
|
| Everything above describes a single-mesh organ, which is what the audited
| assets are and what F04 was built against. Handover 17 replaces them with
| per-structure geometry, and these are what its tests run on until a licensed
| source is adopted.
|
| The node names, anchors and radius are read from the manifest that
| `scripts/make-structure-fixture.mjs` emits alongside the real fixture GLB, not
| retyped. That is the whole point: one generator produces both halves, so the
| in-memory Group a Vitest spec raycasts against and the GLB a Pest spec parses
| cannot describe different organs. `perStructureParity.test.ts` holds them to it.
|
| The import is a build-time JSON read, not a fetch, and `testing/` is not
| exported from index.ts, so nothing here reaches the shipped viewer
| (docs/engineering.md invariant 3).
*/

const FIXTURE_MODEL = fixtureManifest.models[0]

if (FIXTURE_MODEL === undefined) {
  throw new Error(
    'tests/Fixtures/models/manifest.json lists no models. Run `npm run models:fixture`.',
  )
}

/** What the generated fixture contains, as data a spec can assert against. */
export const PER_STRUCTURE_FIXTURE = {
  organSlug: FIXTURE_MODEL.organSlug,
  rootNode: FIXTURE_MODEL.rootNode,
  structureNodes: FIXTURE_MODEL.structureNodes as readonly string[],
  anchors: FIXTURE_MODEL.anchors as Readonly<Record<string, readonly number[]>>,
  radius: FIXTURE_MODEL.structureRadius,
} as const

/**
 * The per-structure organ as a scene graph: one root, one named mesh per
 * structure, one shared material.
 *
 * Mirrors the GLB exactly — including that the anchor lives in the node's
 * position rather than baked into the vertices, because that is what a real
 * Blender export produces and what makes
 * `scene.getObjectByName('heart__apex').position` mean something.
 *
 * One material for the whole organ, deliberately: handover 17 Branch A limits
 * per-structure material slots to sources that already provide them, and a test
 * that quietly used nine materials would not catch a highlight implementation
 * that mutates the shared one.
 */
export function createPerStructureFixtureModel(): Group {
  const material = new MeshStandardMaterial({ color: 0xc74849, roughness: 0.45 })
  const geometry = new SphereGeometry(PER_STRUCTURE_FIXTURE.radius, 12, 8)

  const root = new Group()
  root.name = PER_STRUCTURE_FIXTURE.rootNode

  for (const name of PER_STRUCTURE_FIXTURE.structureNodes) {
    const anchor = PER_STRUCTURE_FIXTURE.anchors[name]

    if (anchor === undefined) {
      throw new Error(`The fixture manifest lists node "${name}" with no anchor.`)
    }

    const mesh = new Mesh(geometry, material)
    mesh.name = name
    mesh.position.set(anchor[0] as number, anchor[1] as number, anchor[2] as number)

    root.add(mesh)
  }

  const scene = new Group()
  scene.add(root)

  return scene
}

/**
 * The `OrganDto` Branch B will produce for that model: every structure carries
 * both a `modelObjectName` and its `anchorPosition`.
 *
 * Both, not either. Handover 17 keeps the dot as the permanent fallback and
 * expects mixed-mode organs during migration, so a spec proving the fallback
 * still works needs a DTO it can null one field on — which is what
 * `withoutMeshIdentity` is for.
 */
export function createPerStructureOrgan(overrides: Partial<OrganDto> = {}): OrganDto {
  return createOrgan({
    structures: PER_STRUCTURE_FIXTURE.structureNodes.map((node) => {
      const slug = node.slice(node.indexOf('__') + 2)
      const anchor = PER_STRUCTURE_FIXTURE.anchors[node] as readonly number[]

      return createStructure({
        id: `str_${slug.replace(/-/g, '_')}`,
        slug,
        name: slug,
        modelObjectName: node,
        anchorPosition: [anchor[0] as number, anchor[1] as number, anchor[2] as number],
      })
    }),
    ...overrides,
  })
}

/** The same organ with one structure back on dot-only selection. */
export function withoutMeshIdentity(organ: OrganDto, structureSlug: string): OrganDto {
  return {
    ...organ,
    structures: organ.structures.map((structure) =>
      structure.slug === structureSlug ? { ...structure, modelObjectName: null } : structure,
    ),
  }
}
