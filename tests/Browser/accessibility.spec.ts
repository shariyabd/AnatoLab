import { expect, test, type Page } from '@playwright/test'

/**
 * The WCAG 2.2 audit, as a test rather than as a report (PRD §31, handover 14).
 *
 * An audit that lives in a document is an audit that was true once. These are
 * the criteria that can be measured mechanically, so they run with everything
 * else and fail when a palette or a control drifts back out of range.
 *
 * What is deliberately NOT here: judgement calls that a machine gets wrong more
 * often than right — whether alt text is *meaningful*, whether a heading
 * *describes* its section, whether an error message is *actionable*. Those were
 * checked by reading the pages, and the findings are in the handover report.
 *
 * Requires a running server and a seeded database.
 */

/**
 * Colour pairs that actually occur in the interface, with the threshold each
 * has to clear.
 *
 * `minimum` is 4.5 for anything rendered as text at the sizes this application
 * uses (12–16px, none of it "large" by WCAG's definition) and 3.0 for the
 * boundary of an interactive control (SC 1.4.11).
 */
const CONTRAST: { label: string; foreground: string; background: string; minimum: number }[] = [
  { label: 'body text', foreground: '--color-ink', background: '--color-surface', minimum: 4.5 },
  {
    label: 'body text on a raised surface',
    foreground: '--color-ink',
    background: '--color-surface-raised',
    minimum: 4.5,
  },
  {
    label: 'muted text',
    foreground: '--color-ink-muted',
    background: '--color-surface',
    minimum: 4.5,
  },
  {
    label: 'muted text on a raised surface',
    foreground: '--color-ink-muted',
    background: '--color-surface-raised',
    minimum: 4.5,
  },
  {
    label: 'label on an accent button',
    foreground: '--color-accent-ink',
    background: '--color-accent',
    minimum: 4.5,
  },
  {
    label: 'accent text',
    foreground: '--color-accent',
    background: '--color-surface',
    minimum: 4.5,
  },
  {
    label: 'accent text on a raised surface',
    foreground: '--color-accent',
    background: '--color-surface-raised',
    minimum: 4.5,
  },
  {
    label: 'error text',
    foreground: '--color-danger',
    background: '--color-surface',
    minimum: 4.5,
  },
  {
    label: 'error text on a raised surface',
    foreground: '--color-danger',
    background: '--color-surface-raised',
    minimum: 4.5,
  },
  {
    label: 'success text',
    foreground: '--color-success',
    background: '--color-surface',
    minimum: 4.5,
  },
  {
    label: 'control boundary',
    foreground: '--color-border-strong',
    background: '--color-surface',
    minimum: 3,
  },
  {
    label: 'control boundary on a raised surface',
    foreground: '--color-border-strong',
    background: '--color-surface-raised',
    minimum: 3,
  },
]

/**
 * Contrast ratios for every pair above, in the theme requested.
 *
 * Measured in the browser rather than computed from the source, because the
 * palette is authored in oklch and the conversion that matters is the one the
 * viewer's screen gets. Chrome serialises a computed oklch() colour as oklch(),
 * so the conversion to sRGB is done by painting each colour onto a canvas and
 * reading the pixel back.
 */
async function contrastRatios(
  page: Page,
  theme: 'light' | 'dark',
): Promise<Record<string, number>> {
  return page.evaluate(
    ({ pairs, theme }) => {
      document.documentElement.classList.toggle('dark', theme === 'dark')

      const probe = document.createElement('div')
      document.body.appendChild(probe)

      const canvas = document.createElement('canvas')
      canvas.width = 1
      canvas.height = 1
      const ctx = canvas.getContext('2d', { willReadFrequently: true })

      if (ctx === null) throw new Error('no 2d context to convert colours with')

      function channels(token: string): [number, number, number] {
        probe.style.color = `var(${token})`

        ctx.clearRect(0, 0, 1, 1)
        ctx.fillStyle = getComputedStyle(probe).color
        ctx.fillRect(0, 0, 1, 1)

        const data = ctx.getImageData(0, 0, 1, 1).data

        return [data[0], data[1], data[2]]
      }

      function luminance([r, g, b]: [number, number, number]): number {
        const linear = (value: number): number => {
          const channel = value / 255
          return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
        }

        return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b)
      }

      const ratios: Record<string, number> = {}

      for (const pair of pairs) {
        const a = luminance(channels(pair.foreground))
        const b = luminance(channels(pair.background))

        ratios[pair.label] =
          Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100
      }

      probe.remove()

      return ratios
    },
    { pairs: CONTRAST, theme },
  )
}

test.describe('WCAG 2.2', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`text and controls meet their contrast minimum in the ${theme} theme`, async ({
      page,
    }) => {
      await page.goto('/')

      const ratios = await contrastRatios(page, theme)

      for (const pair of CONTRAST) {
        expect(
          ratios[pair.label],
          `${pair.label} (${theme}): ${String(ratios[pair.label])}:1, needs ${String(pair.minimum)}:1`,
        ).toBeGreaterThanOrEqual(pair.minimum)
      }
    })
  }

  test('every page puts the skip link first and lands focus in the content', async ({ page }) => {
    for (const path of ['/', '/login', '/register', '/attribution']) {
      await page.goto(path)

      await page.keyboard.press('Tab')

      const skip = page.getByRole('link', { name: 'Skip to main content' })
      await expect(skip, `${path} does not start with a skip link`).toBeFocused()

      // Focusable, so the target actually receives focus rather than merely
      // scrolling — SC 2.4.3, and the reason <main> carries tabindex="-1".
      await expect(page.locator('#main-content')).toHaveAttribute('tabindex', '-1')
    }
  })

  test('interactive controls meet the 24 by 24 minimum target size', async ({ page }) => {
    await page.goto('/login')

    // SC 2.5.8 (new in WCAG 2.2). Inline links in a sentence are exempt, and
    // so is anything visually hidden — the sr-only structure lists are reached
    // by keyboard, never by pointer.
    const targets = page.locator(
      'main button:visible, main a:visible:not(p a), main input:visible, main select:visible',
    )

    const count = await targets.count()
    expect(count).toBeGreaterThan(0)

    for (let index = 0; index < count; index += 1) {
      const target = targets.nth(index)

      // A control wrapped in its own <label> is targeted by the whole label —
      // clicking the word toggles the box — so that is the box SC 2.5.8 is
      // about. Measuring the 18px checkbox glyph alone would report a failure
      // that no pointer user experiences.
      const handle = target.locator('xpath=ancestor-or-self::*[self::label][1]')
      const measured = (await handle.count()) > 0 ? handle.first() : target

      const box = await measured.boundingBox()

      if (box === null) continue

      const label = (await measured.textContent())?.trim().slice(0, 40) ?? '(unlabelled)'

      expect(Math.round(box.height), `"${label}" is ${String(box.height)}px tall`).toBeGreaterThanOrEqual(24)
      expect(Math.round(box.width), `"${label}" is ${String(box.width)}px wide`).toBeGreaterThanOrEqual(24)
    }
  })

  test('reduced motion is honoured, and is not the same thing as no feedback', async ({
    browser,
  }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' })
    const page = await context.newPage()

    await page.goto('/')

    // The global rule collapses every transition and animation to ~0. Asserted
    // on a real element rather than by reading the stylesheet, so a later
    // override in a component would fail this. Parsed numerically because
    // Chrome serialises 0.01ms as "1e-05s".
    const seconds = (value: string): number[] =>
      value.split(',').map((part) => Number.parseFloat(part.trim()))

    const durations = await page
      .getByRole('link', { name: 'Get started' })
      .evaluate((el) => {
        const style = getComputedStyle(el)
        return { transition: style.transitionDuration, animation: style.animationDuration }
      })

    for (const value of [...seconds(durations.transition), ...seconds(durations.animation)]) {
      expect(value).toBeLessThan(0.001)
    }

    // Reduced motion is not "no feedback": the page still says what happened,
    // it just does not animate saying it. The skip link is the cheapest proof
    // that focus styling — which is not motion — is untouched.
    await page.keyboard.press('Tab')
    await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused()

    await context.close()
  })

  test('the attribution page is a document, with landmarks and a heading order', async ({
    page,
  }) => {
    await page.goto('/attribution')

    await expect(page.getByRole('main')).toBeVisible()
    await expect(page.getByRole('contentinfo')).toBeVisible()

    // One h1, and the register's own headings below it — a table of licence
    // statuses is only navigable by heading if the levels are right.
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
    await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible()
  })
})
