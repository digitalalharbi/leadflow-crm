import { test, type Page } from '@playwright/test'
import { ALPHA, PASSWORD } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { ensurePlatformOwner } from '../fixtures/seed'

/**
 * The visual review pass, as something that can be re-run.
 *
 * This asserts nothing. Green tests were never evidence that a screen looks
 * right — every design bug this release fixed was found by opening a page and
 * looking at it, and none of them failed a test. So the suite's job here is
 * only to put every screen in front of a person quickly, in all three of the
 * modes that actually break: light, dark, and a phone.
 *
 *   npx playwright test --config=playwright.visual.config.ts
 *
 * Output lands in artifacts/visual-review/, which is not tracked — the recipe
 * is in the repository, the pictures are regenerated on demand.
 */

// The platform owner belongs to no tenant, so the fixtures do not carry one.
const OWNER = 'owner@e2e-visual.test'

const SCREENS = [
  { name: 'dashboard', path: '/dashboard' },
  { name: 'leads', path: '/leads' },
  { name: 'pipeline', path: '/pipeline' },
  { name: 'follow-ups-overdue', path: '/follow-ups?bucket=overdue' },
  { name: 'projects', path: '/projects' },
  { name: 'reports-overview', path: '/reports' },
  { name: 'integrations', path: '/integrations' },
  { name: 'users', path: '/users' },
  { name: 'settings', path: '/settings' },
] as const

/** Waits for the page to have rendered rather than for a fixed delay. */
async function settle(page: Page) {
  await page.getByTestId('page-title').waitFor({ state: 'visible', timeout: 20_000 })
  await page.waitForLoadState('networkidle').catch(() => undefined)
  // Let the charts' entry transitions finish, or every capture is mid-animation.
  await page.waitForTimeout(600)
}

async function setTheme(page: Page, theme: 'light' | 'dark') {
  await page.addInitScript((value) => {
    try {
      localStorage.setItem('leadflow.appearance', value)
    } catch {
      /* ignore */
    }
  }, theme)
}

for (const theme of ['light', 'dark'] as const) {
  test.describe(`${theme} — tenant workspace`, () => {
    test.beforeEach(async ({ page }) => {
      await setTheme(page, theme)
      await authenticateAs(page, ALPHA.admin.email)
    })

    for (const screen of SCREENS) {
      test(`${screen.name}`, async ({ page }, testInfo) => {
        await page.goto(screen.path)
        await settle(page)

        const device = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop'

        await page.screenshot({
          path: `../artifacts/visual-review/${device}-${theme}-${screen.name}.png`,
          fullPage: true,
        })
      })
    }
  })
}

test.describe('platform console', () => {
  test.beforeAll(() => {
    ensurePlatformOwner(OWNER, PASSWORD)
  })

  for (const theme of ['light', 'dark'] as const) {
    test(`${theme}`, async ({ page }, testInfo) => {
      await setTheme(page, theme)
      await authenticateAs(page, OWNER)
      await page.goto('/platform')
      await settle(page)

      const device = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop'

      await page.screenshot({
        path: `../artifacts/visual-review/${device}-${theme}-platform.png`,
        fullPage: true,
      })
    })
  }
})

test.describe('login', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`${theme}`, async ({ page }, testInfo) => {
      await setTheme(page, theme)
      await page.goto(`/login?tenant=${ALPHA.slug}`)
      await page.getByRole('button', { name: 'تسجيل الدخول' }).waitFor()
      await page.waitForTimeout(600)

      const device = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop'

      await page.screenshot({
        path: `../artifacts/visual-review/${device}-${theme}-login.png`,
        fullPage: true,
      })
    })
  }
})
