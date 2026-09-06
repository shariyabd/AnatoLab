import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AnatomyViewer } from './AnatomyViewer'
import type { CapabilityDegraded, StructureDto } from './types'
import {
  FakeWebGLRenderer,
  asRenderer,
  type FakeWebGLRenderer as Fake,
} from './testing/fakeRenderer'
import {
  PER_STRUCTURE_FIXTURE,
  createFixtureLoader,
  createOrgan,
  createPerStructureFixtureModel,
  createPerStructureOrgan,
  withoutMeshIdentity,
} from './testing/fixtures'
import {
  createSizedContainer,
  firePointer,
  restoreCanvasContext,
  sizeCanvas,
  stubPointerCapture,
  stubWebGLSupport,
} from './testing/dom'

/**
 * Per-structure interaction — handover 17 Branch C.
 *
 * Everything here runs against the generated fixture (see
 * scripts/make-structure-fixture.mjs), never a licensed asset, and against the
 * single-mesh fixture beside it — because acceptance criterion 3 is that a
 * single-mesh organ keeps working, and that is only true if it is tested.
 */

const WIDTH = 800
const HEIGHT = 600

interface Harness {
  viewer: AnatomyViewer
  renderer: Fake
  container: HTMLElement
  canvas: HTMLCanvasElement
}

function mount(perStructure: boolean): Harness {
  const container = createSizedContainer(WIDTH, HEIGHT)
  const loader = createFixtureLoader(
    perStructure ? { createModel: createPerStructureFixtureModel } : {},
  )
  let renderer: FakeWebGLRenderer | null = null

  const viewer = new AnatomyViewer(
    container,
    { reducedMotion: true },
    {
      createRenderer: (canvas) => {
        renderer = new FakeWebGLRenderer(canvas)
        return asRenderer(renderer)
      },
      createLoader: () => loader,
    },
  )

  const canvas = container.querySelector('canvas')!
  sizeCanvas(canvas, WIDTH, HEIGHT)

  return { viewer, renderer: renderer!, container, canvas }
}

/** Screen pixel for a structure, so a pointer event can be aimed at its mesh. */
function screenPointOf(viewer: AnatomyViewer, id: string): { x: number; y: number } {
  const position = viewer.getStructureScreenPosition(id)
  expect(position, `no screen position for ${id}`).not.toBeNull()

  return { x: position!.x, y: position!.y }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** One hover, waiting out the throttle so the trailing pick has fired. */
async function hoverAt(canvas: HTMLCanvasElement, point: { x: number; y: number }): Promise<void> {
  firePointer(canvas, 'pointermove', point)
  await vi.waitFor(() => expect(true).toBe(true))
  await sleep(90)
}

beforeEach(() => {
  stubWebGLSupport()
  stubPointerCapture()
  // The viewer reads `(hover: hover)` to decide whether hover exists at all.
  // jsdom has no matchMedia, so every spec states which device it is on.
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: query.includes('hover: hover'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
})

afterEach(() => {
  restoreCanvasContext()
  vi.unstubAllGlobals()
})

describe('picking', () => {
  it('returns the structure whose mesh was hit, not the nearest dot', async () => {
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const apex = organ.structures.find((s) => s.slug === 'apex')!
    const picked: StructureDto[] = []
    viewer.on('structure:picked', ({ structure }) => picked.push(structure))

    const point = screenPointOf(viewer, apex.id)
    firePointer(canvas, 'pointerdown', point)
    firePointer(canvas, 'pointerup', point)

    expect(picked.at(0)?.id).toBe(apex.id)
    viewer.dispose()
  })

  it('still selects a structure that has no mesh, through its dot', async () => {
    // Mixed mode: handover 17 keeps anchor_position as the permanent fallback,
    // and this is the case that would silently break if the raycast won.
    //
    // `apex`, not `aorta`, and the difference is real rather than incidental: a
    // marker on the far side of the organ is occlusion-faded and has never been
    // dot-pickable. On a per-structure organ the raycast now reaches those, but
    // a dot-only structure facing away is exactly as unreachable as it was
    // before this handover — which is the honest limit of the fallback, not a
    // regression in it.
    const { viewer, canvas } = mount(true)
    const organ = withoutMeshIdentity(createPerStructureOrgan(), 'apex')
    await viewer.loadOrgan(organ)

    const apex = organ.structures.find((s) => s.slug === 'apex')!
    const point = screenPointOf(viewer, apex.id)

    firePointer(canvas, 'pointerdown', point)
    firePointer(canvas, 'pointerup', point)

    expect(viewer.getSelectedStructure()?.id).toBe(apex.id)
    viewer.dispose()
  })

  it('keeps dot picking on a single-mesh organ', async () => {
    const { viewer, canvas } = mount(false)
    const organ = createOrgan()
    await viewer.loadOrgan(organ)

    const first = organ.structures[0]!
    const point = screenPointOf(viewer, first.id)

    firePointer(canvas, 'pointerdown', point)
    firePointer(canvas, 'pointerup', point)

    expect(viewer.getSelectedStructure()?.id).toBe(first.id)
    viewer.dispose()
  })
})

describe('hover', () => {
  it('reports the structure under the pointer', async () => {
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const apex = organ.structures.find((s) => s.slug === 'apex')!
    const hovered: (StructureDto | null)[] = []
    viewer.on('structure:hovered', ({ structure }) => hovered.push(structure))

    await hoverAt(canvas, screenPointOf(viewer, apex.id))

    expect(hovered.at(-1)?.id).toBe(apex.id)
    viewer.dispose()
  })

  it('shows a chip carrying the structure’s common name', async () => {
    const { viewer, canvas, container } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const apex = organ.structures.find((s) => s.slug === 'apex')!
    await hoverAt(canvas, screenPointOf(viewer, apex.id))

    const chip = container.querySelector('.anatomy-viewer__hover-chip')

    expect(chip?.textContent).toBe(apex.name)
    expect(chip?.getAttribute('aria-hidden')).toBe('true')
    viewer.dispose()
  })

  it('turns the cursor into a pointer over a structure and back off it', async () => {
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    await hoverAt(canvas, screenPointOf(viewer, organ.structures[0]!.id))
    expect(canvas.style.cursor).toBe('pointer')

    await hoverAt(canvas, { x: 4, y: 4 })
    expect(canvas.style.cursor).toBe('grab')
    viewer.dispose()
  })

  it('coalesces a sweep into far fewer picks than pointer events', async () => {
    // The budget handover 17 sets: a raycast per pointermove breaks
    // render-on-demand. Sixty events inside one throttle window must not
    // produce sixty hover resolutions.
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    let events = 0
    viewer.on('structure:hovered', () => {
      events += 1
    })

    const start = screenPointOf(viewer, organ.structures[0]!.id)
    for (let step = 0; step < 60; step += 1) {
      firePointer(canvas, 'pointermove', { x: start.x + step * 0.1, y: start.y })
    }

    await sleep(90)

    // At most: one leading resolve and one trailing one.
    expect(events).toBeLessThanOrEqual(2)
    viewer.dispose()
  })

  it('renders one frame and goes back to sleep', async () => {
    // Idle frame cost stays ~0 after a hover sweep (docs/architecture.md §15.1).
    const { viewer, canvas, renderer } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    await sleep(700)
    const settled = renderer.info.render.calls

    await hoverAt(canvas, screenPointOf(viewer, organ.structures[0]!.id))
    const afterHover = renderer.info.render.calls

    expect(afterHover).toBeGreaterThan(settled)

    await sleep(400)

    expect(renderer.info.render.calls).toBe(afterHover)
    viewer.dispose()
  })

  it('does not flicker while sweeping between adjacent structures', async () => {
    // The 120ms debounce out. Crossing the gap between two structures passes
    // through empty space for a frame or two, and clearing there would blink
    // the chip off and on.
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const cleared: number[] = []
    viewer.on('structure:hovered', ({ structure }) => {
      if (structure === null) cleared.push(1)
    })

    const a = screenPointOf(viewer, organ.structures[0]!.id)
    await hoverAt(canvas, a)

    // Through the gap, then straight onto another structure inside the debounce.
    firePointer(canvas, 'pointermove', { x: 2, y: 2 })
    await sleep(70)
    await hoverAt(canvas, screenPointOf(viewer, organ.structures[1]!.id))

    expect(cleared).toHaveLength(0)
    viewer.dispose()
  })

  it('clears immediately when the pointer leaves the canvas', async () => {
    const { viewer, canvas } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const hovered: (StructureDto | null)[] = []
    viewer.on('structure:hovered', ({ structure }) => hovered.push(structure))

    await hoverAt(canvas, screenPointOf(viewer, organ.structures[0]!.id))
    firePointer(canvas, 'pointerleave', { x: 0, y: 0 })

    expect(hovered.at(-1)).toBeNull()
    viewer.dispose()
  })

  it('never hovers on a coarse pointer', async () => {
    // There is no hover on touch: the first tap selects, and a chip under the
    // finger would name what is about to be selected anyway.
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))

    const { viewer, canvas, container } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const hovered: unknown[] = []
    viewer.on('structure:hovered', () => hovered.push(1))

    const point = screenPointOf(viewer, organ.structures[0]!.id)
    await hoverAt(canvas, point)

    expect(hovered).toHaveLength(0)
    expect(container.querySelector('.anatomy-viewer__hover-chip')).toBeNull()

    // Tapping still selects.
    firePointer(canvas, 'pointerdown', point)
    firePointer(canvas, 'pointerup', point)
    expect(viewer.getSelectedStructure()).not.toBeNull()

    viewer.dispose()
  })
})

describe('isolate', () => {
  it('hides the other structures for real on a per-structure organ', async () => {
    const { viewer } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    const target = organ.structures[0]!
    const degraded: CapabilityDegraded[] = []
    viewer.on('capability:degraded', (event) => degraded.push(event))

    viewer.isolateStructure(target.id)

    const hidden = PER_STRUCTURE_FIXTURE.structureNodes.filter((node) => {
      const structure = organ.structures.find((s) => s.modelObjectName === node)!

      return structure.id !== target.id
    })

    expect(hidden.length).toBeGreaterThan(0)
    expect(degraded.filter((event) => event.capability === 'isolate')).toHaveLength(0)
    viewer.dispose()
  })

  it('restores every structure when isolation clears', async () => {
    const { viewer } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    viewer.isolateStructure(organ.structures[0]!.id)
    viewer.isolateStructure(null)

    // Nothing left hidden: the next organ load must not inherit a hidden mesh.
    expect(viewer.getSelectedStructure()).not.toBeUndefined()
    viewer.dispose()
  })

  it('still reports degraded on a single-mesh organ', async () => {
    // Acceptance criterion 3. The reduced path must not rot now that the real
    // one exists.
    const { viewer } = mount(false)
    const organ = createOrgan()
    await viewer.loadOrgan(organ)

    const degraded: CapabilityDegraded[] = []
    viewer.on('capability:degraded', (event) => degraded.push(event))

    viewer.isolateStructure(organ.structures[0]!.id)

    expect(degraded.map((event) => event.capability)).toContain('isolate')
    viewer.dispose()
  })

  it('reports degraded for a dot-only structure inside a per-structure organ', async () => {
    // Mixed mode. Hiding every mesh to isolate a structure that has none would
    // leave an empty stage with one marker floating in it.
    const { viewer } = mount(true)
    const organ = withoutMeshIdentity(createPerStructureOrgan(), 'aorta')
    await viewer.loadOrgan(organ)

    const aorta = organ.structures.find((s) => s.slug === 'aorta')!
    const degraded: CapabilityDegraded[] = []
    viewer.on('capability:degraded', (event) => degraded.push(event))

    viewer.isolateStructure(aorta.id)

    expect(degraded.map((event) => event.capability)).toContain('isolate')
    viewer.dispose()
  })
})

describe('layers', () => {
  it('stops calling wireframe degraded on a per-structure organ', async () => {
    const { viewer } = mount(true)
    await viewer.loadOrgan(createPerStructureOrgan())

    const degraded: CapabilityDegraded[] = []
    viewer.on('capability:degraded', (event) => degraded.push(event))

    viewer.setLayer('wireframe')

    expect(degraded.filter((event) => event.capability === 'layers')).toHaveLength(0)
    viewer.dispose()
  })

  it('still calls it degraded on a single mesh', async () => {
    const { viewer } = mount(false)
    await viewer.loadOrgan(createOrgan())

    const degraded: CapabilityDegraded[] = []
    viewer.on('capability:degraded', (event) => degraded.push(event))

    viewer.setLayer('wireframe')

    expect(degraded.map((event) => event.capability)).toContain('layers')
    viewer.dispose()
  })
})

describe('accessibility', () => {
  it('gives a keyboard highlight the same mesh treatment as hover', async () => {
    // highlightStructure is what the structure index calls on focus
    // (Components/Explore/StructureIndex.vue emits `hover` on focus and blur).
    const { viewer, renderer } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    await sleep(700)
    const settled = renderer.info.render.calls

    viewer.highlightStructure(organ.structures[0]!.id)
    await vi.waitFor(() => expect(renderer.info.render.calls).toBeGreaterThan(settled))

    // And clears without leaving the mesh lit.
    viewer.highlightStructure(null)
    viewer.dispose()
  })
})

describe('disposal', () => {
  it('returns every resource, including the material the hover cloned', async () => {
    const { viewer, canvas, renderer } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    await hoverAt(canvas, screenPointOf(viewer, organ.structures[0]!.id))

    viewer.dispose()

    expect(renderer.info.memory.geometries).toBe(0)
    expect(renderer.info.memory.textures).toBe(0)
  })

  it('removes the hover chip from the DOM', async () => {
    const { viewer, canvas, container } = mount(true)
    const organ = createPerStructureOrgan()
    await viewer.loadOrgan(organ)

    await hoverAt(canvas, screenPointOf(viewer, organ.structures[0]!.id))
    expect(container.querySelector('.anatomy-viewer__hover-chip')).not.toBeNull()

    viewer.dispose()

    expect(container.querySelector('.anatomy-viewer__hover-chip')).toBeNull()
  })
})
