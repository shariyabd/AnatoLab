import { expect, test } from '@playwright/test'

/**
 * Placeholder smoke journey.
 *
 * Handover 14 owns the full PRD §44 journey. This exists so the Playwright
 * runner is proven to work now rather than being stood up under deadline, and
 * so a broken build is caught by something that actually opens a browser.
 *
 * Requires a running server and a migrated database:
 *   php artisan migrate --force && php artisan serve
 */
test('the landing page offers a way to sign up', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'AnatoLab', level: 1 })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Get started' })).toBeVisible()
})

test('the login page is reachable and keyboard navigable', async ({ page }) => {
  await page.goto('/login')

  await expect(page.getByLabel('Email')).toBeVisible()

  // The skip link must be the first tab stop on every page (DoD item 8).
  await page.keyboard.press('Tab')
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused()
})
