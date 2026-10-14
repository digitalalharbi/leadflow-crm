import { chromium, type FullConfig } from '@playwright/test'

/**
 * Load the app once before any test runs.
 *
 * `webServer.url` proves the port answers; it does not prove the application
 * renders. Those are different facts, and when they disagree the suite says so
 * in the worst available way — the first test fails on a navigation timeout,
 * which reads as a problem with that test rather than with the server every
 * test is about to use.
 *
 * This asks for the page itself, so a broken or still-building front end fails
 * here, once, before the first spec. On a machine with no `node_modules/.vite`
 * cache — any CI runner — that first load is also where the dependency
 * optimiser does its work, and it is paid here rather than out of a test's
 * budget.
 *
 * The per-test navigation timeout is deliberately left alone. A navigation that
 * is slow in the middle of a run is still a failure, and still worth hearing.
 */
export default async function warmTheApp(config: FullConfig) {
  const baseURL = config.projects[0]?.use?.baseURL ?? process.env.E2E_BASE_URL

  if (!baseURL) return

  const browser = await chromium.launch()

  try {
    const page = await browser.newPage()

    // Generous, because this is a build rather than a page view, and a cold
    // machine is allowed to take its time exactly once.
    await page.goto(baseURL, { waitUntil: 'load', timeout: 180_000 })
  } finally {
    await browser.close()
  }
}
