import { defineConfig, devices } from '@playwright/test'

/**
 * One smoke journey, not a second test suite (docs/architecture.md §15.2).
 *
 * Handover 14 owns the PRD §44 journey test. This config exists now so that
 * handover inherits a working runner rather than standing one up under
 * deadline.
 */
export default defineConfig({
  testDir: './tests/Browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? 'github' : 'list',

  use: {
    baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

  // Started by the person or CI job running the test, not by Playwright: the
  // app needs a migrated database first, and hiding that in a webServer block
  // makes a failure look like a browser problem.
})
