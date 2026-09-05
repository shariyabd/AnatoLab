/**
 * Constructs the real `GLTFLoader` for the asset pipeline's output format.
 *
 * Split out of `AssetManager.ts` and reached only through a dynamic import so
 * the Meshopt WASM blob is fetched when a viewer actually loads a model, never
 * when a page merely imports the library and never in the Vitest suite (which
 * injects its own `ModelLoader`).
 *
 * scripts/encode-model.mjs emits `EXT_meshopt_compression` geometry plus either
 * KTX2/Basis or WebP textures. Meshopt is therefore mandatory; KTX2 is opt-in
 * because no Basis transcoder is deployed today (scripts/README.md).
 */

import type { WebGLRenderer } from 'three'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js'
import type { ModelLoader } from './AssetManager'

export interface GltfLoaderOptions {
  readonly ktx2TranscoderPath?: string
  readonly renderer?: WebGLRenderer
}

export async function createGltfLoader(options: GltfLoaderOptions = {}): Promise<ModelLoader> {
  const loader = new GLTFLoader()
  loader.setMeshoptDecoder(MeshoptDecoder)

  const { ktx2TranscoderPath, renderer } = options
  if (ktx2TranscoderPath !== undefined && renderer !== undefined) {
    const { KTX2Loader } = await import('three/examples/jsm/loaders/KTX2Loader.js')
    const ktx2 = new KTX2Loader().setTranscoderPath(ktx2TranscoderPath).detectSupport(renderer)
    loader.setKTX2Loader(ktx2)
  }

  return loader
}
