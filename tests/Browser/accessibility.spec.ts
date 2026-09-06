import { expect, test, type Page } from '@playwright/test'
import { ATELIER_CONTRAST_PAIRINGS } from '../../resources/js/design/palette'

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
 * The pairings, taken from the palette inventory rather than restated.
 *
 * `resources/js/design/theme.contrast.test.ts` asserts the same list against
 * the values authored in theme.css. This asserts it against the values a real
 * browser resolved on a real page, which is the half a source-level check
 * cannot see: a token shadowed by a later rule, or a colour that never made it
 * into the built stylesheet at all.
 */
const CONTRAST = ATELIER_CONTRAST_PAIRINGS.map((pairing) => ({
  label: `${pairing.usage} (--${pairing.foreground} on --${pairing.background})`,
  foreground: `--${pairing.foreground}`,
  background: `--${pairing.background}`,
  minimum: pairing.threshold,
}))

/**
 * Contrast ratios for every pair above, as the browser paints them.
 *
 * Measured rather than computed from the source: a computed custom property is
 * serialised verbatim, so the conversion to sRGB is done by painting each
 * colour onto a canvas and reading the pixel back. That works whatever syntax
 * the token is authored in.
 */
async function contrastRatios(page: Page): Promise<Record<string, number>> {
  return page.evaluate((pairs) => {
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
  }, CONTRAST)
}

test.describe('WCAG 2.2', () => {
  test('text and controls meet their contrast minimum', async ({ page }) => {
    await page.goto('/')

    const ratios = await contrastRatios(page)

    for (const pair of CONTRAST) {
      expect(
        ratios[pair.label],
        `${pair.label}: ${String(ratios[pair.label])}:1, needs ${String(pair.minimum)}:1`,
      ).toBeGreaterThanOrEqual(pair.minimum)
    }
  })

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

      expect(
        Math.round(box.height),
        `"${label}" is ${String(box.height)}px tall`,
      ).toBeGreaterThanOrEqual(24)
      expect(
        Math.round(box.width),
        `"${label}" is ${String(box.width)}px wide`,
      ).toBeGreaterThanOrEqual(24)
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

    const durations = await page.getByRole('link', { name: 'Get started' }).evaluate((el) => {
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

  /*
   |---------------------------------------------------------------------------
   | The atelier visual language — handover 15
   |---------------------------------------------------------------------------
   |
   | /explore is the page the whole restyle is judged on, and the one with the
   | most chrome floating over a canvas. There is no axe-core in this project
   | and adding one is a dependency this handover may not take, so the criteria
   | below are the mechanical ones written out: a single h1, landmarks, unique
   | ids, an accessible name on every control, and no sideways scroll.
   |
   | One test rather than four, and one account rather than four. /explore is
   | behind auth, so each of these costs a registration and a seeded page load;
   | split up they made the suite slow enough to start timing out on the tests
   | that came after them.
   */
  test('explore is structurally sound at every width', async ({ page }) => {
    await signInForExplore(page)
    await page.goto('/explore')

    await expect(page.getByRole('main')).toBeVisible()
    await expect(page.getByRole('contentinfo')).toBeVisible()
    await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)

    const duplicateIds = await page.evaluate(() => {
      const ids = [...document.querySelectorAll('[id]')].map((node) => node.id)
      return ids.filter((id, index) => ids.indexOf(id) !== index)
    })

    expect(duplicateIds, 'duplicate ids on /explore').toEqual([])

    const unnamed = await page.evaluate(() =>
      [...document.querySelectorAll('main button, main a[href]')]
        .filter((node) => {
          const label = node.getAttribute('aria-label') ?? (node.textContent ?? '').trim()

          return label === ''
        })
        .map((node) => node.outerHTML.slice(0, 80)),
    )

    expect(unnamed, 'controls with no accessible name').toEqual([])

    // Wide content scrolls inside its own box; the page never scrolls sideways.
    for (const width of [1440, 1024, 768, 390]) {
      await page.setViewportSize({ width, height: 900 })

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )

      expect(
        overflow,
        `${String(width)}px viewport scrolls ${String(overflow)}px sideways`,
      ).toBeLessThanOrEqual(1)
    }
  })

  test('reduced motion leaves auto-rotate off, and says why rather than lying', async ({
    browser,
  }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' })
    const page = await context.newPage()

    await signInForExplore(page)
    await page.goto('/explore')

    const rotate = page.getByRole('switch')

    // Off, inert, and labelled with the reason. A switch the viewer has already
    // declined must not look like one a student can turn on.
    await expect(rotate).toHaveAttribute('aria-checked', 'false')
    await expect(rotate).toBeDisabled()
    await expect(page.getByText('Motion reduced')).toBeVisible()

    await context.close()
  })
})

/**
 * A fresh account per run. /explore is behind auth while the licence gate is
 * open, so every assertion above needs one (routes/features/explore.php).
 */
async function signInForExplore(page: Page): Promise<void> {
  const email = `a11y-${String(Date.now())}-${String(Math.floor(Math.random() * 1e6))}@example.test`

  await page.goto('/register')
  await page.getByLabel('Name').fill('Accessibility Test User')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('correct-horse-battery')
  await page.getByLabel('Confirm password').fill('correct-horse-battery')
  await page.getByRole('button', { name: /register|create/i }).click()

  await expect(page).toHaveURL(/\/dashboard/)
}
