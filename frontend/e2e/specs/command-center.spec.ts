import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The part of the dashboard that is about now.
 *
 * It exists so that nobody scrolls to learn what needs them: the attention
 * list, the lead to start with and the way into «اعمل الآن» are all on the
 * first screen, above the period figures.
 */
test.describe('Command center', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('says what needs attention and offers the way in', async ({ page }) => {
    await expect(page.getByTestId('command-center')).toBeVisible()
    await expect(page.getByTestId('attention-list')).toBeVisible()
    await expect(page.getByTestId('start-work')).toBeVisible()
  })

  test('recommends leads with a reason and opens the lead', async ({ page }) => {
    const first = page.getByTestId('recommended-item').first()

    await expect(first).toBeVisible()
    // A recommendation without a reason is an instruction nobody trusts.
    await expect(first).not.toHaveText(/^\s*$/)

    await first.click()
    await expect(page).toHaveURL(/\/leads\/[0-9a-f-]{36}/)
  })

  test('the way into the work queue is one press', async ({ page }) => {
    await page.getByTestId('start-work').click()
    await expect(page).toHaveURL(/\/work$/)
  })

  test('an attention row leads to the place that fixes it', async ({ page }) => {
    await page.getByTestId('attention-overdue_follow_ups').click()
    await expect(page).toHaveURL(/\/follow-ups$/)
  })

  test('the period figures are still on the first screen', async ({ page }) => {
    const box = await page.getByTestId('kpi-total').boundingBox()
    const viewport = page.viewportSize()

    expect(box).not.toBeNull()
    // Top of the figures inside the viewport — the command panel must not
    // push them below the fold.
    expect(box!.y).toBeLessThan(viewport!.height)
  })

  test('shows the product name and nothing else', async ({ page }) => {
    await expect(page.locator('body')).not.toContainText(/alpha|beta|leadflow|mahtam/i)
  })
})
