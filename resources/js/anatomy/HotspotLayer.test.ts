import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PerspectiveCamera, Vector3 } from 'three'
import { HotspotLayer, snapAllToSurface } from './HotspotLayer'
import { HOTSPOT_SURFACE_OFFSET } from './constants'
import { normaliseIntoFitCube } from './AssetManager'
import { FIXTURE_RADIUS, createFixtureModel, createStructure } from './testing/fixtures'
import { restoreCanvasContext, stubCanvasContext } from './testing/dom'

function createNormalisedSphere() {
  const model = createFixtureModel()
  normaliseIntoFitCube(model)
  return model
}

function createCamera(): PerspectiveCamera {
  const camera = new PerspectiveCamera(42, 800 / 600, 0.1, 200)
  camera.position.set(0, 0, 6)
  camera.lookAt(0, 0, 0)
  camera.updateMatrixWorld()
  return camera
}

describe('snapAllToSurface', () => {
  it('snaps an anchor authored inside the shell onto the nearest surface', () => {
    const organ = createNormalisedSphere()
    const structure = createStructure({ anchorPosition: [0, 0, 1] })

    const result = snapAllToSurface([structure], organ).get(structure.id)

    expect(result).toBeDefined()
    // Surface radius plus the float-off offset, within one vertex of the
    // 24×16 sphere tessellation.
    expect(result!.position.length()).toBeCloseTo(FIXTURE_RADIUS + HOTSPOT_SURFACE_OFFSET, 1)
  })

  it('never snaps through to the far side of a hollow shell', () => {
    // The failure the direction cone exists to prevent: nearest-vertex alone can
    // pick the far wall for an anchor authored near the centre, putting the dot
    // behind the organ (docs/project-context.md §2.4).
    const organ = createNormalisedSphere()
    const structure = createStructure({ anchorPosition: [0, 0, 0.05] })

    const result = snapAllToSurface([structure], organ).get(structure.id)!

    expect(result.position.z).toBeGreaterThan(0)
    expect(result.normal.z).toBeGreaterThan(0)
  })

  it('resolves every structure in a single pass', () => {
    const organ = createNormalisedSphere()
    const structures = [
      createStructure({ id: 'a', anchorPosition: [0, 0, 1.5] }),
      createStructure({ id: 'b', anchorPosition: [0, 1.5, 0] }),
      createStructure({ id: 'c', anchorPosition: [-1.5, 0, 0] }),
    ]

    const results = snapAllToSurface(structures, organ)

    expect([...results.keys()]).toEqual(['a', 'b', 'c'])
    expect(results.get('a')!.position.z).toBeGreaterThan(0)
    expect(results.get('b')!.position.y).toBeGreaterThan(0)
    expect(results.get('c')!.position.x).toBeLessThan(0)
  })

  it('keeps the authored coordinate when there is no geometry to snap to', () => {
    const structure = createStructure({ anchorPosition: [1, 0, 0] })
    const result = snapAllToSurface([structure], createFixtureModel().clear()).get(structure.id)!

    expect(result.position.x).toBeCloseTo(1 + HOTSPOT_SURFACE_OFFSET, 5)
  })

  it('offsets the marker off the surface along the normal', () => {
    const organ = createNormalisedSphere()
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })

    const result = snapAllToSurface([structure], organ).get(structure.id)!
    const surface = result.position.clone().addScaledVector(result.normal, -HOTSPOT_SURFACE_OFFSET)

    expect(result.position.length()).toBeGreaterThan(surface.length())
  })
})

describe('HotspotLayer', () => {
  let layer: HotspotLayer
  let container: HTMLElement

  beforeEach(() => {
    stubCanvasContext()
    container = document.createElement('div')
    document.body.append(container)
    layer = new HotspotLayer()
  })

  afterEach(() => {
    layer.dispose()
    container.remove()
    restoreCanvasContext()
  })

  it('adds a dot and a ring per structure', () => {
    layer.attach([createStructure()], createNormalisedSphere(), '#d1584f')
    expect(layer.group.children).toHaveLength(2)
    expect(layer.structures).toHaveLength(1)
  })

  it('mirrors the markers as an accessible list of buttons', () => {
    // PRD §31: every 3D interaction needs a textual equivalent.
    const onIndexSelect = vi.fn()
    layer.dispose()
    layer = new HotspotLayer({ onIndexSelect })
    layer.mountIndex(container)
    layer.attach([createStructure({ name: 'Left ventricle' })], createNormalisedSphere(), '#fff')

    const button = container.querySelector<HTMLButtonElement>('.hotspot-index button')
    expect(button?.textContent).toBe('Left ventricle')

    button?.click()
    expect(onIndexSelect).toHaveBeenCalledOnce()
  })

  it('reflects selection in the accessible list', () => {
    layer.mountIndex(container)
    const structure = createStructure()
    layer.attach([structure], createNormalisedSphere(), '#fff')

    layer.setSelected(structure.id)

    expect(container.querySelector('button')?.getAttribute('aria-pressed')).toBe('true')
  })

  it('fades a marker on the far side and keeps the near one bright', () => {
    const organ = createNormalisedSphere()
    const front = createStructure({ id: 'front', anchorPosition: [0, 0, FIXTURE_RADIUS] })
    const back = createStructure({ id: 'back', anchorPosition: [0, 0, -FIXTURE_RADIUS] })
    layer.attach([front, back], organ, '#fff')

    layer.update(createCamera())

    const [, frontDot, , backDot] = layer.group.children
    expect(
      (frontDot as never as { material: { opacity: number } }).material.opacity,
    ).toBeGreaterThan(0.9)
    expect((backDot as never as { material: { opacity: number } }).material.opacity).toBeLessThan(
      0.2,
    )
  })

  it('picks the marker under the pointer', () => {
    const camera = createCamera()
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })
    layer.attach([structure], createNormalisedSphere(), '#fff')
    layer.update(camera)

    const screen = layer.screenPosition(structure.id, camera, 800, 600)!
    expect(screen.visible).toBe(true)

    expect(layer.pick(screen.x, screen.y, camera, 800, 600)?.id).toBe(structure.id)
  })

  it('returns nothing when the pointer is outside the pick radius', () => {
    const camera = createCamera()
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })
    layer.attach([structure], createNormalisedSphere(), '#fff')
    layer.update(camera)

    const screen = layer.screenPosition(structure.id, camera, 800, 600)!
    expect(layer.pick(screen.x + 200, screen.y, camera, 800, 600)).toBeNull()
  })

  it('will not pick a marker the facing test has faded out', () => {
    // Clicking through the organ to its far side is never what the student meant.
    const camera = createCamera()
    const back = createStructure({ id: 'back', anchorPosition: [0, 0, -FIXTURE_RADIUS] })
    layer.attach([back], createNormalisedSphere(), '#fff')
    layer.update(camera)

    const screen = layer.screenPosition(back.id, camera, 800, 600)!
    expect(screen.visible).toBe(false)
    expect(layer.pick(screen.x, screen.y, camera, 800, 600)).toBeNull()
  })

  it('picks nothing at all in author mode', () => {
    const camera = createCamera()
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })
    layer.attach([structure], createNormalisedSphere(), '#fff')
    layer.update(camera)
    const screen = layer.screenPosition(structure.id, camera, 800, 600)!

    layer.setInteractive(false)

    expect(layer.pick(screen.x, screen.y, camera, 800, 600)).toBeNull()
  })

  it('flashes green for correct and red for wrong', () => {
    const camera = createCamera()
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })
    layer.attach([structure], createNormalisedSphere(), '#ffffff')

    layer.flash(structure.id, true)
    layer.update(camera)
    const correct = (
      layer.group.children[1] as never as { material: { color: { getHex(): number } } }
    ).material.color.getHex()

    layer.flash(structure.id, false)
    layer.update(camera)
    const wrong = (
      layer.group.children[1] as never as { material: { color: { getHex(): number } } }
    ).material.color.getHex()

    expect(correct).toBe(0x35c46a)
    expect(wrong).toBe(0xe2564a)
  })

  it('keeps the loop awake only while something is animating', () => {
    const structure = createStructure()
    layer.attach([structure], createNormalisedSphere(), '#fff')

    expect(layer.isAnimating).toBe(false)
    layer.flash(structure.id, true)
    expect(layer.isAnimating).toBe(true)
  })

  it('dims every marker but the isolated one', () => {
    const camera = createCamera()
    const solo = createStructure({ id: 'solo', anchorPosition: [0, 0, FIXTURE_RADIUS] })
    const other = createStructure({ id: 'other', anchorPosition: [0, FIXTURE_RADIUS, 0] })
    layer.attach([solo, other], createNormalisedSphere(), '#fff')

    layer.setSolo('solo')
    layer.update(camera)

    const opacityOf = (index: number) =>
      (layer.group.children[index] as never as { material: { opacity: number } }).material.opacity

    expect(opacityOf(1)).toBeGreaterThan(opacityOf(3))
  })

  it('holds a marker the same size on screen as the camera dollies', () => {
    const structure = createStructure({ anchorPosition: [0, 0, FIXTURE_RADIUS] })
    layer.attach([structure], createNormalisedSphere(), '#fff')

    const near = createCamera()
    layer.update(near)
    const nearScale = (layer.group.children[1] as { scale: Vector3 }).scale.x

    const far = createCamera()
    far.position.set(0, 0, 12)
    far.updateMatrixWorld()
    layer.update(far)
    const farScale = (layer.group.children[1] as { scale: Vector3 }).scale.x

    // Twice the distance, twice the world size — the same pixels.
    expect(farScale / nearScale).toBeCloseTo((12 - FIXTURE_RADIUS) / (6 - FIXTURE_RADIUS), 1)
  })

  it('frees its quad and textures on dispose', () => {
    layer.attach([createStructure()], createNormalisedSphere(), '#fff')
    const material = (layer.group.children[1] as never as { material: { dispose(): void } })
      .material
    const spy = vi.spyOn(material, 'dispose')

    layer.dispose()

    expect(spy).toHaveBeenCalledOnce()
    expect(layer.group.children).toHaveLength(0)
  })
})
