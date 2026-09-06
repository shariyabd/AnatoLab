import { describe, expect, it } from 'vitest'
import THEME_CSS from '../../css/theme.css?raw'
import {
  MARKER_ACTIVE,
  MARKER_FLASH_CORRECT,
  MARKER_FLASH_WRONG,
  MARKER_RESTING,
  MARKER_RING,
  SHADOW_INK,
} from './palette'

/**
 * The viewer's colours against the token layer that authored them.
 *
 * The viewer cannot read a CSS custom property (invariant 3), so `palette.ts`
 * holds a copy. This is what stops the copy going stale: retune
 * `--color-hotspot` in theme.css without touching the viewer and the build
 * fails here with both values, rather than the markers quietly staying the old
 * colour.
 *
 * Same mechanism and same reasoning as `fitSizeParity.test.ts`, and the same
 * `?raw` import as `resources/js/design/theme.contrast.test.ts` — these specs
 * run in jsdom, where reading a file would need @types/node, a dependency this
 * handover may not add.
 */
function token(name: string): string {
  const value = new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{3,8})\\s*;`).exec(THEME_CSS)?.[1]

  if (value === undefined) {
    throw new Error(`theme.css does not define --${name} as a hex value`)
  }

  return value.toLowerCase()
}

describe('viewer palette', () => {
  it.each([
    { constant: 'MARKER_RESTING', value: MARKER_RESTING, name: 'color-hotspot' },
    { constant: 'MARKER_ACTIVE', value: MARKER_ACTIVE, name: 'color-hotspot-live' },
    { constant: 'MARKER_RING', value: MARKER_RING, name: 'color-surface' },
    { constant: 'SHADOW_INK', value: SHADOW_INK, name: 'color-ink' },
  ])('$constant mirrors --$name', ({ value, name }) => {
    expect(value).toBe(token(name))
  })

  it('keeps the two correctness colours out of the mirrored set', () => {
    // theme.css declares no success or danger token, so these cannot be
    // mirrors. Asserted rather than assumed: the day one is added, this fails
    // and the pair above it is where they belong.
    expect(THEME_CSS).not.toContain(MARKER_FLASH_CORRECT)
    expect(THEME_CSS).not.toContain(MARKER_FLASH_WRONG)
  })
})
