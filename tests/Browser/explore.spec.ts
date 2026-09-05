import { expect, test, type Page } from '@playwright/test'

/**
 * PRD §44 steps 2–5 in a real browser: open an organ, interact with it, select
 * a structure, read its explanation — plus the two paths a unit test cannot
 * prove, keyboard-only use and a browser with no WebGL at all.
 *
 * Requires a running server and a migrated, seeded database:
 *   php artisan migrate:fresh --seed && php artisan serve
 *
 * The seeded organs carry placeholder `model_path` values while the licence
 * gate is open (docs/licence-log.md §3), so the GLB requests 404 today and the
 * viewer renders its load-failure explanation. That is deliberate: every
 * assertion below is about the page, not the model, and each one has to hold
 * whether or not a model is there. When real models land, these tests keep
 * passing and gain a canvas.
 */
async function signIn(page: Page): Promise<void> {
  const email = `explore-${String(Date.now())}-${String(Math.floor(Math.random() * 1e6))}@example.test`

  await page.goto('/register')
  await page.getByLabel('Name').fill('Explore Test User')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('correct-horse-battery')
  await page.getByLabel('Confirm password').fill('correct-horse-battery')
  await page.getByRole('button', { name: /register|create/i }).click()

  await expect(page).toHaveURL(/\/dashboard/)
}

test.describe('Explore', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page)
  })

  test('opens an organ, selects a structure, and explains it', async ({ page }) => {
    await page.getByRole('link', { name: 'Explore' }).click()

    // The nav lands on the first published organ, whichever that is — the
    // library is ordered by name, so naming one here would break the day an
    // organ is added before it alphabetically.
    await expect(page).toHaveURL(/\/explore$/)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await expect(
      page.getByRole('list', { name: 'Structures in this organ' }).getByRole('button'),
    ).not.toHaveCount(0)

    await page.getByRole('list', { name: 'Organs' }).getByRole('button', { name: /^Heart/i }).click()

    await expect(page).toHaveURL(/\/explore\/heart$/)
    await expect(page.getByRole('heading', { name: 'Heart', level: 1 })).toBeVisible()

    const structures = page.getByRole('list', { name: 'Structures in this organ' })
    await structures.getByRole('button', { name: /^Left ventricle/i }).click()

    // Step 5: the explanation, not just the label.
    const panel = page.getByRole('region', { name: 'Selected structure' })
    await expect(panel).toContainText(/left ventricle/i)
    await expect(panel.getByRole('heading', { name: 'What it does' })).toBeVisible()
  })

  test('switches organs without leaving the page', async ({ page }) => {
    await page.goto('/explore')

    await page.getByRole('list', { name: 'Organs' }).getByRole('button', { name: /^Lungs/i }).click()

    await expect(page).toHaveURL(/\/explore\/lungs$/)
    await expect(page.getByRole('heading', { name: 'Lungs', level: 1 })).toBeVisible()

    const structures = page.getByRole('list', { name: 'Structures in this organ' })
    // Anchored: "Carina tracheae" is also in this list.
    await expect(structures.getByRole('button', { name: /^Trachea/i })).toBeVisible()
  })

  test('selects a structure with the keyboard alone', async ({ page }) => {
    // The skip link being the first tab stop is the layout's guarantee and is
    // asserted in smoke.spec.ts. What is this page's to prove is that every
    // structure is reachable and activatable without a pointer, including when
    // there is no canvas to click on (docs/engineering.md §4).
    await page.goto('/explore/heart')

    const target = page
      .getByRole('list', { name: 'Structures in this organ' })
      .getByRole('button', { name: /^Left ventricle/i })

    await target.focus()
    await expect(target).toBeFocused()
    await page.keyboard.press('Enter')

    await expect(target).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByRole('region', { name: 'Selected structure' })).toContainText(
      /left ventricle/i,
    )
  })

  test('falls back to text when the browser cannot do WebGL', async ({ page }) => {
    // The viewer probes for a WebGL 2 context before constructing a renderer
    // (resources/js/anatomy/webgl.ts). Refusing that context is the closest a
    // test can get to a machine with hardware acceleration switched off.
    await page.addInitScript(() => {
      const original = HTMLCanvasElement.prototype.getContext

      HTMLCanvasElement.prototype.getContext = function patched(
        this: HTMLCanvasElement,
        ...args: Parameters<typeof original>
      ) {
        if (typeof args[0] === 'string' && args[0].startsWith('webgl')) return null
        return Reflect.apply(original, this, args) as unknown
      } as typeof original
    })

    const consoleErrors: string[] = []
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text())
    })
    page.on('pageerror', (error) => consoleErrors.push(error.message))

    await page.goto('/explore')

    await expect(page.getByText(/cannot show 3D graphics/i)).toBeVisible()

    // Everything that is not the canvas keeps working.
    const structures = page.getByRole('list', { name: 'Structures in this organ' })
    await expect(structures.getByRole('button')).not.toHaveCount(0)

    await structures.getByRole('button').first().click()
    await expect(page.getByRole('region', { name: 'Selected structure' })).not.toContainText(
      'Choose a structure',
    )

    // "No error surface": a page that degrades on purpose does not also throw.
    expect(consoleErrors).toEqual([])
  })
})
