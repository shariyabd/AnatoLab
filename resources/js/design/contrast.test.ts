import { describe, expect, it } from 'vitest'
import {
  AA_LARGE_TEXT,
  AA_TEXT,
  contrastRatio,
  contrastRatioOf,
  parseColor,
  relativeLuminance,
} from './contrast'

describe('parseColor', () => {
  it.each([
    ['#000000', [0, 0, 0]],
    ['#FFFFFF', [255, 255, 255]],
    ['#2A2320', [42, 35, 32]],
    ['2a2320', [42, 35, 32]],
    ['#fff', [255, 255, 255]],
    ['rgb(42, 35, 32)', [42, 35, 32]],
    ['rgba(42, 35, 32, 0.5)', [42, 35, 32]],
    ['rgb(42 35 32)', [42, 35, 32]],
    ['  #2A2320  ', [42, 35, 32]],
  ])('reads %s', (input, expected) => {
    expect(parseColor(input)).toEqual(expected)
  })

  it.each(['', 'transparent', 'oklch(23% 0.02 265)', '#12345', 'not a colour'])(
    'returns null for %s rather than throwing',
    (input) => {
      expect(parseColor(input)).toBeNull()
    },
  )
})

describe('relativeLuminance', () => {
  it('anchors at the two ends of the range', () => {
    expect(relativeLuminance([0, 0, 0])).toBe(0)
    expect(relativeLuminance([255, 255, 255])).toBeCloseTo(1, 10)
  })
})

describe('contrastRatio', () => {
  it('reaches 21:1 for black on white', () => {
    expect(contrastRatio([0, 0, 0], [255, 255, 255])).toBeCloseTo(21, 10)
  })

  it('is 1:1 for a colour against itself', () => {
    expect(contrastRatio([227, 112, 90], [227, 112, 90])).toBeCloseTo(1, 10)
  })

  it('does not depend on which colour is called the foreground', () => {
    const forwards = contrastRatio([42, 35, 32], [251, 247, 243])
    const backwards = contrastRatio([251, 247, 243], [42, 35, 32])

    expect(forwards).toBeCloseTo(backwards, 10)
  })

  /*
   | The numbers the handover's own review turns on. Ink on paper is the
   | headline pair; accent-ink on accent-soft is the pairing Phase 1 specifies
   | for the active nav pill; white on accent is the pairing Phase 5's filled
   | CTA would use by default and cannot, which is why this case is pinned as a
   | failure rather than left to be rediscovered.
   */
  it.each([
    ['ink on paper', '#2A2320', '#FBF7F3', 14.49, AA_TEXT, true],
    ['ink-soft on paper', '#6B5D55', '#FBF7F3', 5.93, AA_TEXT, true],
    ['ink-muted on paper', '#776C66', '#FBF7F3', 4.78, AA_TEXT, true],
    ['accent-ink on accent-soft', '#B44A38', '#FCEFEA', 4.7, AA_TEXT, true],
    ['ink on accent', '#2A2320', '#E3705A', 4.95, AA_TEXT, true],
    ['hairline-strong on paper', '#918A85', '#FBF7F3', 3.19, AA_LARGE_TEXT, true],
    ['surface on accent', '#FFFFFF', '#E3705A', 3.12, AA_TEXT, false],
    ['the handover ink-muted on paper', '#9C8E85', '#FBF7F3', 2.98, AA_TEXT, false],
  ])('measures %s', (_name, foreground, background, expected, threshold, passes) => {
    const ratio = contrastRatioOf(foreground, background)

    expect(ratio).not.toBeNull()
    expect(ratio as number).toBeCloseTo(expected, 2)
    expect((ratio as number) >= threshold).toBe(passes)
  })
})

describe('contrastRatioOf', () => {
  it('returns null when a token has not resolved', () => {
    expect(contrastRatioOf('', '#FBF7F3')).toBeNull()
    expect(contrastRatioOf('#FBF7F3', 'oklch(23% 0.02 265)')).toBeNull()
  })
})
