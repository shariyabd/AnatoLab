/**
 * jsdom shims for the viewer suite.
 *
 * jsdom implements neither WebGL nor pointer capture, and its `getContext` throws
 * a "not implemented" notice rather than returning null. These helpers make the
 * environment answer the two questions the viewer actually asks it — "is there a
 * WebGL 2 context?" and "can this element capture the pointer?" — without
 * pretending to be a GPU.
 */

type ContextFactory = (contextId: string) => unknown

let originalGetContext: ContextFactory | null = null

/**
 * Replaces jsdom's `getContext`, which throws a "not implemented" notice on every
 * call. Returns null for `2d` — the viewer's generated marker and shadow textures
 * degrade to flat colour without one, which is the branch worth exercising here —
 * and a minimal probe object for `webgl2` when `webgl2` is set.
 */
export function stubCanvasContext(options: { webgl2?: boolean } = {}): void {
  installContextFactory((contextId) =>
    contextId === 'webgl2' && options.webgl2 === true
      ? { getExtension: () => ({ loseContext: () => undefined }) }
      : null,
  )
}

/** Makes `probeWebGL()` succeed. */
export function stubWebGLSupport(): void {
  stubCanvasContext({ webgl2: true })
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
