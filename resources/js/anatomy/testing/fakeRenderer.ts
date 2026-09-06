/**
 * A `WebGLRenderer` stand-in that keeps three's own GPU-memory bookkeeping.
 *
 * jsdom has no WebGL, so the real renderer cannot be constructed and the
 * disposal test — the one docs/architecture.md §5.4 rule 6 requires — could not
 * run against the real scene-graph, hotspot, and material code at all.
 *
 * The counting here is not an approximation. `WebGLGeometries.get()` registers a
 * geometry the first time it is drawn, adds a `dispose` listener, and increments
 * `info.memory.geometries`; the listener decrements it
 * (three/src/renderers/webgl/WebGLGeometries.js). `WebGLTextures` does the same
 * for textures. This class reproduces exactly that, so "geometries and textures
 * return to zero" means the same thing here as it does in a browser: every
 * resource that reached the GPU had `dispose()` called on it.
 */

import {
  Line,
  Mesh,
  Points,
  Sprite,
  type BufferGeometry,
  type Camera,
  type Material,
  type Object3D,
  type Scene,
  type Texture,
  type WebGLRenderer,
} from 'three'

/** Live instances, so a test can assert the renderer itself was released. */
export const liveRenderers = { count: 0 }

interface Disposable {
  addEventListener(type: 'dispose', listener: () => void): void
}

export class FakeWebGLRenderer {
  readonly domElement: HTMLCanvasElement
  readonly info = {
    memory: { geometries: 0, textures: 0 },
    render: { calls: 0, triangles: 0, frame: 0 },
  }
  readonly capabilities = { getMaxAnisotropy: (): number => 8, isWebGL2: true }
  readonly shadowMap = { enabled: false }

  outputColorSpace = ''
  toneMapping = 0
  toneMappingExposure = 1
  localClippingEnabled = false
  contextLost = false
  disposed = false
  scene: Scene | null = null

  readonly #geometries = new Set<BufferGeometry>()
  readonly #textures = new Set<Texture>()

  constructor(canvas: HTMLCanvasElement) {
    this.domElement = canvas
    liveRenderers.count += 1
  }

  setSize(): void {}
  setPixelRatio(): void {}
  setViewport(): void {}
  clear(): void {}

  render(scene: Scene, _camera: Camera): void {
    // Kept so a test can inspect what the viewer actually put in front of the
    // camera — the scene graph is private to the viewer, and asserting on it
    // through the thing that draws it is the only honest way in.
    this.scene = scene
    this.info.render.calls += 1
    this.#registerTexture(scene.environment)
    scene.traverse((object) => this.#registerObject(object))
  }

  dispose(): void {
    // Matches the real renderer: dispose() releases renderer-owned state and does
    // *not* reset the memory counters. Those only fall when each resource is
    // itself disposed, which is precisely what the test is checking.
    this.disposed = true
    liveRenderers.count -= 1
  }

  forceContextLoss(): void {
    this.contextLost = true
  }

  #registerObject(object: Object3D): void {
    if (!(
      object instanceof Mesh ||
      object instanceof Points ||
      object instanceof Line ||
      object instanceof Sprite
    )) {
      return
    }

    this.#registerGeometry(object.geometry)

    const materials: Material[] = Array.isArray(object.material)
      ? object.material
      : [object.material]
    for (const material of materials) {
      for (const value of Object.values(material)) {
        if (isTexture(value)) this.#registerTexture(value)
      }
    }
  }

  #registerGeometry(geometry: BufferGeometry): void {
    if (this.#geometries.has(geometry)) return
    this.#geometries.add(geometry)
    this.info.memory.geometries = this.#geometries.size
    ;(geometry as unknown as Disposable).addEventListener('dispose', () => {
      this.#geometries.delete(geometry)
      this.info.memory.geometries = this.#geometries.size
    })
  }

  #registerTexture(texture: Texture | null): void {
    if (texture === null || this.#textures.has(texture)) return
    this.#textures.add(texture)
    this.info.memory.textures = this.#textures.size
    ;(texture as unknown as Disposable).addEventListener('dispose', () => {
      this.#textures.delete(texture)
      this.info.memory.textures = this.#textures.size
    })
  }
}

function isTexture(value: unknown): value is Texture {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { isTexture?: boolean }).isTexture === true
  )
}

/** The cast the viewer's `createRenderer` seam expects. */
export function asRenderer(renderer: FakeWebGLRenderer): WebGLRenderer {
  return renderer as unknown as WebGLRenderer
}
