/**
 * jsdom shims for the viewer suite.
 *
 * jsdom implements neither WebGL nor pointer capture, and its `getContext` throws
 * a "not implemented" notice rather than returning null. These helpers make the
 * environment answer the two questions the viewer actually asks it — "is there a
 * WebGL 2 context?" and "can this element capture the pointer?" — without
 * pretending to be a GPU.
 */

type ContextFactory = (this: HTMLCanvasElement, contextId: string) => unknown

let originalGetContext: ContextFactory | null = null

export interface CanvasStubOptions {
  readonly webgl2?: boolean
  /**
   * Off by default, because the generated marker and shadow textures are
   * *supposed* to degrade to flat colour where 2D canvas is unavailable, and
   * that is the branch most of the suite should be exercising. Turn it on for a
   * test that needs the textures to actually exist.
   */
  readonly twoD?: boolean
}

/**
 * Replaces jsdom's `getContext`, which throws a "not implemented" notice on every
 * call. Returns a minimal probe object for `webgl2` when `webgl2` is set, a
 * drawing-shaped stub for `2d` when `twoD` is set, and null otherwise.
 */
export function stubCanvasContext(options: CanvasStubOptions = {}): void {
  installContextFactory(function stub(this: HTMLCanvasElement, contextId: string) {
    if (contextId === 'webgl2') {
      return options.webgl2 === true
        ? { getExtension: () => ({ loseContext: () => undefined }) }
        : null
    }
    if (contextId === '2d' && options.twoD === true) return createDrawingStub(this)
    return null
  })
}

/**
 * Enough of `CanvasRenderingContext2D` for the four generated textures.
 *
 * Deliberately records nothing: what the gradients look like is not something a
 * unit test can judge, and the reason to have this at all is that the code paths
 * *around* the drawing — texture creation, colour space, and above all disposal —
 * cannot run without a context to return.
 */
function createDrawingStub(canvas: HTMLCanvasElement): CanvasRenderingContext2D {
  const gradient = { addColorStop: (): void => undefined }
  const context = {
    canvas,
    fillStyle: '',
    strokeStyle: '',
    lineWidth: 0,
    createRadialGradient: () => gradient,
    fillRect: (): void => undefined,
    beginPath: (): void => undefined,
    arc: (): void => undefined,
    stroke: (): void => undefined,
  }
  return context as unknown as CanvasRenderingContext2D
}

/** Makes `probeWebGL()` succeed. */
export function stubWebGLSupport(options: Omit<CanvasStubOptions, 'webgl2'> = {}): void {
  stubCanvasContext({ ...options, webgl2: true })
}

/** Makes `probeWebGL()` fail, as it does where WebGL is blocklisted or switched off. */
export function stubWebGLUnavailable(): void {
  stubCanvasContext({ webgl2: false })
}

export function restoreCanvasContext(): void {
  if (originalGetContext === null) return
  Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
    configurable: true,
    value: originalGetContext,
  })
  originalGetContext = null
}

function installContextFactory(factory: ContextFactory): void {
  const descriptor = Object.getOwnPropertyDescriptor(HTMLCanvasElement.prototype, 'getContext')
  if (originalGetContext === null && typeof descriptor?.value === 'function') {
    originalGetContext = descriptor.value as ContextFactory
  }
  Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
    configurable: true,
    value: factory,
  })
}

/** OrbitControls captures the pointer on press; jsdom has no such method. */
export function stubPointerCapture(): void {
  const element = HTMLElement.prototype as unknown as Record<string, unknown>
  element['setPointerCapture'] ??= (): void => undefined
  element['releasePointerCapture'] ??= (): void => undefined
  element['hasPointerCapture'] ??= (): boolean => false
}

/**
 * A container with a real size. jsdom reports 0×0 for everything, and a viewer
 * that believes it is zero pixels wide cannot project a marker onto the screen,
 * which is what picking measures.
 */
export function createSizedContainer(width = 800, height = 600): HTMLElement {
  const container = document.createElement('div')
  document.body.append(container)

  for (const [property, value] of [
    ['clientWidth', width],
    ['clientHeight', height],
  ] as const) {
    Object.defineProperty(container, property, { configurable: true, value })
  }

  return container
}

/** Gives the viewer's canvas a size and an origin, so pointer maths is checkable. */
export function sizeCanvas(canvas: HTMLCanvasElement, width = 800, height = 600): void {
  Object.defineProperty(canvas, 'clientWidth', { configurable: true, value: width })
  Object.defineProperty(canvas, 'clientHeight', { configurable: true, value: height })
  canvas.getBoundingClientRect = () =>
    ({ left: 0, top: 0, width, height, right: width, bottom: height, x: 0, y: 0 }) as DOMRect
}

/**
 * jsdom 26 has no `PointerEvent`. A `MouseEvent` under a pointer event name
 * carries every field the viewer reads (`clientX`, `clientY`); the extra pointer
 * fields are added so OrbitControls' own listeners do not see `undefined`.
 */
export function firePointer(
  target: EventTarget,
  type: 'pointerdown' | 'pointerup' | 'pointermove' | 'pointerleave',
  position: { x: number; y: number },
): void {
  const event = new MouseEvent(type, {
    bubbles: true,
    cancelable: true,
    clientX: position.x,
    clientY: position.y,
  })
  Object.assign(event, { pointerId: 1, pointerType: 'mouse', isPrimary: true })
  target.dispatchEvent(event)
}
