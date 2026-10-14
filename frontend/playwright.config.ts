import { defineConfig, devices } from '@playwright/test'

/**
 * The API base URL the SPA talks to. Vite proxies /api to this in dev, and the
 * tests also call it directly for the setup/isolation checks that are faster
 * and more honest to assert at the API layer.
 */
const API_URL = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000'
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173'

export default defineConfig({
  testDir: './e2e/specs',
  outputDir: './e2e/.results',

  // Each spec file resets the fixtures it needs, so files must not interleave.
  fullyParallel: false,
  workers: 1,

  // A flaky E2E suite is worse than none: it trains people to ignore red.
  // One retry absorbs genuine timing noise; a second failure is a real failure.
  retries: process.env.CI ? 1 : 0,
  forbidOnly: Boolean(process.env.CI),

  timeout: 45_000,
  expect: { timeout: 10_000 },

  reporter: process.env.CI
    ? [['github'], ['html', { open: 'never', outputFolder: './e2e/.report' }], ['list']]
    : [['list']],

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: process.env.CI ? 'retain-on-failure' : 'off',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
    // The product is Arabic-first; testing it in English would test a path no
    // customer uses on day one.
    locale: 'ar-SA',
    extraHTTPHeaders: { 'Accept-Language': 'ar' },
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
      // The mobile spec asserts drawer behaviour and stacked layouts that only
      // exist below the `lg` breakpoint, so it belongs to the mobile project.
      // The density spec runs at a smaller laptop size of its own, below.
      testIgnore: /mobile\.spec\.ts|density\.spec\.ts/,
    },
    {
      /*
       * The screen most of this product is actually used on.
       *
       * 1366×768 is still the commonest laptop in the market this sells into,
       * and it is the size everything was quietly too big for: a layout that
       * fits 1440×900 can be a page and a half here. Small project, one spec,
       * because the whole suite at a second desktop size would double the run
       * to catch one class of problem.
       */
      name: 'laptop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 } },
      testMatch: /density\.spec\.ts/,
    },
    {
      // A sales rep works the phone far more than a desktop, so the layouts that
      // matter most get a second run. Deliberately narrow: re-running the whole
      // suite on mobile would double the time to catch the same regressions.
      name: 'mobile',
      use: { ...devices['Pixel 7'] },
      testMatch: /mobile\.spec\.ts/,
    },
  ],

  // Servers are started by the harness in CI; locally reuse whatever is running.
  webServer: process.env.E2E_NO_SERVER
    ? undefined
    : {
        // Bound explicitly: the base URL above is 127.0.0.1, and Vite's default
        // host can resolve to ::1 only, which makes the whole suite fail with
        // ERR_CONNECTION_REFUSED on a machine where that happens.
        command: 'npm run dev -- --port 5173 --host 127.0.0.1',
        url: BASE_URL,
        reuseExistingServer: true,
        timeout: 60_000,
      },

  metadata: { apiUrl: API_URL },
})
