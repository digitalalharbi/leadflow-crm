import { defineConfig, devices } from '@playwright/test'

/**
 * The installable app, tested the way a user gets it: a PRODUCTION build (the service worker exists
 * only there) served by `vite preview`, in each engine. Separate from the main suite, which drives the
 * dev server and must never have a worker caching its files.
 *
 *   E2E_API_URL=http://127.0.0.1:8012 npx playwright test -c playwright.pwa.config.ts
 */
const API_URL = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000'
const PORT = Number(process.env.PWA_PORT ?? 5303)
const BASE_URL = `http://127.0.0.1:${PORT}`

export default defineConfig({
  testDir: './e2e/pwa',
  outputDir: './e2e/.results-pwa',
  globalSetup: './e2e/pwa/build.ts',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],

  use: {
    baseURL: BASE_URL,
    locale: 'ar-SA',
    serviceWorkers: 'allow',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    extraHTTPHeaders: { 'Accept-Language': 'ar' },
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 } } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'], viewport: { width: 1366, height: 768 } } },
    { name: 'webkit', use: { ...devices['Desktop Safari'], viewport: { width: 1366, height: 768 } } },
    { name: 'iphone', use: { ...devices['iPhone 14'] } },
    { name: 'android', use: { ...devices['Pixel 7'] } },
  ],

  webServer: {
    command: `npx vite preview --outDir dist-pwa --port ${PORT} --host 127.0.0.1 --strictPort`,
    url: BASE_URL,
    reuseExistingServer: true,
    timeout: 60_000,
    env: { VITE_PROXY_TARGET: API_URL },
  },

  metadata: { apiUrl: API_URL },
})
