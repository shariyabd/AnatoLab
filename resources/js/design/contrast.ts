/**
 * WCAG 2.2 contrast arithmetic.
 *
 * Handover 15 requires the atelier palette's contrast to be "checked, not
 * assumed", and warm greys on warm paper fail easily — --color-ink-muted as the
 * handover first specified it measured 2.98:1. So the ratio is computed from the
 * token values rather than recorded next to them by hand, in two places that
 * need it for different reasons:
 *
 * - resources/js/design/theme.contrast.test.ts parses theme.css and fails the
 *   build if an authored token drops under its threshold.
 * - Pages/DesignTokens.vue reads the values the browser actually resolved, so
 *   the review surface reports what is on screen rather than what was intended.
 *
 * Only sRGB hex is supported. Every atelier token is authored as hex for
 * exactly that reason; the resolved values the browser hands back for those
 * tokens are `rgb()` triples, which `parseColor` also accepts.
 */

/** WCAG 2.2 SC 1.4.3 — normal-size text. */
export const AA_TEXT = 4.5

/** WCAG 2.2 SC 1.4.3 large text, and SC 1.4.11 non-text UI boundaries. */
export const AA_LARGE_TEXT = 3

export type Rgb = readonly [number, number, number]

const HEX_PATTERN = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i
const RGB_PATTERN = /^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)/i

/**
 * Returns null rather than throwing: the page reads whatever the browser
 * resolved, and a token that has not loaded yet is a blank string, not a bug.
 */
export function parseColor(value: string): Rgb | null {
  const trimmed = value.trim()

  const hex = HEX_PATTERN.exec(trimmed)?.[1]

  if (hex !== undefined) {
    const digits =
      hex.length === 3
        ? hex
            .split('')
            .map((digit) => digit + digit)
            .join('')
        : hex

    return [
      Number.parseInt(digits.slice(0, 2), 16),
      Number.parseInt(digits.slice(2, 4), 16),
      Number.parseInt(digits.slice(4, 6), 16),
    ]
  }

  const rgb = RGB_PATTERN.exec(trimmed)

  if (rgb?.[1] !== undefined && rgb[2] !== undefined && rgb[3] !== undefined) {
    return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])]
  }

  return null
}

/** Relative luminance, WCAG 2.2 definition. */
export function relativeLuminance([red, green, blue]: Rgb): number {
  const channel = (value: number): number => {
    const scaled = value / 255

    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4
  }

  return 0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue)
}

/**
 * Contrast ratio between two colours, 1 to 21. Order does not matter — the
 * lighter of the pair is found rather than assumed, so a caller cannot get a
 * ratio below 1 by passing foreground and background the wrong way round.
 */
export function contrastRatio(foreground: Rgb, background: Rgb): number {
  const first = relativeLuminance(foreground)
  const second = relativeLuminance(background)

  const lighter = Math.max(first, second)
  const darker = Math.min(first, second)

  return (lighter + 0.05) / (darker + 0.05)
}

/** Null when either colour is unparseable, so a caller must handle the gap. */
export function contrastRatioOf(foreground: string, background: string): number | null {
  const first = parseColor(foreground)
  const second = parseColor(background)

  if (first === null || second === null) {
    return null
  }

  return contrastRatio(first, second)
}
