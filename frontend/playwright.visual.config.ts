import { defineConfig, devices } from '@playwright/test'

/**
 * The visual review pass.
 *
 * Kept out of the main config on purpose: it asserts nothing and takes a
 * screenshot of every screen in every mode, which is useful to look through
 * before a release and pure cost on every push.
 *
 *   npx playwright test --config=playwright.visual.config.ts
 */
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173'

export default defineConfig({
  testDir: './e2e/visual',
  outputDir: './e2e/.results-visual',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  reporter: [['list']],

  use: {
    baseURL: BASE_URL,
    locale: 'ar-SA',
    extraHTTPHeaders: { 'Accept-Language': 'ar' },
  },

  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],

  webServer: process.env.E2E_NO_SERVER
    ? undefined
    : {
        command: 'npm run dev -- --port 5173 --host 127.0.0.1',
        url: BASE_URL,
        reuseExistingServer: true,
        timeout: 60_000,
      },
})
