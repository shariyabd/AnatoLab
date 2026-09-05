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
