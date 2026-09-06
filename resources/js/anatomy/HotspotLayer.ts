/**
 * Hotspot markers: the working selection mechanism.
 *
 * The models are a single node, a single mesh, a single primitive, a single
 * material (docs/project-context.md §2.2). A raycast against the heart returns
 * "the heart", never "the left ventricle", so per-structure selection cannot be
 * geometric. Each structure is instead an authored coordinate in FIT_SIZE pivot
 * space, snapped onto the surface at load time and drawn as a billboard. Picking
 * is screen-space distance to a billboard.
 *
 * Reimplemented from the technique notes in docs/project-context.md §2.4 —
 * surface snapping with direction-cone filtering, occlusion fade by facing test,
 * screen-space picking, flash feedback. No upstream code was copied; the licence
 * question is open (docs/licence-log.md §4) and techniques are not copyrightable
 * (§5).
 *
 * Four techniques, each earning its complexity:
 *
 * - **Direction-cone filtering before nearest-vertex.** Nearest-vertex alone
 *   snaps an anchor authored just inside the surface onto the *far* wall of a
 *   hollow shell, putting the aortic marker behind the heart. Rejecting vertices
 *   outside a cone around the anchor's own direction from the origin removes
 *   that class of error entirely.
 * - **One linear pass.** All structures are resolved in a single walk of the
 *   position buffer, not one walk each: 150k triangles × 8 structures is the
 *   difference between 8 ms and 60 ms on a mid-range phone.
 * - **Facing test instead of a raycast.** Whether a marker is on the far side is
 *   `dot(surfaceNormal, toCamera)`. Per frame, per marker, no BVH, no raycast.
 * - **Screen-space picking.** Project the markers, measure pixels. The pointer
 *   never touches the mesh, so pick cost is independent of triangle count.
 */

import {
  Color,
  Group,
  Mesh,
  MeshBasicMaterial,
  PlaneGeometry,
  Texture,
  Vector3,
  type Camera,
  type Object3D,
  type PerspectiveCamera,
} from 'three'
import { HOTSPOT_SURFACE_OFFSET } from './constants'
import {
  MARKER_ACTIVE,
  MARKER_FLASH_CORRECT,
  MARKER_FLASH_WRONG,
  MARKER_RESTING,
  MARKER_RING,
  SHADOW_INK,
} from './palette'
import type { StructureDto, StructureId } from './types'

/**
 * cos(60°). A vertex more than 60° away from the anchor's own direction out of
 * the origin is on a different part of the shell and is not a candidate,
 * however close it happens to be in straight-line distance.
 */
const DIRECTION_CONE_COS = 0.5

/**
 * Marker size as a fraction of viewport height, held constant as the camera
 * dollies. Three's `Sprite` would give that for free, but every Sprite in the
 * process shares one module-level `BufferGeometry` that nothing can own or
 * release — `renderer.info.memory.geometries` would never return to zero, and
 * docs/architecture.md §5.4 rule 6 requires that it does. Billboarded meshes on
 * a geometry this layer allocates and frees cost one line in `update()`.
 */
const MARKER_SCREEN_FRACTION = 0.026

/**
 * The halo quad, as a multiple of the dot quad.
 *
 * Handover 15 phase 4 asks for a 2 px white ring around the dot and a soft drop
 * shadow behind it, at every state. Both live in one texture, so the geometry of
 * all three is fixed by these four numbers together; the arithmetic that ties
 * them is written out at `createHaloTexture`. Changing one without redoing that
 * sum puts a gap between the dot and its ring.
 */
const HALO_RATIO = 1.71

/**
 * The selected or highlighted marker, and how long it takes to get there.
 *
 * One ease-out, then it stops. Deliberately *not* a loop: a marker that pulses
 * for ever means every frame is a changed frame, which is the render-on-demand
 * loop's whole budget spent on decoration (docs/architecture.md §15.1).
 */
const ACTIVE_SCALE = 1.35
const ACTIVE_SCALE_IN_MS = 400

/** Below this opacity a marker is considered on the far side and unpickable. */
const PICKABLE_OPACITY = 0.35

const FLASH_MS = 900

type MarkerMesh = Mesh<PlaneGeometry, MeshBasicMaterial>

interface Marker {
  readonly structure: StructureDto
  readonly dot: MarkerMesh
  /** White ring plus drop shadow, one quad behind the dot. */
  readonly halo: MarkerMesh
  /** Post-snap world position, in FIT_SIZE pivot space. */
  readonly position: Vector3
  /** Outward surface normal at the snapped vertex; drives the facing test. */
  readonly normal: Vector3
  /** Wall-clock ms this marker became active; 0 when it is at rest. */
  activeSince: number
  /** Wall-clock ms at which a quiz flash ends; 0 when not flashing. */
  flashUntil: number
  flashCorrect: boolean
}

export interface HotspotScreenPosition {
  readonly x: number
  readonly y: number
  /** False when the marker is behind the camera or on the far side of the organ. */
  readonly visible: boolean
}

export interface HotspotLayerOptions {
  /** Suppresses the idle pulse and shortens the flash. */
  readonly reducedMotion?: boolean
  /** Invoked by the accessible structure list, which mirrors the markers. */
  readonly onIndexSelect?: (structure: StructureDto) => void
  /**
   * A structure index button gained or lost keyboard focus.
   *
   * Handover 17 requires every hover-reachable structure to stay
   * keyboard-reachable "with the same highlight on focus". Focus is the
   * keyboard's hover, so it is reported separately from selection — arrowing
   * through the list must light structures up without selecting each one on
   * the way past.
   */
  readonly onIndexFocus?: (structure: StructureDto | null) => void
}

export class HotspotLayer {
  /** Add this to the scene. Markers live here, nothing else does. */
  readonly group = new Group()

  readonly #markers = new Map<StructureId, Marker>()
  readonly #options: HotspotLayerOptions
  /** One quad, shared by every marker and owned here. See MARKER_SCREEN_FRACTION. */
  readonly #quad = new PlaneGeometry(1, 1)
  readonly #dotTexture: Texture | null
  readonly #haloTexture: Texture | null
  #indexList: HTMLUListElement | null = null
  #indexContainer: HTMLElement | null = null
  #selectedId: StructureId | null = null
  #highlightedId: StructureId | null = null
  #soloId: StructureId | null = null
  #interactive = true
  /**
   * Zero by default. An idle pulse would mean every frame is a changed frame,
   * which defeats the render-on-demand loop the whole viewer is built around
   * (docs/project-context.md §2.4). Only a simulation's `pulse_rate` directive
   * turns it on (docs/architecture.md §12).
   */
  #pulseRate = 0
  #disposed = false

  constructor(options: HotspotLayerOptions = {}) {
    this.#options = options
    this.group.name = 'hotspots'
    // Markers are UI, not anatomy: they draw over the organ and are never
    // occluded by it. Depth ordering would fight the facing test, which is the
    // thing that actually decides visibility.
    this.group.renderOrder = 10
    this.#dotTexture = createDotTexture()
    this.#haloTexture = createHaloTexture()
  }

  get structures(): readonly StructureDto[] {
    return [...this.#markers.values()].map((marker) => marker.structure)
  }

  structureById(id: StructureId): StructureDto | null {
    return this.#markers.get(id)?.structure ?? null
  }

  /**
   * Builds one marker per structure, snapping each authored coordinate onto the
   * organ's surface. Replaces whatever was attached before.
   *
   * Markers take no colour from the organ or the structure. A marker is canvas
   * chrome: it has to be legible against pale lung and dark liver alike, and it
   * has to say which of two states it is in — which a per-structure identity
   * colour cannot do, and which the audited red-dots-on-a-red-heart could not do
   * either. `--color-hotspot` at rest, `--color-hotspot-live` when active
   * (handover 15 phase 4). `StructureDto.markerColor` stays in the contract and
   * is still what the structure list and the callout key off.
   */
  attach(structures: readonly StructureDto[], organ: Object3D): void {
    this.clear()

    const snapped = snapAllToSurface(structures, organ)

    for (const structure of structures) {
      const resolved = snapped.get(structure.id)
      if (resolved === undefined) continue

      const dot = this.#createMarkerMesh(this.#dotTexture, new Color(MARKER_RESTING))
      dot.position.copy(resolved.position)
      dot.renderOrder = 11

      const halo = this.#createMarkerMesh(this.#haloTexture, new Color(MARKER_RING))
      halo.position.copy(resolved.position)
      halo.renderOrder = 10

      this.group.add(halo, dot)
      this.#markers.set(structure.id, {
        structure,
        dot,
        halo,
        position: resolved.position,
        normal: resolved.normal,
        activeSince: 0,
        flashUntil: 0,
        flashCorrect: false,
      })
    }

    this.#rebuildIndex()
  }

  /**
   * Mirrors the markers as a real list of buttons so the 3D interaction has a
   * keyboard and screen-reader equivalent (PRD §31, docs/engineering.md §11.8).
   */
  mountIndex(container: HTMLElement): void {
    this.#indexContainer = container
    this.#rebuildIndex()
  }

  setInteractive(interactive: boolean): void {
    this.#interactive = interactive
    for (const marker of this.#markers.values()) {
      marker.dot.visible = interactive
      marker.halo.visible = interactive
    }
  }

  setSelected(id: StructureId | null): void {
    this.#selectedId = id
    this.#stampActive()
    this.#syncIndexPressedState()
  }

  setHighlighted(id: StructureId | null): void {
    this.#highlightedId = id
    this.#stampActive()
  }

  /** Isolation: everything except `id` dims. Null restores all markers. */
  setSolo(id: StructureId | null): void {
    this.#soloId = id
  }

  /** Simulation directive `pulse_rate` (docs/architecture.md §12). */
  setPulseRate(rate: number): void {
    this.#pulseRate = Math.max(0, rate)
  }

  /**
   * Quiz feedback. Green for correct, red for wrong. The caller is responsible
   * for also flashing the correct structure green after a wrong answer — that
   * is an assessment decision, not a rendering one.
   */
  flash(id: StructureId, correct: boolean): number {
    const marker = this.#markers.get(id)
    if (marker === undefined) return 0
    const duration = this.#options.reducedMotion === true ? FLASH_MS / 2 : FLASH_MS
    marker.flashUntil = now() + duration
    marker.flashCorrect = correct
    return duration
  }

  /**
   * True while a flash or an activation scale-in is still running, so the render
   * loop stays awake for exactly as long as something is moving and not one
   * frame longer.
   */
  get isAnimating(): boolean {
    if (this.#pulseRate > 0 && this.#options.reducedMotion !== true) return this.#markers.size > 0

    const current = now()
    const easing = this.#options.reducedMotion !== true

    for (const marker of this.#markers.values()) {
      if (marker.flashUntil > current) return true
      if (easing && marker.activeSince > 0 && current - marker.activeSince < ACTIVE_SCALE_IN_MS) {
        return true
      }
    }
    return false
  }

  /**
   * Per-frame marker state: occlusion fade, selection emphasis, flash colour.
   *
   * `dot(normal, toCamera)` is the whole occlusion model. It is exact for a
   * convex shell and close enough for these organs, and it costs one dot product
   * per marker instead of a raycast into a 150k-triangle mesh.
   */
  update(camera: PerspectiveCamera): void {
    if (this.#markers.size === 0) return

    const current = now()
    const cameraPosition = camera.getWorldPosition(new Vector3())
    const toCamera = new Vector3()
    // World units per unit of screen height, at one unit of distance. Multiplying
    // by the marker's own distance is what keeps it the same size on screen
    // however far the camera dollies.
    const unitsPerScreenHeight = 2 * Math.tan((camera.fov * Math.PI) / 360)

    for (const marker of this.#markers.values()) {
      toCamera.subVectors(cameraPosition, marker.position).normalize()
      const facing = marker.normal.dot(toCamera)

      let opacity = smoothstep(-0.2, 0.25, facing)
      // Never fully invisible: a faded dot still tells the student there is
      // something on the other side worth rotating to.
      opacity = 0.08 + opacity * 0.92

      const dimmed = this.#soloId !== null && this.#soloId !== marker.structure.id
      if (dimmed) opacity *= 0.15

      const active = marker.activeSince > 0
      const flashing = marker.flashUntil > current

      const material = marker.dot.material
      material.opacity = opacity
      material.color.set(active ? MARKER_ACTIVE : MARKER_RESTING)

      const distance = cameraPosition.distanceTo(marker.position)
      const screenUnit = unitsPerScreenHeight * distance

      let scale = MARKER_SCREEN_FRACTION * this.#activeScaleOf(marker, current)

      if (flashing) {
        const remaining = (marker.flashUntil - current) / FLASH_MS
        material.color.set(marker.flashCorrect ? MARKER_FLASH_CORRECT : MARKER_FLASH_WRONG)
        scale *= 1 + 0.35 * remaining
      } else if (!dimmed && this.#options.reducedMotion !== true && this.#pulseRate > 0) {
        // A slow breath so a static screenshot and a live screen look the same,
        // and so the dots read as interactive without demanding attention.
        scale *= 1 + 0.06 * Math.sin((current / 900) * this.#pulseRate)
      }

      marker.dot.scale.setScalar(scale * screenUnit)
      marker.dot.quaternion.copy(camera.quaternion)

      // The halo carries the white ring and the drop shadow, and it is white at
      // every state — it is what makes an orange dot legible on orange tissue,
      // so tinting it with the state colour would undo its only job.
      marker.halo.material.opacity = opacity
      marker.halo.scale.setScalar(scale * HALO_RATIO * screenUnit)
      marker.halo.quaternion.copy(camera.quaternion)
    }
  }

  /**
   * Where a marker is in its one-shot activation ease.
   *
   * Reduced motion lands on the end state immediately: the size difference is
   * the signal, the 400 ms getting there is not.
   */
  #activeScaleOf(marker: Marker, current: number): number {
    if (marker.activeSince === 0) return 1
    if (this.#options.reducedMotion === true) return ACTIVE_SCALE

    const progress = Math.min(1, (current - marker.activeSince) / ACTIVE_SCALE_IN_MS)
    return 1 + (ACTIVE_SCALE - 1) * easeOutCubic(progress)
  }

  /**
   * Starts the activation ease on whichever markers just became active, and
   * clears it on the rest.
   *
   * Stamped here rather than in `update()` so the ease is timed from the
   * selection, not from the first frame that happens to follow it — and so a
   * marker that is already active does not restart when the *other* of selection
   * and highlight lands on it.
   */
  #stampActive(): void {
    const current = now()
    for (const marker of this.#markers.values()) {
      const active =
        marker.structure.id === this.#selectedId || marker.structure.id === this.#highlightedId

      if (!active) {
        marker.activeSince = 0
      } else if (marker.activeSince === 0) {
        marker.activeSince = current
      }
    }
  }

  /**
   * Nearest marker to a pointer position, in pixels.
   *
   * Front-most wins on a tie so a dot on the near side is always preferred over
   * one behind it, and markers the facing test has faded out are not pickable —
   * clicking through the organ to its far side is never what the student meant.
   */
  pick(
    x: number,
    y: number,
    camera: Camera,
    width: number,
    height: number,
    radius = 24,
  ): StructureDto | null {
    if (!this.#interactive) return null

    let best: StructureDto | null = null
    let bestDistance = radius * radius
    let bestDepth = Number.POSITIVE_INFINITY
    const projected = new Vector3()

    for (const marker of this.#markers.values()) {
      if (marker.dot.material.opacity < PICKABLE_OPACITY) continue

      projected.copy(marker.position).project(camera)
      if (projected.z > 1) continue

      const screenX = ((projected.x + 1) / 2) * width
      const screenY = ((1 - projected.y) / 2) * height
      const distance = (screenX - x) ** 2 + (screenY - y) ** 2

      if (distance > bestDistance) continue
      if (distance === bestDistance && projected.z >= bestDepth) continue

      best = marker.structure
      bestDistance = distance
      bestDepth = projected.z
    }

    return best
  }

  /**
   * Where a marker currently sits on screen, so the host page can anchor a
   * callout to it imperatively — no framework re-render while the model spins
   * (docs/project-context.md §2.4).
   */
  screenPosition(
    id: StructureId,
    camera: Camera,
    width: number,
    height: number,
  ): HotspotScreenPosition | null {
    const marker = this.#markers.get(id)
    if (marker === undefined) return null

    const projected = marker.position.clone().project(camera)
    return {
      x: ((projected.x + 1) / 2) * width,
      y: ((1 - projected.y) / 2) * height,
      visible: projected.z <= 1 && marker.dot.material.opacity >= PICKABLE_OPACITY,
    }
  }

  /** World position of a marker, for camera fly-to. */
  positionOf(id: StructureId): Vector3 | null {
    const marker = this.#markers.get(id)
    return marker === undefined ? null : marker.position.clone()
  }

  clear(): void {
    for (const marker of this.#markers.values()) {
      this.group.remove(marker.dot, marker.halo)
      // `material.dispose()`, not `disposeMaterial()`: the maps are shared across
      // every marker and owned by this layer, so they are freed once in
      // dispose(), not once per marker here.
      marker.dot.material.dispose()
      marker.halo.material.dispose()
    }
    this.#markers.clear()
    this.#selectedId = null
    this.#highlightedId = null
    this.#soloId = null
    this.#rebuildIndex()
  }

  dispose(): void {
    if (this.#disposed) return
    this.#disposed = true

    this.clear()
    // The shared quad and marker textures outlive individual organs, so they are
    // freed here rather than in clear().
    this.#quad.dispose()
    this.#dotTexture?.dispose()
    this.#haloTexture?.dispose()
    this.#indexList?.remove()
    this.#indexList = null
    this.#indexContainer = null
    this.group.removeFromParent()
  }

  #createMarkerMesh(map: Texture | null, color: Color): MarkerMesh {
    const mesh = new Mesh(
      this.#quad,
      new MeshBasicMaterial({
        map,
        color,
        opacity: 1,
        transparent: true,
        // Markers are UI drawn over the organ. Occlusion is decided by the facing
        // test in update(), not by the depth buffer — that is the whole point of
        // not raycasting the mesh every frame.
        depthTest: false,
        depthWrite: false,
        toneMapped: false,
      }),
    )
    mesh.frustumCulled = false
    return mesh
  }

  #rebuildIndex(): void {
    const container = this.#indexContainer
    if (container === null) return

    this.#indexList?.remove()
    if (this.#markers.size === 0) {
      this.#indexList = null
      return
    }

    const list = container.ownerDocument.createElement('ul')
    list.className = 'hotspot-index'
    list.setAttribute('aria-label', 'Structures in this model')

    for (const marker of this.#markers.values()) {
      const item = container.ownerDocument.createElement('li')
      const button = container.ownerDocument.createElement('button')
      button.type = 'button'
      button.dataset.structureId = marker.structure.id
      button.textContent = marker.structure.name
      button.setAttribute('aria-pressed', String(marker.structure.id === this.#selectedId))
      button.addEventListener('click', () => this.#options.onIndexSelect?.(marker.structure))
      button.addEventListener('focus', () => this.#options.onIndexFocus?.(marker.structure))
      button.addEventListener('blur', () => this.#options.onIndexFocus?.(null))
      item.append(button)
      list.append(item)
    }

    container.append(list)
    this.#indexList = list
  }

  #syncIndexPressedState(): void {
    const buttons = this.#indexList?.querySelectorAll('button') ?? []
    for (const button of buttons) {
      button.setAttribute('aria-pressed', String(button.dataset.structureId === this.#selectedId))
    }
  }
}

interface SnapResult {
  readonly position: Vector3
  readonly normal: Vector3
}

/**
 * Resolves every authored anchor onto the nearest surface vertex, in one pass.
 *
 * Exported for its own test: this is the function that decides whether a marker
 * lands on the structure the author clicked or on the wall behind it.
 */
export function snapAllToSurface(
  structures: readonly StructureDto[],
  organ: Object3D,
): Map<StructureId, SnapResult> {
  const results = new Map<StructureId, SnapResult>()
  if (structures.length === 0) return results

  const anchors = structures.map((structure) => ({
    id: structure.id,
    anchor: new Vector3(...structure.anchorPosition),
    direction: new Vector3(...structure.anchorPosition).normalize(),
  }))

  const bestSquared = new Array<number>(anchors.length).fill(Number.POSITIVE_INFINITY)
  const bestVertex = anchors.map(() => new Vector3())
  const bestNormal = anchors.map(() => new Vector3())
  // Fallback state, used only for an anchor whose cone catches no vertex at all —
  // an anchor authored against a different model revision, say. Snapping it to
  // the globally nearest vertex is wrong but visible; dropping the marker
  // silently is wrong and invisible.
  const fallbackSquared = new Array<number>(anchors.length).fill(Number.POSITIVE_INFINITY)
  const fallbackVertex = anchors.map(() => new Vector3())
  const fallbackNormal = anchors.map(() => new Vector3())

  const vertex = new Vector3()
  const normal = new Vector3()
  const direction = new Vector3()

  organ.updateWorldMatrix(true, true)
  organ.traverse((object) => {
    if (!(object instanceof Mesh)) return

    const position = object.geometry.getAttribute('position')
    if (position === undefined) return
    const normals = object.geometry.getAttribute('normal')

    for (let index = 0; index < position.count; index += 1) {
      vertex.fromBufferAttribute(position, index).applyMatrix4(object.matrixWorld)

      if (normals === undefined) {
        // No normals in the buffer: treat the outward radial direction as the
        // surface normal. Correct for a roughly convex shell, which every one of
        // these models is.
        normal.copy(vertex).normalize()
      } else {
        normal
          .fromBufferAttribute(normals, index)
          .transformDirection(object.matrixWorld)
          .normalize()
      }

      direction.copy(vertex).normalize()

      for (let a = 0; a < anchors.length; a += 1) {
        const candidate = anchors[a]
        if (candidate === undefined) continue

        const distance = vertex.distanceToSquared(candidate.anchor)

        if (distance < (fallbackSquared[a] ?? Number.POSITIVE_INFINITY)) {
          fallbackSquared[a] = distance
          fallbackVertex[a]?.copy(vertex)
          fallbackNormal[a]?.copy(normal)
        }

        if (direction.dot(candidate.direction) < DIRECTION_CONE_COS) continue
        if (distance >= (bestSquared[a] ?? Number.POSITIVE_INFINITY)) continue

        bestSquared[a] = distance
        bestVertex[a]?.copy(vertex)
        bestNormal[a]?.copy(normal)
      }
    }
  })

  for (let a = 0; a < anchors.length; a += 1) {
    const candidate = anchors[a]
    if (candidate === undefined) continue

    const inCone = Number.isFinite(bestSquared[a] ?? Number.POSITIVE_INFINITY)
    const hasFallback = Number.isFinite(fallbackSquared[a] ?? Number.POSITIVE_INFINITY)

    let surface: Vector3
    let surfaceNormal: Vector3

    if (inCone) {
      surface = bestVertex[a] ?? candidate.anchor
      surfaceNormal = bestNormal[a] ?? candidate.direction
    } else if (hasFallback) {
      surface = fallbackVertex[a] ?? candidate.anchor
      surfaceNormal = fallbackNormal[a] ?? candidate.direction
    } else {
      // No geometry at all — a model that failed to decode. Keep the authored
      // coordinate so the structure list still works.
      surface = candidate.anchor
      surfaceNormal = candidate.direction
    }

    const resolvedNormal = surfaceNormal.clone()
    if (resolvedNormal.lengthSq() === 0) resolvedNormal.copy(candidate.direction)

    results.set(candidate.id, {
      position: surface.clone().addScaledVector(resolvedNormal, HOTSPOT_SURFACE_OFFSET),
      normal: resolvedNormal,
    })
  }

  return results
}

function smoothstep(edge0: number, edge1: number, value: number): number {
  const t = Math.min(1, Math.max(0, (value - edge0) / (edge1 - edge0)))
  return t * t * (3 - 2 * t)
}

function easeOutCubic(t: number): number {
  return 1 - (1 - t) ** 3
}

function now(): number {
  return typeof performance === 'undefined' ? Date.now() : performance.now()
}

/*
 | Marker art is generated rather than shipped: two small canvases beat a sprite
 | sheet request, and each is drawn white so the state colour can be applied as a
 | material tint at runtime. Both builders return null where 2D canvas is
 | unavailable (jsdom without node-canvas), in which case the quad falls back to
 | a flat colour.
 */

const TEXTURE_SIZE = 64
const TEXTURE_CENTRE = TEXTURE_SIZE / 2

/**
 * The filled dot. Solid to 90 % of its radius, then one texel of falloff so the
 * edge is antialiased at any zoom rather than stair-stepped.
 */
const DOT_RADIUS = 26

/**
 * The white ring and the drop shadow, in the halo texture's own pixels.
 *
 * These four numbers are one sum, and it is the reason `HALO_RATIO` is 1.71
 * rather than a round number. Working in screen pixels on a 600 px-tall canvas:
 *
 *   dot quad          0.026 × 600            = 15.6 px
 *   dot diameter      15.6 × (2 × 26 / 64)   = 12.7 px, so a radius of 6.34 px
 *   halo quad         15.6 × 1.71            = 26.7 px, so a half-width of 13.3 px
 *   ring outer edge   (17.5 + 4.7 / 2) / 32 × 13.3 = 8.25 px
 *   ring inner edge   (17.5 - 4.7 / 2) / 32 × 13.3 = 6.31 px  ← meets the dot
 *   ring width                                      = 1.94 px ← handover's 2 px
 *
 * The shadow then has the remaining 13.3 − 8.25 px of the quad to fade out in.
 */
const RING_RADIUS = 17.5
const RING_WIDTH = 4.7
const SHADOW_CENTRE_ALPHA = 0.34
const SHADOW_DROP = 2

function createDotTexture(): Texture | null {
  const context = createCanvasContext(TEXTURE_SIZE)
  if (context === null) return null

  const gradient = context.createRadialGradient(
    TEXTURE_CENTRE,
    TEXTURE_CENTRE,
    0,
    TEXTURE_CENTRE,
    TEXTURE_CENTRE,
    DOT_RADIUS,
  )
  gradient.addColorStop(0, 'rgba(255,255,255,1)')
  gradient.addColorStop(0.9, 'rgba(255,255,255,1)')
  gradient.addColorStop(1, 'rgba(255,255,255,0)')
  context.fillStyle = gradient
  context.fillRect(0, 0, TEXTURE_SIZE, TEXTURE_SIZE)

  return canvasToTexture(context)
}

/**
 * Drop shadow first, white ring over it, both in one texture.
 *
 * One quad rather than two because the material tint has to stay white for both:
 * the shadow is drawn dark and the ring white, and multiplying either by white
 * leaves it as drawn. Splitting them would cost a third draw call per marker for
 * no visual difference.
 *
 * The shadow sits a couple of texels low, which is what makes the marker read as
 * floating off the surface rather than painted onto it, and it is warm rather
 * than neutral because every shadow in this palette is.
 */
function createHaloTexture(): Texture | null {
  const context = createCanvasContext(TEXTURE_SIZE)
  if (context === null) return null

  const gradient = context.createRadialGradient(
    TEXTURE_CENTRE,
    TEXTURE_CENTRE + SHADOW_DROP,
    0,
    TEXTURE_CENTRE,
    TEXTURE_CENTRE + SHADOW_DROP,
    TEXTURE_CENTRE,
  )
  const ink = new Color(SHADOW_INK)
  const channels = `${String(Math.round(ink.r * 255))},${String(
    Math.round(ink.g * 255),
  )},${String(Math.round(ink.b * 255))}`

  gradient.addColorStop(0, `rgba(${channels},${String(SHADOW_CENTRE_ALPHA)})`)
  gradient.addColorStop(0.55, `rgba(${channels},${String(SHADOW_CENTRE_ALPHA * 0.4)})`)
  gradient.addColorStop(1, `rgba(${channels},0)`)
  context.fillStyle = gradient
  context.fillRect(0, 0, TEXTURE_SIZE, TEXTURE_SIZE)

  context.strokeStyle = 'rgba(255,255,255,1)'
  context.lineWidth = RING_WIDTH
  context.beginPath()
  context.arc(TEXTURE_CENTRE, TEXTURE_CENTRE, RING_RADIUS, 0, Math.PI * 2)
  context.stroke()

  return canvasToTexture(context)
}

function createCanvasContext(size: number): CanvasRenderingContext2D | null {
  if (typeof document === 'undefined') return null
  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size
  return canvas.getContext('2d')
}

function canvasToTexture(context: CanvasRenderingContext2D): Texture {
  const texture = new Texture(context.canvas)
  texture.needsUpdate = true
  return texture
}
