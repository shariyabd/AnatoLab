/**
 * WebGL availability probe.
 *
 * The audited viewer constructed a `WebGLRenderer` unconditionally; on a machine
 * with WebGL disabled or blocklisted that throws inside the constructor and the
 * page is left with a blank canvas and no message (docs/project-context.md §2.5).
 * docs/architecture.md §5.4 rule 5 requires the opposite: detect first, emit
 * `webgl:unavailable`, and let the page render its text fallback.
 */

/** Why the probe failed, in words a UI can show without further translation. */
export interface WebGLProbe {
  readonly available: boolean
  readonly detail: string
}

export function probeWebGL(): WebGLProbe {
  if (typeof document === 'undefined') {
    return { available: false, detail: 'No document: 3D rendering needs a browser.' }
  }

  try {
    const canvas = document.createElement('canvas')
    // WebGL 2 only. Three r15x+ targets it, and every browser we support has
    // shipped it since 2021; probing WebGL 1 as well would report "available"
    // for contexts the renderer then refuses.
    const context = canvas.getContext('webgl2')

    if (context === null) {
      return {
        available: false,
        detail:
          'This browser or device could not create a WebGL 2 context. ' +
          'Hardware acceleration may be switched off.',
      }
    }

    // Release the probe context immediately. Browsers cap the number of live
    // contexts per page (docs/architecture.md §5.4 rule 7) and this one exists
    // only to answer the question.
    const lose = context.getExtension('WEBGL_lose_context')
    lose?.loseContext()

    return { available: true, detail: '' }
  } catch (error) {
    return {
      available: false,
      detail: error instanceof Error ? error.message : 'WebGL context creation failed.',
    }
  }
}
