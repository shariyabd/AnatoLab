/**
 * Model loading, normalisation, caching, and disposal.
 *
 * Reimplemented from the technique description in docs/project-context.md §2.4
 * ("LRU (limit 3), in-flight de-dup, low-priority prefetch, full disposal") — the
 * upstream repository carries no licence (docs/licence-log.md §1) and the
 * grant-vs-replace decision in §4 is still *not yet taken*, so nothing was
 * copied. Techniques are not copyrightable (docs/licence-log.md §5); the code
 * below is ours.
 *
 * Three responsibilities, in order of importance:
 *
 * 1. **Normalisation.** Every model is scaled so its longest axis measures
 *    FIT_SIZE and centred on the origin *before* anything else sees it. Every
 *    `anchorPosition` in the database is authored in that space, so this is the
 *    single point where a model becomes addressable by stored coordinates
 *    (docs/architecture.md §5.4 rule 1).
 * 2. **Caching.** Three organs stay resident. Revisiting one inside a lesson is
 *    then free, and the fourth evicts the least recently used — bounded memory
 *    on a device we do not control (PRD §25).
 * 3. **Disposal.** Everything this class allocated, it frees.
 */

import {
  Box3,
  LinearSRGBColorSpace,
  Mesh,
  MeshStandardMaterial,
  Vector3,
  type Group,
  type Material,
  type Object3D,
  type Texture,
  type WebGLRenderer,
} from 'three'
import { FIT_SIZE } from './constants'
import { disposeObject3D } from './dispose'

/** How many organs stay resident. Matches the audited limit. */
const CACHE_LIMIT = 3

/**
 * The narrow slice of `GLTFLoader` this class uses.
 *
 * Declared as an interface rather than taken as a concrete `GLTFLoader` so the
 * Vitest suite can drive the whole cache, normalisation, and disposal path
 * without a network, a WASM decoder, or a real GLB on disk. Production callers
 * never supply one.
 */
export interface ModelLoader {
  load(
    url: string,
    onLoad: (gltf: { scene: Group }) => void,
    onProgress?: (event: ProgressEvent) => void,
    onError?: (error: unknown) => void,
  ): void
}

export interface AssetManagerOptions {
  /** From `renderer.capabilities.getMaxAnisotropy()`. 1 disables the filter. */
  readonly maxAnisotropy?: number
  /**
   * Needed only for KTX2. The asset pipeline (scripts/encode-model.mjs) can emit
   * either KTX2 or WebP textures and currently ships WebP, because no Basis
   * transcoder is deployed — `public/basis/` was deleted as 1.6 MB nothing
   * referenced (scripts/README.md). Supplying a path here turns KTX2 support on
   * without any other change.
   */
  readonly ktx2TranscoderPath?: string
  /** Required alongside `ktx2TranscoderPath`: KTX2 needs the GPU's format list. */
  readonly renderer?: WebGLRenderer
  /** Test seam; see `ModelLoader`. */
  readonly createLoader?: () => ModelLoader
}

/** A model that has been loaded, normalised, and conditioned. */
export interface LoadedOrganModel {
  readonly url: string
  /** Normalised into the FIT_SIZE cube and centred on the origin. */
  readonly root: Group
  readonly triangles: number
  /** Post-normalisation bounds, so callers do not recompute them. */
  readonly bounds: Box3
}

type ProgressHandler = (loaded: number, total: number) => void

interface CacheEntry {
  readonly model: LoadedOrganModel
  /** Retained entries are never evicted — the organ currently on screen. */
  retained: boolean
}

export class AnatomyAssetManager {
  /** Insertion order is the LRU order; a cache hit re-inserts at the end. */
  readonly #cache = new Map<string, CacheEntry>()
  /** In-flight de-duplication: two callers asking for one URL share one request. */
  readonly #inFlight = new Map<string, Promise<LoadedOrganModel>>()
  readonly #options: AssetManagerOptions
  #loader: ModelLoader | null = null
  #idleHandles = new Set<number>()
  #disposed = false

  constructor(options: AssetManagerOptions = {}) {
    this.#options = options
  }

  /**
   * Loads and normalises a model, or returns the cached one.
   *
   * `onProgress` fires only for a real network fetch; a cache hit or a joined
   * in-flight request reports nothing, because reporting a fake 0→100 would make
   * the progress bar lie about work that is not happening.
   */
  async load(url: string, onProgress?: ProgressHandler): Promise<LoadedOrganModel> {
    this.#assertUsable()

    const cached = this.#cache.get(url)
    if (cached !== undefined) {
      // Re-insert to mark most-recently-used.
      this.#cache.delete(url)
      this.#cache.set(url, cached)
      return cached.model
    }

    const existing = this.#inFlight.get(url)
    if (existing !== undefined) return existing

    const request = this.#fetchAndPrepare(url, onProgress)
      .then((model) => {
        if (this.#disposed) {
          // Disposal raced the response. Free it now rather than caching into a
          // manager nobody will ever call dispose() on again.
          disposeObject3D(model.root)
          throw new Error('Asset manager was disposed while loading.')
        }
        this.#cache.set(url, { model, retained: false })
        this.#evictOverflow()
        return model
      })
      .finally(() => {
        this.#inFlight.delete(url)
      })

    this.#inFlight.set(url, request)
    return request
  }

  /**
   * Warms the cache for a model the user has not asked for yet.
   *
   * Deliberately fire-and-forget and scheduled off the critical path: a prefetch
   * that competes with the organ the user is actually looking at is worse than
   * no prefetch. A failure here is silent by design — nothing is waiting on it,
   * and the real load will surface the same error with a real error path.
   */
  prefetch(url: string): void {
    if (this.#disposed) return
    if (this.#cache.has(url) || this.#inFlight.has(url)) return

    const handle = requestIdle(() => {
      this.#idleHandles.delete(handle)
      if (this.#disposed) return
      void this.load(url).catch(() => undefined)
    })
    this.#idleHandles.add(handle)
  }

  /** Protects the organ currently on screen from LRU eviction. */
  retain(url: string): void {
    const entry = this.#cache.get(url)
    if (entry !== undefined) entry.retained = true
  }

  release(url: string): void {
    const entry = this.#cache.get(url)
    if (entry !== undefined) entry.retained = false
    this.#evictOverflow()
  }

  /** Present for the cache tests; not part of the viewer's public surface. */
  get cachedUrls(): readonly string[] {
    return [...this.#cache.keys()]
  }

  dispose(): void {
    if (this.#disposed) return
    this.#disposed = true

    for (const handle of this.#idleHandles) cancelIdle(handle)
    this.#idleHandles.clear()

    for (const entry of this.#cache.values()) {
      disposeObject3D(entry.model.root)
    }
    this.#cache.clear()
    this.#loader = null
  }

  #assertUsable(): void {
    if (this.#disposed) throw new Error('Asset manager has been disposed.')
  }

  async #fetchAndPrepare(url: string, onProgress?: ProgressHandler): Promise<LoadedOrganModel> {
    const loader = await this.#resolveLoader()

    const scene = await new Promise<Group>((resolve, reject) => {
      loader.load(
        url,
        (gltf) => resolve(gltf.scene),
        (event) => onProgress?.(event.loaded, event.total),
        (error) => reject(toLoadError(error)),
      )
    })

    normaliseIntoFitCube(scene)
    this.#conditionMaterials(scene)

    return {
      url,
      root: scene,
      triangles: countTriangles(scene),
      bounds: new Box3().setFromObject(scene),
    }
  }

  async #resolveLoader(): Promise<ModelLoader> {
    if (this.#loader !== null) return this.#loader

    const injected = this.#options.createLoader?.()
    if (injected !== undefined) {
      this.#loader = injected
      return injected
    }

    // Dynamic import: the Meshopt WASM blob is a real download, and a page that
    // never loads a model should never pay for it. See gltfLoader.ts.
    const { createGltfLoader } = await import('./gltfLoader')
    const loader = await createGltfLoader({
      ktx2TranscoderPath: this.#options.ktx2TranscoderPath,
      renderer: this.#options.renderer,
    })
    this.#loader = loader
    return loader
  }

  #conditionMaterials(root: Object3D): void {
    const anisotropy = this.#options.maxAnisotropy ?? 1
    const seen = new Set<Material>()

    root.traverse((object) => {
      if (!(object instanceof Mesh)) return

      // Every model is one baked surface shell (docs/project-context.md §2.2),
      // so shadow casting buys a self-shadowing artefact and nothing else. The
      // contact shadow under the plinth is baked instead.
      object.castShadow = false
      object.receiveShadow = false
      object.frustumCulled = true

      const materials = Array.isArray(object.material) ? object.material : [object.material]
      for (const material of materials) {
        if (seen.has(material)) continue
        seen.add(material)
        conditionMaterial(material, anisotropy)
      }
    })
  }

  #evictOverflow(): void {
    while (this.#cache.size > CACHE_LIMIT) {
      const victim = [...this.#cache.entries()].find(([, entry]) => !entry.retained)
      // Every resident organ is retained: refuse to evict rather than free
      // geometry that is still in a live scene graph.
      if (victim === undefined) return

      const [url, entry] = victim
      this.#cache.delete(url)
      disposeObject3D(entry.model.root)
    }
  }
}

/**
 * Scales the model so its longest axis measures FIT_SIZE and centres it on the
 * origin.
 *
 * The asset pipeline already does this at encode time (scripts/README.md), so for
 * a pipeline-produced model the scale factor is 1 and the translation is zero.
 * It runs anyway: a placeholder model, a hand-dropped GLB, or a replacement
 * source (docs/asset-register.md §5) has not been through the pipeline, and a
 * model that skips normalisation invalidates every authored coordinate against
 * it (docs/architecture.md §5.4 rule 1).
 */
export function normaliseIntoFitCube(root: Object3D): void {
  root.updateWorldMatrix(true, true)

  const bounds = new Box3().setFromObject(root)
  if (bounds.isEmpty()) return

  const size = bounds.getSize(new Vector3())
  const longestAxis = Math.max(size.x, size.y, size.z)
  if (longestAxis <= 0) return

  const scale = FIT_SIZE / longestAxis
  const centre = bounds.getCenter(new Vector3())

  root.scale.multiplyScalar(scale)
  // Centre after scaling: the offset is measured in the pre-scale space, so it
  // has to be scaled by the same factor to land on the origin.
  root.position.sub(centre.multiplyScalar(scale))
  root.updateWorldMatrix(true, true)
}

export function countTriangles(root: Object3D): number {
  let triangles = 0
  root.traverse((object) => {
    if (!(object instanceof Mesh)) return
    const geometry = object.geometry
    const index = geometry.getIndex()
    const position = geometry.getAttribute('position')
    if (index !== null) {
      triangles += index.count / 3
    } else if (position !== undefined) {
      triangles += position.count / 3
    }
  })
  return Math.round(triangles)
}

function conditionMaterial(material: Material, anisotropy: number): void {
  for (const value of Object.values(material)) {
    if (isTexture(value)) {
      // Anisotropy on *every* sampled map, not just the colour map: a normal or
      // roughness map sampled at a grazing angle shimmers just as badly, and
      // these models are viewed at grazing angles constantly because the camera
      // orbits a closed surface.
      value.anisotropy = anisotropy
      value.needsUpdate = true
    }
  }

  if (material instanceof MeshStandardMaterial) {
    // The models carry a single baked colour/normal/roughness set
    // (docs/project-context.md §2.2). Let the room environment do the lighting
    // rather than fighting it with a strong specular response.
    material.envMapIntensity = 1
    material.flatShading = false
    if (material.aoMap !== null) material.aoMapIntensity = 1
    if (material.lightMap !== null) material.lightMap.colorSpace = LinearSRGBColorSpace
    material.needsUpdate = true
  }
}

function isTexture(value: unknown): value is Texture {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { isTexture?: boolean }).isTexture === true
  )
}

function toLoadError(error: unknown): Error {
  if (error instanceof Error) return error
  return new Error(typeof error === 'string' ? error : 'Model failed to load.')
}

interface IdleWindow {
  requestIdleCallback?: (callback: () => void, options?: { timeout: number }) => number
  cancelIdleCallback?: (handle: number) => void
}

/**
 * `requestIdleCallback` where it exists, a timer where it does not (Safari
 * shipped it only in 2022 and jsdom has never had it). The timeout guarantees a
 * prefetch that is never idle still happens eventually.
 */
function requestIdle(callback: () => void): number {
  const idle = globalThis as IdleWindow
  if (typeof idle.requestIdleCallback === 'function') {
    return idle.requestIdleCallback(callback, { timeout: 2_000 })
  }
  return globalThis.setTimeout(callback, 200) as unknown as number
}

function cancelIdle(handle: number): void {
  const idle = globalThis as IdleWindow
  if (typeof idle.cancelIdleCallback === 'function') {
    idle.cancelIdleCallback(handle)
    return
  }
  globalThis.clearTimeout(handle)
}
