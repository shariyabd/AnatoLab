import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  Box3,
  BoxGeometry,
  Group,
  Mesh,
  MeshPhysicalMaterial,
  MeshStandardMaterial,
  SphereGeometry,
  Vector3,
} from 'three'
import { AnatomyAssetManager, countTriangles, normaliseIntoFitCube } from './AssetManager'
import { FIT_SIZE } from './constants'
import { createFixtureLoader, createFixtureModel } from './testing/fixtures'

describe('normaliseIntoFitCube', () => {
  it('scales the longest axis to FIT_SIZE and centres the model on the origin', () => {
    // Every anchor_position in the database is authored in this space
    // (docs/architecture.md §5.4 rule 1), so this is the load-bearing assertion
    // of the whole asset path.
    const root = new Group()
    const mesh = new Mesh(new BoxGeometry(10, 4, 2))
    mesh.position.set(50, 20, 5)
    root.add(mesh)

    normaliseIntoFitCube(root)

    const bounds = new Box3().setFromObject(root)
    const size = bounds.getSize(new Vector3())
    const centre = bounds.getCenter(new Vector3())

    expect(Math.max(size.x, size.y, size.z)).toBeCloseTo(FIT_SIZE, 5)
    expect(centre.length()).toBeCloseTo(0, 5)
  })

  it('is idempotent, so a model the pipeline already normalised is untouched', () => {
    const root = new Group()
    root.add(new Mesh(new BoxGeometry(FIT_SIZE, 1, 1)))

    normaliseIntoFitCube(root)
    const first = root.scale.x
    normaliseIntoFitCube(root)

    expect(root.scale.x).toBeCloseTo(first, 6)
    expect(first).toBeCloseTo(1, 6)
  })

  it('leaves an empty subtree alone rather than dividing by zero', () => {
    const root = new Group()
    expect(() => normaliseIntoFitCube(root)).not.toThrow()
    expect(root.scale.x).toBe(1)
  })
})

describe('countTriangles', () => {
  it('counts indexed and non-indexed geometry alike', () => {
    const root = new Group()
    root.add(new Mesh(new BoxGeometry()))
    expect(countTriangles(root)).toBe(12)
  })
})

describe('AnatomyAssetManager', () => {
  let manager: AnatomyAssetManager

  beforeEach(() => {
    manager = new AnatomyAssetManager()
  })

  it('normalises and measures what it loads', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const model = await manager.load('/models/heart.glb')

    const size = model.bounds.getSize(new Vector3())
    expect(Math.max(size.x, size.y, size.z)).toBeCloseTo(FIT_SIZE, 4)
    expect(model.triangles).toBeGreaterThan(0)
    expect(model.url).toBe('/models/heart.glb')
  })

  it('serves a second request for the same URL from cache', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const first = await manager.load('/models/heart.glb')
    const second = await manager.load('/models/heart.glb')

    expect(second).toBe(first)
    expect(loader.requests).toEqual(['/models/heart.glb'])
  })

  it('de-duplicates two overlapping requests into one fetch', async () => {
    const loader = createFixtureLoader({ delayMs: 5 })
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const [first, second] = await Promise.all([
      manager.load('/models/heart.glb'),
      manager.load('/models/heart.glb'),
    ])

    expect(second).toBe(first)
    expect(loader.requests).toEqual(['/models/heart.glb'])
  })

  it('evicts the least recently used model beyond the limit of three', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    for (const slug of ['heart', 'brain', 'lungs']) await manager.load(`/models/${slug}.glb`)
    // Touch the heart so the brain becomes least-recently-used.
    await manager.load('/models/heart.glb')
    await manager.load('/models/liver.glb')

    expect(manager.cachedUrls).toEqual([
      '/models/lungs.glb',
      '/models/heart.glb',
      '/models/liver.glb',
    ])
  })

  it('frees the geometry of an evicted model', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const evicted = await manager.load('/models/heart.glb')
    const mesh = evicted.root.children[0] as Mesh
    const spy = vi.spyOn(mesh.geometry, 'dispose')

    for (const slug of ['brain', 'lungs', 'liver']) await manager.load(`/models/${slug}.glb`)

    expect(spy).toHaveBeenCalledOnce()
    expect(manager.cachedUrls).not.toContain('/models/heart.glb')
  })

  it('never evicts a retained model, even past the limit', async () => {
    // The organ on screen: freeing its geometry would blank a live scene graph.
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    await manager.load('/models/heart.glb')
    manager.retain('/models/heart.glb')
    for (const slug of ['brain', 'lungs', 'liver', 'kidneys']) {
      await manager.load(`/models/${slug}.glb`)
    }

    expect(manager.cachedUrls).toContain('/models/heart.glb')
    expect(manager.cachedUrls).toHaveLength(3)

    // Releasing does not evict on its own — it only makes the model a candidate
    // again, so the next load is what pushes it out.
    manager.release('/models/heart.glb')
    await manager.load('/models/pancreas.glb')
    expect(manager.cachedUrls).not.toContain('/models/heart.glb')
  })

  it('applies anisotropy to every sampled map, not only the colour map', async () => {
    const loader = createFixtureLoader({
      createModel: () => {
        const model = createFixtureModel()
        const mesh = model.children[0] as Mesh<BoxGeometry, MeshStandardMaterial>
        mesh.material.roughnessMap = mesh.material.map
        return model
      },
    })
    manager = new AnatomyAssetManager({ createLoader: () => loader, maxAnisotropy: 8 })

    const model = await manager.load('/models/heart.glb')
    const material = (model.root.children[0] as Mesh<BoxGeometry, MeshStandardMaterial>).material

    expect(material.map?.anisotropy).toBe(8)
    expect(material.roughnessMap?.anisotropy).toBe(8)
  })

  it('re-homes the baked material onto a physical one so tissue can look wet', async () => {
    // Handover 15 phase 6. The models ship a matte-preview roughness map and no
    // clearcoat, which against the room environment reads as dry plastic.
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const model = await manager.load('/models/heart.glb')
    const material = (model.root.children[0] as Mesh<SphereGeometry, MeshPhysicalMaterial>).material

    expect(material).toBeInstanceOf(MeshPhysicalMaterial)
    expect(material.roughness).toBeCloseTo(0.45, 5)
    expect(material.clearcoat).toBeCloseTo(0.25, 5)
    expect(material.clearcoatRoughness).toBeCloseTo(0.4, 5)
  })

  it('keeps the PHYSICAL define, without which the clearcoat would compile away', async () => {
    // `MeshStandardMaterial.copy` overwrites `defines` with `{ STANDARD: '' }`.
    // Miss that and the material silently renders as a standard one: no error,
    // no clearcoat, and nothing to see except a slightly duller organ.
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const model = await manager.load('/models/heart.glb')
    const material = (model.root.children[0] as Mesh<SphereGeometry, MeshPhysicalMaterial>).material

    expect(material.defines).toMatchObject({ STANDARD: '', PHYSICAL: '' })
  })

  it('carries the baked maps across to the physical material', async () => {
    // The maps are the only anatomical information these single-mesh models
    // have (docs/project-context.md §2.2). Losing them in the upgrade would
    // leave a correctly-lit blank shell.
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })
    const before = loader.models

    const model = await manager.load('/models/heart.glb')
    const material = (model.root.children[0] as Mesh<SphereGeometry, MeshPhysicalMaterial>).material

    expect(before).toHaveLength(1)
    expect(material.map).not.toBeNull()
    expect(material.map?.isTexture).toBe(true)
  })

  it('leaves two primitives that shared a material sharing one', async () => {
    // glTF shares a material between primitives far more often than a geometry.
    // Upgrading per mesh instead of per material would double the shader
    // programs and the texture bindings for no visible difference.
    const loader = createFixtureLoader({
      createModel: () => {
        const model = createFixtureModel()
        const first = model.children[0] as Mesh<SphereGeometry, MeshStandardMaterial>
        const second = new Mesh(new SphereGeometry(1, 8, 6), first.material)
        model.add(second)
        return model
      },
    })
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const model = await manager.load('/models/heart.glb')
    const [first, second] = model.root.children as Mesh<SphereGeometry, MeshPhysicalMaterial>[]

    expect(first!.material).toBeInstanceOf(MeshPhysicalMaterial)
    expect(second!.material).toBe(first!.material)
  })

  it('reports load progress', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })
    const onProgress = vi.fn()

    await manager.load('/models/heart.glb', onProgress)

    expect(onProgress).toHaveBeenCalledWith(512, 1024)
  })

  it('rejects with the loader error rather than swallowing it', async () => {
    const loader = createFixtureLoader({ failWith: new Error('404 Not Found') })
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    await expect(manager.load('/models/missing.glb')).rejects.toThrow('404 Not Found')
  })

  it('prefetches at idle priority without blocking the caller', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    manager.prefetch('/models/brain.glb')
    expect(loader.requests).toEqual([])

    await vi.waitFor(() => expect(manager.cachedUrls).toContain('/models/brain.glb'))
  })

  it('does not prefetch something already cached', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    await manager.load('/models/heart.glb')
    manager.prefetch('/models/heart.glb')

    await new Promise((resolve) => setTimeout(resolve, 250))
    expect(loader.requests).toEqual(['/models/heart.glb'])
  })

  it('frees every cached model on dispose and refuses further loads', async () => {
    const loader = createFixtureLoader()
    manager = new AnatomyAssetManager({ createLoader: () => loader })

    const model = await manager.load('/models/heart.glb')
    const spy = vi.spyOn((model.root.children[0] as Mesh).geometry, 'dispose')

    manager.dispose()

    expect(spy).toHaveBeenCalledOnce()
    expect(manager.cachedUrls).toEqual([])
    await expect(manager.load('/models/heart.glb')).rejects.toThrow('disposed')
  })
})
