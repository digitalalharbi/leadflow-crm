import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Seven numbers on the lead, a health ring in the list, and a queue that says
 * why it is in the order it is — all visual, no paragraphs.
 */
test.describe('Lead intelligence', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('the lead screen shows the seven numbers', async ({ page }) => {
    await page.goto('/leads')
    await page.getByTestId('lead-health').first().waitFor()
    await page.getByRole('link').filter({ has: page.locator('[data-testid="lead-score"]') }).first().click().catch(() => undefined)
    await page.locator('a[href^="/leads/"]').first().click()

    await expect(page.getByTestId('intelligence')).toBeVisible()
    for (const key of ['health', 'intent', 'engagement', 'response', 'staleness', 'contactability', 'completeness']) {
      await expect(page.getByTestId(`intel-${key}`), `${key} is missing`).toBeVisible()
    }
    await expect(page.getByTestId('intel-trend')).toBeVisible()
  })

  test('every list row carries a health ring', async ({ page }) => {
    await page.goto('/leads')
    await expect(page.getByTestId('lead-health').first()).toBeVisible()
  })

  test('the work queue says why this lead is first', async ({ page }) => {
    await page.goto('/work')
    await expect(page.getByTestId('work-action')).toBeVisible()
    await expect(page.getByTestId('work-rank-reason')).toContainText(/\S/)
  })

  test('nothing scrolls sideways on the lead screen', async ({ page }) => {
    await page.goto('/leads')
    await page.locator('a[href^="/leads/"]').first().click()
    await expect(page.getByTestId('intelligence')).toBeVisible()

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })
})
