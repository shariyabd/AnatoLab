import { describe, expect, it } from 'vitest'
import THEME_CSS from '../../css/theme.css?raw'
import { contrastRatioOf } from './contrast'
import { ATELIER_COLOR_GROUPS, ATELIER_CONTRAST_PAIRINGS } from './palette'

/**
 * The atelier palette's accessibility contract, checked against the authored
 * token values rather than against a table someone kept up to date by hand.
 *
 * Handover 15's acceptance criterion 5 is that contrast is "verified
 * numerically", and its own warning that warm greys on warm paper fail easily
 * was correct: the --color-ink-muted the handover specified measures 2.98:1 on
 * --color-paper. This is what stops that reappearing — drop a hex in theme.css
 * below its threshold and the build fails with the measured number.
 *
 * The stylesheet is imported through Vite's `?raw` rather than read with
 * node:fs: these specs run in the jsdom environment, where import.meta.url is
 * an http URL, and reading a file would need @types/node — a dependency this
 * handover is not allowed to add.
 */
function token(name: string): string {
  const value = new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{3,8})\\s*;`).exec(THEME_CSS)?.[1]

  if (value === undefined) {
    throw new Error(`theme.css does not define --${name} as a hex value`)
  }

  return value
}

describe('atelier palette contrast', () => {
  it.each(ATELIER_CONTRAST_PAIRINGS)(
    '$usage: --$foreground on --$background clears $threshold:1',
    ({ foreground, background, threshold }) => {
      const ratio = contrastRatioOf(token(foreground), token(background))

      expect(ratio).not.toBeNull()
      expect(ratio as number).toBeGreaterThanOrEqual(threshold)
    },
  )

  it('authors every colour token as sRGB hex, so every ratio is computable', () => {
    const declared = [...THEME_CSS.matchAll(/--(color-[a-z-]+):\s*([^;]+);/g)]

    expect(declared.length).toBeGreaterThan(0)

    for (const [, name, value] of declared) {
      expect(value?.trim(), `--${name} is not a hex colour`).toMatch(/^#[0-9a-fA-F]{3,8}$/)
    }
  })
})

describe('atelier token inventory', () => {
  /*
   | The review surface is only worth having if it shows the whole palette. A
   | token added to theme.css without a role written down in palette.ts would
   | otherwise be invisible on /design-tokens and go unreviewed.
   */
  it('gives every colour token in theme.css a documented role', () => {
    const declared = [...THEME_CSS.matchAll(/--(color-[a-z-]+):/g)].map(([, name]) => name ?? '')
    const inventoried = ATELIER_COLOR_GROUPS.flatMap((group) =>
      group.tokens.map((entry) => entry.name),
    )

    expect([...declared].sort()).toEqual([...inventoried].sort())
  })

  it('names only tokens that theme.css actually declares in a contrast pairing', () => {
    for (const pairing of ATELIER_CONTRAST_PAIRINGS) {
      expect(() => token(pairing.foreground)).not.toThrow()
      expect(() => token(pairing.background)).not.toThrow()
    }
  })
})
