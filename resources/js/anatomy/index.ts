/**
 * Public surface of the framework-free viewer library.
 *
 * Only `resources/js/composables/useAnatomyViewer.ts` may import from this
 * directory (docs/architecture.md §5.1, docs/engineering.md invariant 3).
 * Everything a consumer needs is re-exported here so no other file has to reach
 * into a module path.
 */

export { AnatomyViewer, type ViewerDependencies } from './AnatomyViewer'
export { AnatomyAssetManager, type LoadedOrganModel, type ModelLoader } from './AssetManager'
export { HotspotLayer, type HotspotScreenPosition, snapAllToSurface } from './HotspotLayer'
export { TypedEmitter } from './emitter'
export { disposeObject3D, disposeMaterial } from './dispose'
export { probeWebGL, type WebGLProbe } from './webgl'
export { FIT_SIZE, MAX_CAMERA_DISTANCE_FACTOR, HOTSPOT_SURFACE_OFFSET } from './constants'
export { GUIDED_TOUR_ID, buildGuidedTour, type AnimationStep } from './animation'
export {
  VISUAL_DIRECTIVE_KEYS,
  type CrossSectionDirective,
  type SimulationState,
  type VisualDirectives,
} from './simulation'
export type * from './types'
