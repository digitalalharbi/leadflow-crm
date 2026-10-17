import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * مهتم is the product, on every screen, until a customer renames it.
 *
 * `identity.spec` covers the title and the manifest of one workspace. This is the
 * wider net: walk the whole workspace and fail on any name the product has been
 * called, because each one that survives somewhere is a customer asking which
 * company they are actually buying from — and a name found by a customer is a
 * name found too late.
 */
const ROUTES = [
  '/', '/work', '/leads', '/pipeline', '/follow-ups', '/projects', '/automation',
  '/reports', '/data-quality', '/integrations', '/readiness', '/users', '/settings',
]

const NAMES = /\b(alpha|beta|leadflow|lead\s*flow|mahtam|muhtam)\b|ألفا|بيتا/i

test.describe('The product has one name', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('a workspace that has not renamed it shows مهتم in the chrome', async ({ page }) => {
    await expect(page.locator('aside').first()).toContainText('مهتم')
    await expect(page).toHaveTitle('مهتم')
  })

  for (const route of ROUTES) {
    test(`${route} names the product nothing else`, async ({ page }) => {
      await page.goto(route)
      await page.waitForLoadState('networkidle')

      const text = await page.locator('body').innerText()
      const found = text.match(NAMES)?.[0]

      expect(found, `"${found}" is on ${route}`).toBeUndefined()
    })
  }

  test('the login screen says it too', async ({ page }) => {
    await page.context().clearCookies()
    await page.evaluate(() => localStorage.clear())
    await page.goto('/login')

    await expect(page.locator('body')).toContainText('مهتم')
    expect(await page.locator('body').innerText()).not.toMatch(NAMES)
  })
})
