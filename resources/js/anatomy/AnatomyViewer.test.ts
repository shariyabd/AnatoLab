import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ACESFilmicToneMapping,
  Box3,
  Color,
  Light,
  Mesh,
  MeshBasicMaterial,
  MeshPhysicalMaterial,
  SRGBColorSpace,
  SphereGeometry,
  Vector3,
  type Object3D,
  type Scene,
} from 'three'
import { AnatomyViewer } from './AnatomyViewer'
import { FIT_SIZE } from './constants'
import { GUIDED_TOUR_ID } from './animation'
import type { CapabilityDegraded, OrganDto, ViewerOptions } from './types'
import { FakeWebGLRenderer, asRenderer, liveRenderers } from './testing/fakeRenderer'
import { createFixtureLoader, createOrgan, type FixtureLoader } from './testing/fixtures'
import {
  createSizedContainer,
  firePointer,
  restoreCanvasContext,
  sizeCanvas,
  stubPointerCapture,
  stubWebGLSupport,
  stubWebGLUnavailable,
} from './testing/dom'

interface Harness {
  viewer: AnatomyViewer
  renderer: FakeWebGLRenderer
  container: HTMLElement
  canvas: HTMLCanvasElement
  loader: FixtureLoader
}

const WIDTH = 800
const HEIGHT = 600

function mountViewer(
  options: ViewerOptions = { reducedMotion: true },
  loaderOptions: Parameters<typeof createFixtureLoader>[0] = {},
): Harness {
  const container = createSizedContainer(WIDTH, HEIGHT)
  const loader = createFixtureLoader(loaderOptions)
  let renderer: FakeWebGLRenderer | null = null

  const viewer = new AnatomyViewer(container, options, {
    createRenderer: (canvas) => {
      renderer = new FakeWebGLRenderer(canvas)
      return asRenderer(renderer)
    },
    createLoader: () => loader,
  })

  const canvas = container.querySelector('canvas')!
  sizeCanvas(canvas, WIDTH, HEIGHT)

  return { viewer, renderer: renderer!, container, canvas, loader }
}

/** Waits for the render-on-demand loop to actually draw at least one frame. */
async function nextFrame(renderer: FakeWebGLRenderer): Promise<void> {
  const before = renderer.info.render.calls
  await vi.waitFor(() => expect(renderer.info.render.calls).toBeGreaterThan(before))
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Waits out the post-interaction settle window and returns the frame count the
 * viewer went quiet on. Any camera change keeps the loop awake for a short while
 * so orbit damping is visible; "settled" means that window has lapsed.
 */
async function settle(renderer: FakeWebGLRenderer): Promise<number> {
  await sleep(600)
  return renderer.info.render.calls
}

/**
 * The scene the viewer actually drew, captured by the fake renderer. The scene
 * graph is private, and going through the thing that renders it is the only way
 * to assert on it without opening a hole in the class for a test.
 */
async function drawnScene(harness: Harness): Promise<Object3D> {
  await nextFrame(harness.renderer)
  const scene = harness.renderer.scene
  expect(scene).not.toBeNull()
  return scene!
}

/** Every mesh the viewer put in the scene itself, excluding organ and markers. */
function stageMeshes(scene: Object3D): Mesh[] {
  return scene.children.filter((child): child is Mesh => child instanceof Mesh)
}

function organMaterial(harness: Harness): MeshPhysicalMaterial {
  const model = harness.loader.models[0]!
  return (model.children[0] as Mesh<SphereGeometry, MeshPhysicalMaterial>).material
}

function saturationOf(color: Color): number {
  const hsl = { h: 0, s: 0, l: 0 }
  color.getHSL(hsl)
  return hsl.s
}

describe('AnatomyViewer', () => {
  let harness: Harness | null = null

  beforeEach(() => {
    liveRenderers.count = 0
    stubWebGLSupport()
    stubPointerCapture()
  })

  afterEach(() => {
    harness?.viewer.dispose()
    harness?.container.remove()
    harness = null
    restoreCanvasContext()
    document.body.replaceChildren()
  })

  describe('loading', () => {
    it('reports progress and then the loaded organ', async () => {
      harness = mountViewer()
      const progress = vi.fn()
      const loaded = vi.fn()
      harness.viewer.on('load:progress', progress)
      harness.viewer.on('organ:loaded', loaded)

      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)

      expect(progress).toHaveBeenCalledWith({ loaded: 512, total: 1024 })
      expect(loaded).toHaveBeenCalledOnce()
      const payload = loaded.mock.calls[0]![0] as { organ: OrganDto; triangles: number }
      expect(payload.organ.id).toBe(organ.id)
      expect(payload.triangles).toBeGreaterThan(0)
    })

    it('emits load:failed rather than rejecting, so the page can fall back to text', async () => {
      // docs/architecture.md §5.4 rule 5: every load path has a failure path, and
      // the structure list stays usable without 3D.
      harness = mountViewer({ reducedMotion: true }, { failWith: new Error('404 Not Found') })
      const failed = vi.fn()
      harness.viewer.on('load:failed', failed)

      await expect(harness.viewer.loadOrgan(createOrgan())).resolves.toBeUndefined()

      expect(failed).toHaveBeenCalledWith({
        reason: 'model-not-found',
        detail: expect.stringContaining('not available'),
      })
    })

    it('classifies a decode failure separately from a network failure', async () => {
      harness = mountViewer(
        { reducedMotion: true },
        { failWith: new Error('Unsupported glTF: malformed chunk') },
      )
      const failed = vi.fn()
      harness.viewer.on('load:failed', failed)

      await harness.viewer.loadOrgan(createOrgan())

      expect(failed.mock.calls[0]![0]).toMatchObject({ reason: 'decode-failed' })
    })

    it('normalises the model into the FIT_SIZE cube before hotspots attach', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())

      const structure = createOrgan().structures[0]!
      const position = harness.viewer.getStructureScreenPosition(structure.id)

      // A marker only projects onto the canvas if the model was scaled into the
      // camera's framing, which is FIT_SIZE and nothing else.
      expect(position).not.toBeNull()
      expect(position!.x).toBeGreaterThan(0)
      expect(position!.x).toBeLessThan(WIDTH)
    })
  })

  describe('structure identity', () => {
    it('round-trips an opaque id from loadOrgan through selectStructure to the event', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      const selected = vi.fn()
      harness.viewer.on('structure:selected', selected)

      await harness.viewer.loadOrgan(organ)
      const id = organ.structures[1]!.id
      harness.viewer.selectStructure(id)

      expect(harness.viewer.getSelectedStructure()?.id).toBe(id)
      expect(selected).toHaveBeenLastCalledWith({ structure: expect.objectContaining({ id }) })
    })

    it('clears the selection for an id it does not know', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const selected = vi.fn()
      harness.viewer.on('structure:selected', selected)

      harness.viewer.selectStructure('str_not_in_this_organ')

      expect(harness.viewer.getSelectedStructure()).toBeNull()
      expect(selected).toHaveBeenCalledWith({ structure: null })
    })
  })

  describe('modes', () => {
    it('selects on click in explore mode', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)

      const id = organ.structures[0]!.id
      const at = harness.viewer.getStructureScreenPosition(id)!
      const picked = vi.fn()
      harness.viewer.on('structure:picked', picked)

      firePointer(harness.canvas, 'pointerdown', at)
      firePointer(harness.canvas, 'pointerup', at)

      expect(picked).toHaveBeenCalledOnce()
      expect(harness.viewer.getSelectedStructure()?.id).toBe(id)
    })

    it('reports a pick without selecting in quiz mode', async () => {
      // A sticky highlight on the structure just guessed would give the answer away.
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      harness.viewer.setMode('quiz')

      const id = organ.structures[0]!.id
      const at = harness.viewer.getStructureScreenPosition(id)!
      const picked = vi.fn()
      harness.viewer.on('structure:picked', picked)

      firePointer(harness.canvas, 'pointerdown', at)
      firePointer(harness.canvas, 'pointerup', at)

      expect(picked).toHaveBeenCalledOnce()
      expect(harness.viewer.getSelectedStructure()).toBeNull()
    })

    it('treats a drag as a camera move, not a click', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const at = harness.viewer.getStructureScreenPosition(organ.structures[0]!.id)!
      const picked = vi.fn()
      harness.viewer.on('structure:picked', picked)

      firePointer(harness.canvas, 'pointerdown', at)
      firePointer(harness.canvas, 'pointerup', { x: at.x + 60, y: at.y + 40 })

      expect(picked).not.toHaveBeenCalled()
    })

    it('emits author:point in FIT_SIZE pivot space in author mode', async () => {
      // This is Handover 13's hotspot authoring tool
      // (docs/handovers/parallel-execution-plan.md D6).
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      harness.viewer.setMode('author')

      const point = vi.fn()
      harness.viewer.on('author:point', point)

      const centre = { x: WIDTH / 2, y: HEIGHT / 2 }
      firePointer(harness.canvas, 'pointerdown', centre)
      firePointer(harness.canvas, 'pointerup', centre)

      expect(point).toHaveBeenCalledOnce()
      const [{ position }] = point.mock.calls[0] as [{ position: readonly number[] }]
      const radius = Math.hypot(...position)
      expect(radius).toBeCloseTo(FIT_SIZE / 2, 1)
      expect(harness.canvas.style.cursor).toBe('crosshair')
    })

    it('stops markers responding in author mode', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const at = harness.viewer.getStructureScreenPosition(organ.structures[0]!.id)!

      harness.viewer.setMode('author')
      const picked = vi.fn()
      harness.viewer.on('structure:picked', picked)

      firePointer(harness.canvas, 'pointerdown', at)
      firePointer(harness.canvas, 'pointerup', at)

      expect(picked).not.toHaveBeenCalled()
    })

    it('drops the selection when leaving explore mode', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      harness.viewer.selectStructure(organ.structures[0]!.id)

      harness.viewer.setMode('mission')

      expect(harness.viewer.getSelectedStructure()).toBeNull()
      expect(harness.viewer.getMode()).toBe('mission')
    })
  })

  describe('reduced semantics', () => {
    function captureDegraded(viewer: AnatomyViewer): CapabilityDegraded[] {
      const seen: CapabilityDegraded[] = []
      viewer.on('capability:degraded', (payload) => seen.push(payload))
      return seen
    }

    it('announces that isolate cannot hide geometry on a single-mesh model', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const degraded = captureDegraded(harness.viewer)

      harness.viewer.isolateStructure(organ.structures[0]!.id)

      expect(degraded).toEqual([
        { capability: 'isolate', reason: expect.stringContaining('single mesh') },
      ])
    })

    it('fades the organ rather than hiding it, and restores on release', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const material = materialOf(harness)

      harness.viewer.isolateStructure(organ.structures[0]!.id)
      expect(material.opacity).toBeCloseTo(0.35, 5)
      expect(material.transparent).toBe(true)

      harness.viewer.isolateStructure(null)
      expect(material.opacity).toBe(1)
    })

    it('announces that layers means wireframe', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const degraded = captureDegraded(harness.viewer)

      harness.viewer.setLayer('wireframe')

      expect(materialOf(harness).wireframe).toBe(true)
      expect(degraded[0]).toMatchObject({ capability: 'layers' })
    })

    it('announces that a section is a whole-organ cut through a hollow shell', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const degraded = captureDegraded(harness.viewer)

      harness.viewer.setLayer('section')

      expect(materialOf(harness).clippingPlanes).toHaveLength(1)
      expect(degraded.map((entry) => entry.capability)).toContain('crossSection')
    })

    it('clears the clipping plane when leaving the section layer', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())

      harness.viewer.setLayer('section')
      harness.viewer.setLayer('solid')

      expect(materialOf(harness).clippingPlanes).toBeNull()
    })

    it('announces that a triggered animation is choreography, not a clip', async () => {
      // gltf.animations.length is 0 for every model in the set
      // (docs/project-context.md §2.2).
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const degraded = captureDegraded(harness.viewer)

      await harness.viewer.triggerAnimation(GUIDED_TOUR_ID, [{ focus: undefined, holdMs: 0 }])

      expect(degraded[0]).toMatchObject({ capability: 'animation' })
      expect(degraded[0]!.reason).toContain('no animation clips')
    })

    it('says so plainly when there is no choreography for the id at all', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const degraded = captureDegraded(harness.viewer)

      await harness.viewer.triggerAnimation('systole')

      expect(degraded).toHaveLength(1)
      expect(degraded[0]!.reason).toContain('systole')
    })

    it('walks every structure for the built-in tour', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)

      // Reduced motion collapses the holds to zero, so the tour completes
      // synchronously enough to assert on.
      await harness.viewer.triggerAnimation(GUIDED_TOUR_ID)

      expect(harness.viewer.getSelectedStructure()).toBeNull()
    })
  })

  describe('simulations', () => {
    it('honours the five permitted visual directives', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const material = materialOf(harness)
      const original = material.color.getHex()

      harness.viewer.applySimulationState({
        state: { output: 0.75 },
        visualDirectives: {
          highlight: organ.structures[0]!.id,
          tint: '#0000ff',
          pulseRate: 1.35,
          focus: organ.structures[0]!.id,
          crossSection: { enabled: true, axis: 'y', offset: 0.2 },
        },
      })

      expect(material.color.getHex()).not.toBe(original)
      expect(material.clippingPlanes).toHaveLength(1)
      expect(harness.viewer.getSelectedStructure()?.id).toBe(organ.structures[0]!.id)
    })

    it('ignores a directive outside the closed vocabulary', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const material = materialOf(harness)

      harness.viewer.applySimulationState({
        state: {},
        visualDirectives: { deform: 0.5, hide: 'str_left_ventricle' } as never,
      })

      expect(material.opacity).toBe(1)
      expect(material.clippingPlanes ?? null).toBeNull()
    })

    it('restores the material when the tint is cleared', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const material = materialOf(harness)
      const original = material.color.getHex()

      harness.viewer.applySimulationState({ state: {}, visualDirectives: { tint: '#0000ff' } })
      harness.viewer.applySimulationState({ state: {}, visualDirectives: { tint: null } })

      expect(material.color.getHex()).toBe(original)
    })
  })

  describe('reduced motion', () => {
    it('refuses to auto-rotate and moves the camera without easing', async () => {
      // The audited implementation honoured neither and auto-rotated by default
      // (docs/project-context.md §5.1).
      harness = mountViewer({ reducedMotion: true })
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)

      harness.viewer.setAutoRotate(true)
      harness.viewer.focusStructure(organ.structures[2]!.id)

      // Camera arrival is immediate: the marker is already near the centre of
      // frame with no tween to wait for.
      const arrived = harness.viewer.getStructureScreenPosition(organ.structures[2]!.id)
      expect(Math.abs(arrived!.x - WIDTH / 2)).toBeLessThan(WIDTH / 4)

      // And with auto-rotate refused, the viewer goes quiet once the damping
      // settle window has lapsed.
      const idle = await settle(harness.renderer)
      await sleep(150)
      expect(harness.renderer.info.render.calls).toBe(idle)
    })

    it('auto-rotates and keeps drawing when motion is allowed', async () => {
      harness = mountViewer({ reducedMotion: false })
      await harness.viewer.loadOrgan(createOrgan())

      harness.viewer.setAutoRotate(true)
      await nextFrame(harness.renderer)
      await nextFrame(harness.renderer)
    })
  })

  describe('render quality', () => {
    it('configures the colour pipeline on whatever renderer it was handed', async () => {
      // Handover 15 phase 6. These are a property of the image the viewer
      // promises, not of how the context happened to be created, so a host that
      // supplies its own renderer gets the same one.
      harness = mountViewer()

      expect(harness.renderer.outputColorSpace).toBe(SRGBColorSpace)
      expect(harness.renderer.toneMapping).toBe(ACESFilmicToneMapping)
      expect(harness.renderer.toneMappingExposure).toBeCloseTo(1.05, 5)
      expect(harness.renderer.localClippingEnabled).toBe(true)
    })

    it('stands the organ on a soft contact shadow and nothing else', async () => {
      // The hard grey disc this replaced was the most prominent thing on the
      // stage whenever a model had not loaded (handover 15 phase 3).
      stubWebGLSupport({ twoD: true })
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())

      const meshes = stageMeshes(await drawnScene(harness))
      expect(meshes).toHaveLength(1)

      const shadow = meshes[0]!
      const material = shadow.material as MeshBasicMaterial
      expect(shadow.name).toBe('contact-shadow')
      expect(material.transparent).toBe(true)
      expect(material.map).not.toBeNull()
      expect(material.depthWrite).toBe(false)
    })

    it('fits the shadow to the organ standing on it', async () => {
      stubWebGLSupport({ twoD: true })
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())

      const shadow = stageMeshes(await drawnScene(harness))[0]!
      const bounds = new Box3().setFromObject(harness.loader.models[0]!)
      const size = bounds.getSize(new Vector3())

      // Wider than the organ, so it reads as contact rather than as a decal.
      expect(shadow.scale.x).toBeGreaterThan(size.x)
      expect(shadow.scale.y).toBeGreaterThan(size.z)
      expect(shadow.position.y).toBeLessThanOrEqual(bounds.min.y)
    })

    it('keeps the shadow off screen until there is a model to cast it', async () => {
      // The canvas is transparent over a white card, so a shadow with nothing
      // above it is just a grey lens laid over the paper.
      stubWebGLSupport({ twoD: true })
      harness = mountViewer()

      const shadow = stageMeshes(await drawnScene(harness))[0]!
      expect(shadow.visible).toBe(false)
    })

    it('builds the lighting rig and its environment once, not once per organ', async () => {
      // PMREM convolution is the most expensive thing that happens at start-up
      // and the room is the same room for every organ, so handover 15 phase 6
      // asks for this to be verified rather than assumed.
      //
      // What is verified here is the rig, not the convolution: PMREM needs
      // render targets that a renderer with no GPU behind it cannot provide, so
      // `scene.environment` is null in jsdom and its identity proves nothing on
      // its own. The lights are built on the same line of the same method, so a
      // second organ rebuilding the rig would show up as four lights here.
      stubWebGLSupport({ twoD: true })
      harness = mountViewer()

      await harness.viewer.loadOrgan(createOrgan())
      const first = (await drawnScene(harness)) as Scene
      const environment = first.environment

      await harness.viewer.loadOrgan(
        createOrgan({ id: 'org_brain', modelUrl: '/models/brain.glb' }),
      )
      const second = (await drawnScene(harness)) as Scene

      expect(second.children.filter((child) => child instanceof Light)).toHaveLength(2)
      expect(second.environment).toBe(environment)
    })

    it('tints the organ towards a muted version of its accent', async () => {
      // Phase 6: real tissue is muted. `organs.accent` is a UI colour and
      // arrives far more saturated than any organ ever is.
      harness = mountViewer()
      const organ = createOrgan({ accentColor: '#d1584f' })
      await harness.viewer.loadOrgan(organ)

      const tinted = organMaterial(harness).color
      const accent = new Color(organ.accentColor)

      expect(tinted.getHex()).not.toBe(0xffffff)
      expect(saturationOf(tinted)).toBeGreaterThan(0)
      expect(saturationOf(tinted)).toBeLessThan(saturationOf(accent))
      expect(tinted.r).toBeGreaterThan(tinted.b)
    })

    it('does not compound the tint when the organ is revisited from cache', async () => {
      // Three models stay resident, so a second visit hands back the same
      // material object. Re-blending would walk the heart towards its own
      // accent one navigation at a time.
      harness = mountViewer()
      const organ = createOrgan()

      await harness.viewer.loadOrgan(organ)
      const first = organMaterial(harness).color.clone()
      await harness.viewer.loadOrgan(organ)

      expect(organMaterial(harness).color.getHex()).toBe(first.getHex())
    })

    it('restores the organ tint, not the bare map colour, when a simulation clears its own', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const tinted = organMaterial(harness).color.clone()

      harness.viewer.applySimulationState({ state: {}, visualDirectives: { tint: '#3355ff' } })
      expect(organMaterial(harness).color.getHex()).not.toBe(tinted.getHex())

      harness.viewer.applySimulationState({ state: {}, visualDirectives: { tint: null } })
      expect(organMaterial(harness).color.getHex()).toBe(tinted.getHex())
    })
  })

  describe('render-on-demand', () => {
    it('stops drawing once the scene has settled', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      await nextFrame(harness.renderer)

      const settled = await settle(harness.renderer)
      await sleep(150)

      expect(harness.renderer.info.render.calls).toBe(settled)
    })

    it('settles again after an activation scale-in', async () => {
      // The one-shot marker ease added in handover 15 phase 4 must end. If it
      // looped, the idle frame cost in docs/architecture.md §15.1 would stop
      // being ~0 the first time a student clicked anything.
      harness = mountViewer({ reducedMotion: false })
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      await settle(harness.renderer)

      harness.viewer.selectStructure(organ.structures[0]!.id)
      await nextFrame(harness.renderer)

      const settled = await settle(harness.renderer)
      await sleep(200)

      expect(harness.renderer.info.render.calls).toBe(settled)
    })

    it('wakes up for a flash and settles again', async () => {
      harness = mountViewer()
      const organ = createOrgan()
      await harness.viewer.loadOrgan(organ)
      const settled = await settle(harness.renderer)

      harness.viewer.flashStructure(organ.structures[0]!.id, true)
      await nextFrame(harness.renderer)

      expect(harness.renderer.info.render.calls).toBeGreaterThan(settled)
    })
  })

  describe('WebGL unavailable', () => {
    it('emits webgl:unavailable instead of rendering a blank canvas', async () => {
      // The audited implementation rendered a blank canvas with no message
      // (docs/project-context.md §2.5).
      stubWebGLUnavailable()
      const container = createSizedContainer()
      const viewer = new AnatomyViewer(container, { reducedMotion: true })
      const unavailable = vi.fn()
      viewer.on('webgl:unavailable', unavailable)

      await vi.waitFor(() => expect(unavailable).toHaveBeenCalledOnce())

      expect(viewer.isAvailable).toBe(false)
      expect(container.querySelector('canvas')).toBeNull()
      // The accessible structure list is still mounted: the page keeps its text
      // fallback (docs/architecture.md §5.4 rule 5).
      expect(container.querySelector('.anatomy-viewer__structures')).not.toBeNull()

      viewer.dispose()
      container.remove()
    })

    it('reports a load as failed rather than pretending to load it', async () => {
      stubWebGLUnavailable()
      const container = createSizedContainer()
      const viewer = new AnatomyViewer(container, { reducedMotion: true })
      const failed = vi.fn()
      viewer.on('load:failed', failed)

      await viewer.loadOrgan(createOrgan())

      expect(failed).toHaveBeenCalledWith({
        reason: 'webgl-unavailable',
        detail: expect.stringContaining('structure list'),
      })

      viewer.dispose()
      container.remove()
    })
  })

  describe('disposal', () => {
    it('returns renderer, geometry and texture counts to zero', async () => {
      // docs/architecture.md §5.4 rule 6, and the handover's headline acceptance
      // criterion. The counting matches three's own WebGLGeometries/WebGLTextures
      // bookkeeping — see testing/fakeRenderer.ts.
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      await nextFrame(harness.renderer)

      const { renderer } = harness
      expect(renderer.info.memory.geometries).toBeGreaterThan(0)
      expect(renderer.info.memory.textures).toBeGreaterThan(0)
      expect(liveRenderers.count).toBe(1)

      harness.viewer.dispose()

      expect(renderer.info.memory.geometries).toBe(0)
      expect(renderer.info.memory.textures).toBe(0)
      expect(liveRenderers.count).toBe(0)
      expect(renderer.disposed).toBe(true)
      expect(renderer.contextLost).toBe(true)
    })

    it('frees the generated marker and contact-shadow textures too', async () => {
      // The rest of the suite runs with no 2D canvas, where those four textures
      // are never created — so without this the disposal path for the art the
      // viewer draws itself has no coverage at all. Handover 15 adds a texture
      // under the organ and rebuilds the two marker textures, which makes that
      // gap worth closing rather than noting.
      stubWebGLSupport({ twoD: true })
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      await nextFrame(harness.renderer)

      const { renderer } = harness
      const withGeneratedArt = renderer.info.memory.textures
      expect(withGeneratedArt).toBeGreaterThan(1)

      harness.viewer.dispose()

      expect(renderer.info.memory.textures).toBe(0)
      expect(renderer.info.memory.geometries).toBe(0)
    })

    it('leaks nothing across a second organ load', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      await harness.viewer.loadOrgan(
        createOrgan({ id: 'org_brain', modelUrl: '/models/brain.glb' }),
      )
      await nextFrame(harness.renderer)

      harness.viewer.dispose()

      expect(harness.renderer.info.memory.geometries).toBe(0)
      expect(harness.renderer.info.memory.textures).toBe(0)
    })

    it('removes the canvas, the structure list and every listener', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())
      const selected = vi.fn()
      harness.viewer.on('structure:selected', selected)

      harness.viewer.dispose()

      expect(harness.container.querySelector('canvas')).toBeNull()
      expect(harness.container.querySelector('.anatomy-viewer__structures')).toBeNull()

      harness.viewer.selectStructure('str_left_ventricle')
      expect(selected).not.toHaveBeenCalled()
    })

    it('is idempotent', async () => {
      harness = mountViewer()
      await harness.viewer.loadOrgan(createOrgan())

      harness.viewer.dispose()
      expect(() => harness!.viewer.dispose()).not.toThrow()
      expect(liveRenderers.count).toBe(0)
    })
  })
})

/** The single material every one of these models carries (docs/project-context.md §2.2). */
function materialOf(harness: Harness): {
  opacity: number
  transparent: boolean
  wireframe: boolean
  color: { getHex(): number }
  clippingPlanes: unknown[] | null
} {
  const model = harness.loader.models.at(-1)
  if (model === undefined) throw new Error('No fixture model has been loaded yet.')
  const mesh = model.children[0] as unknown as { material: unknown }
  return mesh.material as never
}
