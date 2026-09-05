/**
 * The 3D anatomy viewer.
 *
 * Framework-free by contract: this directory imports no Vue, no Inertia, no HTTP
 * client, and never fetches (docs/engineering.md invariant 3,
 * docs/architecture.md §5.4 rule 3). The only bridge into the application is
 * `resources/js/composables/useAnatomyViewer.ts`, which hands it a fully-formed
 * `OrganDto`.
 *
 * Reimplemented, not adapted. docs/licence-log.md §4 records the grant-vs-replace
 * decision as *not yet taken*, and docs/handovers/parallel-execution-plan.md §3
 * C2 says to default to reimplementation absent a written grant. The techniques
 * below come from our own audit notes (docs/project-context.md §2.4), which
 * describe behaviour rather than code; §5 of the licence log confirms that is
 * clean.
 *
 * ## Honest semantics
 *
 * The asset set is single-mesh: one node, one mesh, one primitive, one material,
 * and zero animation clips (docs/project-context.md §2.2). Three methods
 * therefore have reduced semantics, spelled out in docs/architecture.md §5.3 and
 * repeated at each method below. Each emits `capability:degraded` rather than
 * failing silently, so the host page can avoid labelling a control with
 * something it does not do — the precise failure the audit found upstream, where
 * "Isolate" faded only the plinth and "Layers" meant wireframe (§2.5). All three
 * gain full behaviour when per-structure meshes land, with no change to this
 * interface, the API, or the schema.
 *
 * ## Rendering
 *
 * Render-on-demand. The loop draws a frame only when something changed
 * (`#dirty`), while an interaction settles (`#busyUntil`), or while an animation
 * is genuinely running. An idle viewer costs nothing, which is what makes it
 * safe to run beside the AI tutor panel (docs/project-context.md §2.4).
 */

import {
  ACESFilmicToneMapping,
  Box3,
  CircleGeometry,
  Color,
  DirectionalLight,
  Group,
  HemisphereLight,
  Mesh,
  MeshBasicMaterial,
  MeshStandardMaterial,
  PerspectiveCamera,
  PMREMGenerator,
  Plane,
  Raycaster,
  SRGBColorSpace,
  Scene,
  Texture,
  Vector2,
  Vector3,
  WebGLRenderer,
  type Material,
  type Object3D,
} from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import gsap from 'gsap'

import { FIT_SIZE, MAX_CAMERA_DISTANCE_FACTOR } from './constants'
import { AnatomyAssetManager, type LoadedOrganModel, type ModelLoader } from './AssetManager'
import { HotspotLayer, type HotspotScreenPosition } from './HotspotLayer'
import { TypedEmitter } from './emitter'
import { disposeObject3D, disposeMaterial } from './dispose'
import { probeWebGL, type WebGLProbe } from './webgl'
import { GUIDED_TOUR_ID, buildGuidedTour, type AnimationStep } from './animation'
import type { SimulationState, VisualDirectives } from './simulation'
import type {
  OrganDto,
  StructureDto,
  StructureId,
  Unsubscribe,
  Vec3,
  ViewerEventMap,
  ViewerEventName,
  ViewerFailureReason,
  ViewerLayer,
  ViewerMode,
  ViewerOptions,
} from './types'

/** Default framing: far enough out that the whole FIT_SIZE cube is in frame. */
const DEFAULT_CAMERA_DISTANCE = FIT_SIZE * 1.55
const MIN_CAMERA_DISTANCE = FIT_SIZE * 0.55
const MAX_CAMERA_DISTANCE = FIT_SIZE * MAX_CAMERA_DISTANCE_FACTOR

/** How close a fly-to gets to the structure it is framing. */
const FOCUS_DISTANCE = FIT_SIZE * 0.85

/** Keep drawing this long after an interaction so damping settles visibly. */
const SETTLE_MS = 450
const CAMERA_TWEEN_S = 0.85
const CROSSFADE_S = 0.35
const ISOLATED_OPACITY = 0.35

/** Pointer travel, in pixels, above which a press is an orbit and not a click. */
const CLICK_SLOP_PX = 6
const CLICK_TIMEOUT_MS = 700

/**
 * Internal seams, supplied only by the test suite.
 *
 * The §5.2 signature is `constructor(container, options)` and stays that way —
 * this parameter is optional and production callers omit it. It exists because
 * jsdom has no WebGL and no network: without it the disposal test could not run
 * the real scene-graph, hotspot, and material code paths at all, and a test that
 * cannot run the real path proves nothing.
 */
export interface ViewerDependencies {
  readonly createRenderer?: (canvas: HTMLCanvasElement) => WebGLRenderer
  readonly createLoader?: () => ModelLoader
  readonly probeWebGL?: () => WebGLProbe
}

type MaterialRecord = {
  readonly material: Material & {
    color?: Color
    emissive?: Color
    opacity: number
    transparent: boolean
    depthWrite: boolean
    wireframe?: boolean
    clippingPlanes?: Plane[] | null
  }
  readonly color: Color | null
  readonly emissive: Color | null
  readonly opacity: number
  readonly transparent: boolean
  readonly depthWrite: boolean
}

export class AnatomyViewer {
  readonly #container: HTMLElement
  readonly #options: ViewerOptions
  readonly #emitter = new TypedEmitter()
  readonly #reducedMotion: boolean

  readonly #scene = new Scene()
  readonly #camera: PerspectiveCamera
  readonly #modelGroup = new Group()
  readonly #hotspots: HotspotLayer
  readonly #assets: AnatomyAssetManager
  readonly #raycaster = new Raycaster()
  readonly #clipPlane = new Plane(new Vector3(-1, 0, 0), 0)

  #renderer: WebGLRenderer | null = null
  #canvas: HTMLCanvasElement | null = null
  #structureList: HTMLElement | null = null
  #controls: OrbitControls | null = null
  #environment: Texture | null = null
  #plinth: Mesh | null = null
  #contactShadow: Mesh | null = null

  #model: LoadedOrganModel | null = null
  #materials: MaterialRecord[] = []
  #mode: ViewerMode
  #layer: ViewerLayer = 'solid'
  #selectedId: StructureId | null = null
  #isolatedId: StructureId | null = null
  #crossSectionOn = false

  #rafId: number | null = null
  #dirty = true
  #busyUntil = 0
  #visible = true
  #tweening = false
  #animationToken = 0
  #hoveredId: StructureId | null = null
  #pointerDown: { x: number; y: number; at: number } | null = null
  #resizeObserver: ResizeObserver | null = null
  #intersectionObserver: IntersectionObserver | null = null

  #available: boolean
  #disposed = false

  constructor(container: HTMLElement, options: ViewerOptions = {}, deps: ViewerDependencies = {}) {
    this.#container = container
    this.#options = options
    this.#reducedMotion = options.reducedMotion === true
    this.#mode = options.initialMode ?? 'explore'

    this.#camera = new PerspectiveCamera(42, 1, 0.1, 200)
    this.#camera.position.set(0, FIT_SIZE * 0.25, DEFAULT_CAMERA_DISTANCE)

    this.#hotspots = new HotspotLayer({
      reducedMotion: this.#reducedMotion,
      onIndexSelect: (structure) => this.#handleIndexSelect(structure),
    })

    const probe = (deps.probeWebGL ?? probeWebGL)()
    this.#available = probe.available

    this.#assets = new AnatomyAssetManager({
      createLoader: deps.createLoader,
    })

    if (!this.#available) {
      // Deferred: the caller cannot have subscribed yet — `on()` is only
      // reachable after the constructor returns. Emitting synchronously here
      // would drop the event on the floor for every consumer.
      queueMicrotask(() => {
        if (this.#disposed) return
        this.#emitter.emit('webgl:unavailable', { detail: probe.detail })
      })
      this.#mountStructureList()
      return
    }

    this.#buildScene(deps)
  }

  // ---------------------------------------------------------------- content

  /**
   * Loads an organ's model, attaches its hotspots, and frames it.
   *
   * **Never rejects.** Every failure path emits `load:failed` and resolves,
   * because every caller is a UI that has to render the structure list,
   * description, and lesson content without 3D rather than surface a stack trace
   * (docs/architecture.md §5.4 rule 5, PRD §31/§40).
   */
  async loadOrgan(organ: OrganDto): Promise<void> {
    if (this.#disposed) return

    if (!this.#available) {
      this.#emitter.emit('load:failed', {
        reason: 'webgl-unavailable',
        detail: 'The 3D model cannot be shown; the structure list is still available.',
      })
      return
    }

    const startedAt = nowMs()
    const previous = this.#model

    let model: LoadedOrganModel
    try {
      model = await this.#assets.load(organ.modelUrl, (loaded, total) => {
        this.#emitter.emit('load:progress', { loaded, total })
      })
    } catch (error) {
      const failure = classifyLoadFailure(error)
      this.#emitter.emit('load:failed', failure)
      return
    }

    if (this.#disposed) return

    this.#model = model
    this.#assets.retain(organ.modelUrl)

    this.#modelGroup.add(model.root)
    this.#collectMaterials(model.root)
    this.#applyLayerToMaterials()

    // Hotspots snap against this organ's geometry, so they must be rebuilt for
    // every load — an anchor is only meaningful in the model it was authored
    // against (docs/architecture.md §5.4 rule 1).
    this.#hotspots.attach(organ.structures, model.root, organ.accentColor)
    this.#selectedId = null
    this.#isolatedId = null
    this.#hotspots.setSolo(null)
    this.#hotspots.setSelected(null)

    this.#positionGround(model)
    this.resetView()
    // Prime the markers before the first frame: a pick or a callout query that
    // arrives between load and the next render would otherwise read default
    // material state instead of the real occlusion result.
    this.#camera.updateMatrixWorld()
    this.#hotspots.update(this.#camera)

    if (previous !== null && previous !== model) {
      await this.#crossfadeOut(previous)
    }

    this.#requestRender()
    this.#emitter.emit('organ:loaded', {
      organ,
      triangles: model.triangles,
      loadMs: Math.round(nowMs() - startedAt),
    })
  }

  /**
   * Warms the cache for a model the student has not opened yet, at idle
   * priority. Fire-and-forget: a failed prefetch is not an error, because
   * nothing is waiting on it.
   */
  prefetchOrgan(modelUrl: string): void {
    if (this.#disposed || !this.#available) return
    this.#assets.prefetch(modelUrl)
  }

  // -------------------------------------------------------------- selection

  /**
   * Sets selection programmatically. Lessons and missions drive the viewer this
   * way; the audited implementation had no equivalent (docs/project-context.md
   * §5.1).
   *
   * An id with no marker clears the selection and emits `null`, rather than
   * failing quietly — ids are opaque and come from the API, so an unknown one is
   * a contract bug worth seeing.
   */
  selectStructure(id: StructureId | null): void {
    if (this.#disposed) return

    const structure = id === null ? null : this.#hotspots.structureById(id)
    this.#selectedId = structure?.id ?? null
    this.#hotspots.setSelected(this.#selectedId)
    this.#requestRender()
    this.#emitter.emit('structure:selected', { structure })
  }

  getSelectedStructure(): StructureDto | null {
    return this.#selectedId === null ? null : this.#hotspots.structureById(this.#selectedId)
  }

  /**
   * Transient emphasis without changing selection — a lesson pointing at
   * something the student has not clicked.
   *
   * Single-mesh semantics: marker emphasis and a pulse ring. With per-structure
   * meshes this also tints the mesh (docs/architecture.md §5.3).
   */
  highlightStructure(id: StructureId | null): void {
    if (this.#disposed) return
    this.#hotspots.setHighlighted(
      id === null ? null : (this.#hotspots.structureById(id)?.id ?? null),
    )
    this.#requestRender()
  }

  /** Camera fly-to plus selection. Resolves when the camera has arrived. */
  focusStructure(id: StructureId): void {
    if (this.#disposed) return
    const structure = this.#hotspots.structureById(id)
    if (structure === null) return

    this.selectStructure(id)
    const target = this.#hotspots.positionOf(id)
    if (target !== null) void this.#flyTo(target, FOCUS_DISTANCE)
  }

  /**
   * Reduced semantics (docs/architecture.md §5.3).
   *
   * With single-mesh models there is no second object to hide, so this dims every
   * other marker, fades the organ to 35 %, dims the plinth, and flies the camera
   * in. It does **not** hide geometry, and says so through `capability:degraded`.
   * With per-structure meshes it hides every other mesh instead.
   */
  isolateStructure(id: StructureId | null): void {
    if (this.#disposed || !this.#available) return

    const structure = id === null ? null : this.#hotspots.structureById(id)
    this.#isolatedId = structure?.id ?? null
    this.#hotspots.setSolo(this.#isolatedId)

    if (this.#isolatedId === null) {
      this.#restoreMaterials()
      this.#setGroundOpacity(1)
      this.#requestRender()
      return
    }

    this.#setOrganOpacity(ISOLATED_OPACITY)
    this.#setGroundOpacity(0.25)
    this.focusStructure(this.#isolatedId)

    this.#emitter.emit('capability:degraded', {
      capability: 'isolate',
      reason:
        'This model is a single mesh with no per-structure geometry, so other structures ' +
        'are dimmed rather than hidden (docs/project-context.md §2.2).',
    })
  }

  /** Quiz feedback: a green or red ring on the marker. */
  flashStructure(id: StructureId, correct: boolean): void {
    if (this.#disposed || !this.#available) return
    const duration = this.#hotspots.flash(id, correct)
    this.#keepAwake(duration)
  }

  // ------------------------------------------------------------------- view

  resetView(): void {
    if (this.#disposed || !this.#available) return
    void this.#flyTo(
      new Vector3(0, 0, 0),
      DEFAULT_CAMERA_DISTANCE,
      new Vector3(0, FIT_SIZE * 0.2, DEFAULT_CAMERA_DISTANCE),
    )
  }

  zoom(direction: 1 | -1): void {
    if (this.#disposed || this.#controls === null) return

    const target = this.#controls.target
    const offset = this.#camera.position.clone().sub(target)
    const distance = clamp(
      offset.length() * (direction === 1 ? 0.8 : 1.25),
      MIN_CAMERA_DISTANCE,
      MAX_CAMERA_DISTANCE,
    )
    this.#camera.position.copy(target).addScaledVector(offset.normalize(), distance)
    this.#controls.update()
    this.#requestRender()
  }

  /**
   * Auto-rotate is refused outright under `prefers-reduced-motion`. That is not a
   * degraded capability — it is the preference being honoured, and the audited
   * implementation ignored it and auto-rotated by default
   * (docs/project-context.md §5.1).
   */
  setAutoRotate(enabled: boolean): void {
    if (this.#disposed || this.#controls === null) return
    this.#controls.autoRotate = enabled && !this.#reducedMotion
    this.#requestRender()
  }

  /**
   * Reduced semantics (docs/architecture.md §5.3).
   *
   * `wireframe` toggles wireframe on the one material — it is not anatomical
   * layering. `section` applies one global clipping plane across the whole organ,
   * revealing the inside of a hollow shell rather than internal anatomy
   * (docs/project-context.md §2.2). Both announce themselves as degraded.
   */
  setLayer(layer: ViewerLayer): void {
    if (this.#disposed || !this.#available) return

    this.#layer = layer
    this.#applyLayerToMaterials()

    if (layer === 'wireframe') {
      this.#emitter.emit('capability:degraded', {
        capability: 'layers',
        reason:
          'These models carry no layer information, so this is a wireframe of the whole ' +
          'organ, not superficial-to-deep anatomical layers.',
      })
    }

    if (layer === 'section') {
      this.setCrossSection(true)
    } else if (this.#crossSectionOn) {
      this.setCrossSection(false)
    }

    this.#requestRender()
  }

  /**
   * One global clipping plane across the organ. Reduced semantics
   * (docs/architecture.md §5.3): the models are surface shells with no modelled
   * interior, so the cut reveals the inside of the shell, not chambers.
   */
  setCrossSection(enabled: boolean, axis: 'x' | 'y' | 'z' = 'x', offset = 0): void {
    if (this.#disposed || !this.#available) return

    this.#crossSectionOn = enabled
    this.#clipPlane.normal.set(axis === 'x' ? -1 : 0, axis === 'y' ? -1 : 0, axis === 'z' ? -1 : 0)
    this.#clipPlane.constant = offset

    for (const record of this.#materials) {
      record.material.clippingPlanes = enabled ? [this.#clipPlane] : null
      record.material.needsUpdate = true
    }

    if (enabled) {
      this.#emitter.emit('capability:degraded', {
        capability: 'crossSection',
        reason:
          'The cut is whole-organ and the model is a hollow surface shell, so it exposes ' +
          'the inside of the shell rather than internal structures.',
      })
    }

    this.#requestRender()
  }

  // ------------------------------------------------------------------ modes

  /**
   * - `explore` — clicking a marker selects it and opens the callout.
   * - `quiz` — clicking reports `structure:picked` and never changes selection,
   *   so a wrong answer leaves no sticky highlight to copy from.
   * - `mission` — as `quiz`; steps are driven programmatically by
   *   `focusStructure` and `flashStructure`.
   * - `author` — markers stop responding, the cursor becomes a crosshair, and a
   *   click raycasts the mesh and reports the hit as `author:point` in FIT_SIZE
   *   pivot space. This is the admin hotspot tool (Handover 13); upstream gated
   *   the same thing behind `?authoring=1` and copied a code literal to the
   *   clipboard (docs/project-context.md §2.4).
   */
  setMode(mode: ViewerMode): void {
    if (this.#disposed) return

    this.#mode = mode
    this.#hotspots.setInteractive(mode !== 'author')

    if (this.#canvas !== null) {
      this.#canvas.style.cursor = mode === 'author' ? 'crosshair' : 'grab'
    }

    if (mode !== 'explore') {
      // Selection is explore's idea of state. Carrying it into a quiz would show
      // the student a highlighted structure while they are being asked to find
      // one.
      this.selectStructure(null)
    }

    this.#requestRender()
  }

  getMode(): ViewerMode {
    return this.#mode
  }

  // --------------------------------------------------------------- learning

  /**
   * Reduced semantics (docs/architecture.md §5.3).
   *
   * `gltf.animations.length` is 0 for every model in the set
   * (docs/project-context.md §2.2), so there is no clip to play. This runs a
   * scripted camera and marker choreography instead and always announces itself
   * as degraded. Pass `steps` to supply the choreography; omit it and the only
   * built-in id is `'tour'`, a walk through every structure on the organ.
   */
  async triggerAnimation(animationId: string, steps?: readonly AnimationStep[]): Promise<void> {
    if (this.#disposed || !this.#available) return

    const list =
      steps ?? (animationId === GUIDED_TOUR_ID ? buildGuidedTour(this.#hotspots.structures) : null)

    if (list === null || list.length === 0) {
      this.#emitter.emit('capability:degraded', {
        capability: 'animation',
        reason:
          `No choreography is defined for "${animationId}", and the model set contains no ` +
          'animation clips to fall back on.',
      })
      return
    }

    this.#emitter.emit('capability:degraded', {
      capability: 'animation',
      reason:
        'The models contain no animation clips, so this is a scripted camera and marker ' +
        'sequence rather than anatomical motion.',
    })

    this.#animationToken += 1
    const token = this.#animationToken

    for (const step of list) {
      if (this.#disposed || token !== this.#animationToken) return

      if (step.focus !== undefined) {
        const position = this.#hotspots.positionOf(step.focus)
        this.highlightStructure(step.focus)
        if (position !== null) await this.#flyTo(position, FOCUS_DISTANCE)
      }

      const hold = this.#reducedMotion ? 0 : (step.holdMs ?? 0)
      if (hold > 0) await delay(hold)
    }

    if (token === this.#animationToken) this.highlightStructure(null)
  }

  /**
   * Applies one simulation step's visual directives (docs/architecture.md §12).
   *
   * The vocabulary is closed to five directives, chosen because a single-mesh
   * model can actually perform them. Unknown keys are ignored rather than
   * approximated — see `simulation.ts`. State transitions are computed in PHP and
   * are reproducible; nothing here decides anything.
   */
  applySimulationState(state: SimulationState): void {
    if (this.#disposed || !this.#available) return

    const directives: VisualDirectives = state.visualDirectives ?? {}

    if ('tint' in directives) this.#setTint(directives.tint ?? null)
    if ('pulseRate' in directives) {
      this.#hotspots.setPulseRate(directives.pulseRate ?? 0)
      this.#keepAwake(SETTLE_MS)
    }
    if ('highlight' in directives) this.highlightStructure(directives.highlight ?? null)
    if ('crossSection' in directives) {
      const section = directives.crossSection
      if (section === null || section === undefined || section === false) {
        this.setCrossSection(false)
      } else if (section === true) {
        this.setCrossSection(true)
      } else {
        this.setCrossSection(section.enabled, section.axis, section.offset)
      }
    }
    if (directives.focus !== null && directives.focus !== undefined) {
      this.focusStructure(directives.focus)
    }

    this.#requestRender()
  }

  // ----------------------------------------------------------------- events

  on<E extends ViewerEventName>(
    event: E,
    handler: (payload: ViewerEventMap[E]) => void,
  ): Unsubscribe {
    return this.#emitter.on(event, handler)
  }

  /**
   * Where a marker currently sits on the canvas, in CSS pixels.
   *
   * Beyond §5.2, and deliberately: the screen-anchored callout is one of the
   * capabilities worth keeping from the audit (docs/project-context.md §2.4), and
   * without this the host page cannot position one imperatively. Handovers 05,
   * 07, 09 and 12 are told not to reopen this lane
   * (docs/handovers/parallel-execution-plan.md C6), so it ships now.
   */
  getStructureScreenPosition(id: StructureId): HotspotScreenPosition | null {
    if (this.#disposed || this.#canvas === null) return null
    this.#camera.updateMatrixWorld()
    const { clientWidth, clientHeight } = this.#canvas
    return this.#hotspots.screenPosition(id, this.#camera, clientWidth, clientHeight)
  }

  /** False when WebGL could not be initialised; the page should render its text fallback. */
  get isAvailable(): boolean {
    return this.#available
  }

  // ---------------------------------------------------------------- disposal

  /**
   * Frees every GPU resource, listener, observer, timer, and tween this viewer
   * created. Mandatory on unmount, on every path (docs/architecture.md §5.4
   * rule 6). `AnatomyViewer.test.ts` asserts renderer, geometry, and texture
   * counts return to zero.
   */
  dispose(): void {
    if (this.#disposed) return
    this.#disposed = true

    if (this.#rafId !== null) cancelAnimationFrame(this.#rafId)
    this.#rafId = null

    gsap.killTweensOf(this.#camera.position)
    if (this.#controls !== null) gsap.killTweensOf(this.#controls.target)

    this.#resizeObserver?.disconnect()
    this.#intersectionObserver?.disconnect()
    this.#resizeObserver = null
    this.#intersectionObserver = null

    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', this.#handleVisibilityChange)
    }

    if (this.#canvas !== null) {
      this.#canvas.removeEventListener('pointerdown', this.#handlePointerDown)
      this.#canvas.removeEventListener('pointerup', this.#handlePointerUp)
      this.#canvas.removeEventListener('pointermove', this.#handlePointerMove)
      this.#canvas.removeEventListener('pointerleave', this.#handlePointerLeave)
    }

    this.#controls?.dispose()
    this.#controls = null

    this.#hotspots.dispose()

    // Order matters: detach the model before disposing the manager, so the
    // manager frees geometry that is no longer referenced by a live scene graph.
    if (this.#model !== null) this.#modelGroup.remove(this.#model.root)
    this.#model = null
    this.#materials = []
    this.#assets.dispose()

    if (this.#plinth !== null) disposeObject3D(this.#plinth)
    if (this.#contactShadow !== null) {
      const material = this.#contactShadow.material
      if (!Array.isArray(material)) disposeMaterial(material)
      this.#contactShadow.geometry.dispose()
      this.#contactShadow.removeFromParent()
    }
    this.#plinth = null
    this.#contactShadow = null

    this.#environment?.dispose()
    this.#environment = null
    this.#scene.environment = null
    this.#scene.clear()

    this.#renderer?.dispose()
    // Tell the driver to drop the context now rather than at the next GC. Browsers
    // cap live contexts per page (docs/architecture.md §5.4 rule 7), and a
    // lesson that navigates between eight organs would exhaust that cap.
    this.#renderer?.forceContextLoss()
    this.#renderer = null

    this.#canvas?.remove()
    this.#canvas = null
    this.#structureList?.remove()
    this.#structureList = null

    this.#emitter.clear()
  }

  // ------------------------------------------------------------- internals

  #buildScene(deps: ViewerDependencies): void {
    const canvas = document.createElement('canvas')
    canvas.className = 'anatomy-viewer__canvas'
    canvas.style.display = 'block'
    canvas.style.width = '100%'
    canvas.style.height = '100%'
    canvas.style.touchAction = 'none'
    canvas.style.cursor = this.#mode === 'author' ? 'crosshair' : 'grab'
    this.#container.append(canvas)
    this.#canvas = canvas

    const renderer =
      deps.createRenderer?.(canvas) ??
      createDefaultRenderer(canvas, this.#options.lowPower === true)
    this.#renderer = renderer

    this.#scene.add(this.#modelGroup, this.#hotspots.group)
    this.#buildLighting(renderer)
    this.#buildGround()
    this.#mountStructureList()

    const controls = new OrbitControls(this.#camera, canvas)
    controls.enableDamping = true
    controls.dampingFactor = 0.08
    // Pan is on. The audited implementation disabled it; the PRD requires it, and
    // without it a student cannot bring an off-centre structure to the middle of
    // the screen (docs/project-context.md §5.1).
    controls.enablePan = true
    controls.screenSpacePanning = true
    controls.minDistance = MIN_CAMERA_DISTANCE
    controls.maxDistance = MAX_CAMERA_DISTANCE
    controls.autoRotate = false
    controls.autoRotateSpeed = 0.6
    controls.addEventListener('change', () => {
      this.#dirty = true
      this.#keepAwake(SETTLE_MS)
    })
    this.#controls = controls

    canvas.addEventListener('pointerdown', this.#handlePointerDown)
    canvas.addEventListener('pointerup', this.#handlePointerUp)
    canvas.addEventListener('pointermove', this.#handlePointerMove)
    canvas.addEventListener('pointerleave', this.#handlePointerLeave)

    this.#observeContainer()
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', this.#handleVisibilityChange)
    }

    this.#resize()
    this.#startLoop()
  }

  #buildLighting(renderer: WebGLRenderer): void {
    // Analytic lights first: they are the floor the scene falls back to if the
    // environment map cannot be generated, and on their own they still read as
    // lit rather than flat.
    const hemisphere = new HemisphereLight(0xffffff, 0x30343c, 1.1)
    const key = new DirectionalLight(0xffffff, 1.6)
    key.position.set(2.5, 3.5, 2.5)
    // No shadow maps. The models are closed shells, so a shadow map buys
    // self-shadowing acne and a second render pass; a baked contact shadow under
    // the organ gives the grounding cue for a fraction of the cost
    // (docs/project-context.md §2.4).
    key.castShadow = false
    this.#scene.add(hemisphere, key)

    const room = buildRoomScene()
    try {
      const pmrem = new PMREMGenerator(renderer)
      // Generated once and reused for every organ: PMREM convolution is the most
      // expensive thing that happens at start-up, and the room is the same room.
      const environment = pmrem.fromScene(room, 0.04).texture
      this.#scene.environment = environment
      this.#environment = environment
      pmrem.dispose()
    } catch {
      // Float render targets are unavailable on some low-end GPUs and in every
      // headless test. The analytic lights above already cover this case, so
      // there is nothing for the user to be told.
    } finally {
      // The room is scaffolding either way: PMREMGenerator does not dispose the
      // scene it convolves, and on the failure path it never touched it.
      disposeObject3D(room)
    }
  }

  #buildGround(): void {
    const plinth = new Mesh(
      new CircleGeometry(FIT_SIZE * 0.78, 64),
      new MeshStandardMaterial({
        color: new Color(0x1a1d23),
        roughness: 0.95,
        metalness: 0,
        transparent: true,
        opacity: 0.55,
      }),
    )
    plinth.rotation.x = -Math.PI / 2
    plinth.renderOrder = -2
    this.#scene.add(plinth)
    this.#plinth = plinth

    const shadowTexture = createContactShadowTexture()
    if (shadowTexture !== null) {
      const shadow = new Mesh(
        new CircleGeometry(FIT_SIZE * 0.6, 48),
        new MeshBasicMaterial({
          map: shadowTexture,
          transparent: true,
          opacity: 0.55,
          depthWrite: false,
        }),
      )
      shadow.rotation.x = -Math.PI / 2
      shadow.renderOrder = -1
      this.#scene.add(shadow)
      this.#contactShadow = shadow
    }
  }

  #positionGround(model: LoadedOrganModel): void {
    const floor = new Box3().setFromObject(model.root).min.y
    if (this.#plinth !== null) this.#plinth.position.y = floor - 0.02
    if (this.#contactShadow !== null) this.#contactShadow.position.y = floor - 0.01
  }

  #mountStructureList(): void {
    const list = document.createElement('div')
    list.className = 'anatomy-viewer__structures'
    // The dots are mirrored as real buttons so the 3D interaction has a keyboard
    // and screen-reader equivalent (PRD §31). The host page styles this; the
    // viewer does not hide it.
    this.#container.append(list)
    this.#structureList = list
    this.#hotspots.mountIndex(list)
  }

  #startLoop(): void {
    const tick = (): void => {
      this.#rafId = requestAnimationFrame(tick)
      this.#renderFrame()
    }
    this.#rafId = requestAnimationFrame(tick)
  }

  /**
   * One frame of the render-on-demand loop.
   *
   * Skipped entirely when the canvas is off-screen or the tab is hidden — an
   * `IntersectionObserver` and `visibilitychange` gate, so a viewer scrolled out
   * of view stops costing anything (docs/project-context.md §2.4).
   */
  #renderFrame(): void {
    const renderer = this.#renderer
    const controls = this.#controls
    if (renderer === null || controls === null) return
    if (!this.#visible) return
    if (typeof document !== 'undefined' && document.hidden) return

    const busy = nowMs() < this.#busyUntil
    const animating = controls.autoRotate || this.#tweening || this.#hotspots.isAnimating

    if (!this.#dirty && !busy && !animating) return

    controls.update()
    this.#hotspots.update(this.#camera)
    renderer.render(this.#scene, this.#camera)
    this.#dirty = false
  }

  #requestRender(): void {
    this.#dirty = true
  }

  #keepAwake(ms: number): void {
    this.#busyUntil = Math.max(this.#busyUntil, nowMs() + ms)
    this.#dirty = true
  }

  #observeContainer(): void {
    if (typeof ResizeObserver !== 'undefined') {
      this.#resizeObserver = new ResizeObserver(() => this.#resize())
      this.#resizeObserver.observe(this.#container)
    }

    if (typeof IntersectionObserver !== 'undefined') {
      this.#intersectionObserver = new IntersectionObserver(
        (entries) => {
          const entry = entries.at(-1)
          if (entry === undefined) return
          this.#visible = entry.isIntersecting
          if (this.#visible) this.#keepAwake(SETTLE_MS)
        },
        { threshold: 0 },
      )
      this.#intersectionObserver.observe(this.#container)
    }
  }

  #resize(): void {
    const renderer = this.#renderer
    if (renderer === null) return

    const width = Math.max(1, this.#container.clientWidth)
    const height = Math.max(1, this.#container.clientHeight)

    renderer.setSize(width, height, false)
    this.#camera.aspect = width / height
    this.#camera.updateProjectionMatrix()
    this.#requestRender()
  }

  #handleVisibilityChange = (): void => {
    if (typeof document !== 'undefined' && !document.hidden) this.#keepAwake(SETTLE_MS)
  }

  #handlePointerDown = (event: PointerEvent): void => {
    this.#pointerDown = { x: event.clientX, y: event.clientY, at: nowMs() }
  }

  #handlePointerUp = (event: PointerEvent): void => {
    const down = this.#pointerDown
    this.#pointerDown = null
    if (down === null) return

    // An orbit drag ends with a pointerup too. Anything that travelled further
    // than the slop, or dwelt longer than the timeout, was a camera move.
    const travelled = Math.hypot(event.clientX - down.x, event.clientY - down.y)
    if (travelled > CLICK_SLOP_PX || nowMs() - down.at > CLICK_TIMEOUT_MS) return

    const pointer = this.#toCanvasPixels(event)
    if (pointer === null) return

    if (this.#mode === 'author') {
      this.#captureAuthorPoint(pointer)
      return
    }

    const structure = this.#pickAt(pointer)
    if (structure === null) {
      if (this.#mode === 'explore') this.selectStructure(null)
      return
    }

    this.#emitter.emit('structure:picked', { structure, pointer })

    // Quiz and mission consume the click without changing selection: a sticky
    // highlight on the structure just guessed would give the next question away.
    if (this.#mode === 'explore') this.selectStructure(structure.id)
  }

  #handlePointerMove = (event: PointerEvent): void => {
    if (this.#mode === 'author' || this.#canvas === null) return

    const pointer = this.#toCanvasPixels(event)
    if (pointer === null) return

    const structure = this.#pickAt(pointer)
    const id = structure?.id ?? null
    this.#canvas.style.cursor = id === null ? 'grab' : 'pointer'

    if (id === this.#hoveredId) return
    this.#hoveredId = id
    this.#requestRender()
    this.#emitter.emit('structure:hovered', { structure })
  }

  #handlePointerLeave = (): void => {
    this.#pointerDown = null
    if (this.#hoveredId === null) return
    this.#hoveredId = null
    this.#emitter.emit('structure:hovered', { structure: null })
  }

  #handleIndexSelect(structure: StructureDto): void {
    if (this.#mode === 'explore') {
      // focusStructure selects as part of flying to it, so calling both would
      // emit structure:selected twice for one keyboard activation.
      this.focusStructure(structure.id)
      return
    }
    // In quiz and mission modes the accessible list is the keyboard equivalent
    // of clicking a dot, so it reports a pick rather than selecting.
    this.#emitter.emit('structure:picked', { structure, pointer: { x: 0, y: 0 } })
  }

  #toCanvasPixels(event: PointerEvent): { x: number; y: number } | null {
    const canvas = this.#canvas
    if (canvas === null) return null
    const rect = canvas.getBoundingClientRect()
    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
  }

  #pickAt(pointer: { x: number; y: number }): StructureDto | null {
    const canvas = this.#canvas
    if (canvas === null) return null
    this.#camera.updateMatrixWorld()
    const width = canvas.clientWidth || 1
    const height = canvas.clientHeight || 1
    return this.#hotspots.pick(pointer.x, pointer.y, this.#camera, width, height)
  }

  /**
   * Author mode: raycast the organ itself and report the hit.
   *
   * This is the one place a mesh raycast is correct. It asks "where on the
   * surface did the admin click", which the single mesh can answer, rather than
   * "which structure is this", which it cannot (docs/project-context.md §2.2).
   */
  #captureAuthorPoint(pointer: { x: number; y: number }): void {
    const canvas = this.#canvas
    const model = this.#model
    if (canvas === null || model === null) return

    const width = canvas.clientWidth || 1
    const height = canvas.clientHeight || 1
    const ndc = new Vector2((pointer.x / width) * 2 - 1, -(pointer.y / height) * 2 + 1)

    this.#raycaster.setFromCamera(ndc, this.#camera)
    const hit = this.#raycaster.intersectObject(model.root, true).at(0)
    if (hit === undefined) return

    // Reported in FIT_SIZE pivot space, which is the only space an
    // `anchor_position` is meaningful in (docs/architecture.md §5.4 rule 1).
    const position: Vec3 = [hit.point.x, hit.point.y, hit.point.z]
    this.#emitter.emit('author:point', { position })
  }

  #collectMaterials(root: Object3D): void {
    const records: MaterialRecord[] = []
    const seen = new Set<Material>()

    root.traverse((object) => {
      if (!(object instanceof Mesh)) return
      const materials = Array.isArray(object.material) ? object.material : [object.material]
      for (const material of materials) {
        if (seen.has(material)) continue
        seen.add(material)
        const typed = material as MaterialRecord['material']
        records.push({
          material: typed,
          color: typed.color?.clone() ?? null,
          emissive: typed.emissive?.clone() ?? null,
          opacity: typed.opacity,
          transparent: typed.transparent,
          depthWrite: typed.depthWrite,
        })
      }
    })

    this.#materials = records
  }

  #applyLayerToMaterials(): void {
    for (const record of this.#materials) {
      if (record.material.wireframe !== undefined) {
        record.material.wireframe = this.#layer === 'wireframe'
      }
    }
  }

  #setOrganOpacity(opacity: number): void {
    for (const record of this.#materials) {
      record.material.transparent = opacity < 1 || record.transparent
      record.material.opacity = opacity
      // Without this the shell writes depth at 35 % opacity and hides its own far
      // wall, so a "faded" organ still looks solid from the inside.
      record.material.depthWrite = opacity >= 1 ? record.depthWrite : false
      record.material.needsUpdate = true
    }
    this.#requestRender()
  }

  #setGroundOpacity(scale: number): void {
    const plinth = this.#plinth?.material
    if (plinth !== undefined && !Array.isArray(plinth)) {
      plinth.opacity = 0.55 * scale
    }
    const shadow = this.#contactShadow?.material
    if (shadow !== undefined && !Array.isArray(shadow)) {
      shadow.opacity = 0.55 * scale
    }
  }

  #setTint(tint: string | null): void {
    for (const record of this.#materials) {
      if (record.material.color === undefined || record.color === null) continue

      if (tint === null) {
        record.material.color.copy(record.color)
        record.material.emissive?.copy(record.emissive ?? new Color(0x000000))
        continue
      }

      const target = new Color(tint)
      // Blend rather than replace: a flat repaint destroys the baked colour map
      // that carries every anatomical cue the model has.
      record.material.color.copy(record.color).lerp(target, 0.45)
      record.material.emissive?.copy(target).multiplyScalar(0.15)
    }
    this.#requestRender()
  }

  #restoreMaterials(): void {
    for (const record of this.#materials) {
      record.material.opacity = record.opacity
      record.material.transparent = record.transparent
      record.material.depthWrite = record.depthWrite
      record.material.needsUpdate = true
    }
    this.#requestRender()
  }

  /**
   * Fades the outgoing organ out rather than popping it.
   *
   * `depthWrite` goes off for the duration so the two models do not punch holes
   * in each other while both are in the scene — the depth-prepass problem the
   * audit called out (docs/project-context.md §2.4). Skipped entirely under
   * reduced motion.
   */
  async #crossfadeOut(previous: LoadedOrganModel): Promise<void> {
    const finish = (): void => {
      this.#modelGroup.remove(previous.root)
      this.#assets.release(previous.url)
      this.#requestRender()
    }

    if (this.#reducedMotion) {
      finish()
      return
    }

    const materials: MaterialRecord['material'][] = []
    previous.root.traverse((object) => {
      if (!(object instanceof Mesh)) return
      const list = Array.isArray(object.material) ? object.material : [object.material]
      for (const material of list) materials.push(material as MaterialRecord['material'])
    })

    const state = { opacity: 1 }
    this.#tweening = true
    await new Promise<void>((resolve) => {
      gsap.to(state, {
        opacity: 0,
        duration: CROSSFADE_S,
        ease: 'power2.out',
        onUpdate: () => {
          for (const material of materials) {
            material.transparent = true
            material.depthWrite = false
            material.opacity = state.opacity
          }
          this.#requestRender()
        },
        onComplete: () => resolve(),
      })
    })
    this.#tweening = false

    // Restore before releasing: the model stays in the LRU cache and must look
    // right the next time it is shown.
    for (const material of materials) {
      material.opacity = 1
      material.transparent = false
      material.depthWrite = true
    }
    finish()
  }

  /**
   * Eases the camera to a framing. Under `prefers-reduced-motion` it jumps —
   * the destination is the point, the travel is decoration.
   */
  async #flyTo(target: Vector3, distance: number, from?: Vector3): Promise<void> {
    const controls = this.#controls
    if (controls === null) return

    const direction =
      from?.clone().normalize() ?? this.#camera.position.clone().sub(controls.target).normalize()
    if (direction.lengthSq() === 0) direction.set(0, 0, 1)

    const destination = target.clone().addScaledVector(direction, distance)

    if (this.#reducedMotion) {
      this.#camera.position.copy(destination)
      controls.target.copy(target)
      controls.update()
      this.#requestRender()
      return
    }

    gsap.killTweensOf(this.#camera.position)
    gsap.killTweensOf(controls.target)
    this.#tweening = true

    await Promise.all([
      gsap.to(this.#camera.position, {
        x: destination.x,
        y: destination.y,
        z: destination.z,
        duration: CAMERA_TWEEN_S,
        ease: 'power2.inOut',
        onUpdate: () => this.#requestRender(),
      }),
      gsap.to(controls.target, {
        x: target.x,
        y: target.y,
        z: target.z,
        duration: CAMERA_TWEEN_S,
        ease: 'power2.inOut',
      }),
    ])

    this.#tweening = false
    controls.update()
    this.#requestRender()
  }
}

function createDefaultRenderer(canvas: HTMLCanvasElement, lowPower: boolean): WebGLRenderer {
  const renderer = new WebGLRenderer({
    canvas,
    antialias: !lowPower,
    alpha: true,
    powerPreference: lowPower ? 'low-power' : 'high-performance',
  })
  // Capped at 2: beyond that the pixel count doubles for a difference nobody can
  // see on a 150k-triangle model, and phones are exactly where that hurts.
  renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio ?? 1, lowPower ? 1 : 2))
  renderer.outputColorSpace = SRGBColorSpace
  renderer.toneMapping = ACESFilmicToneMapping
  renderer.toneMappingExposure = 1
  // Local clipping, so `setCrossSection` cuts only the organ's materials and
  // leaves the plinth and markers alone.
  renderer.localClippingEnabled = true
  return renderer
}

/**
 * A minimal room for the environment map: three emissive quads and a floor. Big
 * enough to give the shell a believable specular response, small enough that
 * PMREM convolution is not a start-up cost worth measuring.
 */
function buildRoomScene(): Scene {
  const room = new Scene()
  const panels: [number, number, number, number][] = [
    [0, 4, 0, 8],
    [-4, 1, 0, 4],
    [4, 1, 0, 4],
  ]
  for (const [x, y, z, size] of panels) {
    const panel = new Mesh(
      new CircleGeometry(size / 2, 4),
      new MeshBasicMaterial({ color: 0xffffff }),
    )
    panel.position.set(x, y, z)
    panel.lookAt(0, 0, 0)
    room.add(panel)
  }
  return room
}

function createContactShadowTexture(): Texture | null {
  if (typeof document === 'undefined') return null
  const canvas = document.createElement('canvas')
  canvas.width = 128
  canvas.height = 128
  const context = canvas.getContext('2d')
  if (context === null) return null

  const gradient = context.createRadialGradient(64, 64, 0, 64, 64, 64)
  gradient.addColorStop(0, 'rgba(0,0,0,0.55)')
  gradient.addColorStop(0.6, 'rgba(0,0,0,0.22)')
  gradient.addColorStop(1, 'rgba(0,0,0,0)')
  context.fillStyle = gradient
  context.fillRect(0, 0, 128, 128)

  const texture = new Texture(canvas)
  texture.needsUpdate = true
  return texture
}

/**
 * Maps a loader error onto the failure vocabulary in `types.ts`, so the page can
 * say "we could not find that model" rather than showing an HTTP status.
 */
function classifyLoadFailure(error: unknown): {
  reason: ViewerFailureReason
  detail: string
} {
  const message = error instanceof Error ? error.message : String(error)

  if (/\b404\b|not found/i.test(message)) {
    return { reason: 'model-not-found', detail: 'The 3D model for this organ is not available.' }
  }
  if (/parse|decode|invalid|unsupported|malformed/i.test(message)) {
    return { reason: 'decode-failed', detail: 'The 3D model could not be decoded.' }
  }
  return { reason: 'network', detail: 'The 3D model could not be downloaded.' }
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => {
    globalThis.setTimeout(resolve, ms)
  })
}

function nowMs(): number {
  return typeof performance === 'undefined' ? Date.now() : performance.now()
}
