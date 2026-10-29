import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The operations home: today in nine numbers, then what needs a person and how everything is doing — visual first. */
test.describe('Operations home', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('a manager opens to the nine answers, then the attention queue, the health panels, the ranking and the tops', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    await expect(page.getByTestId('ops-answers')).toBeVisible()
    await expect(page.getByTestId('ops-answers').locator('a')).toHaveCount(9)

    const home = page.getByTestId('operations-home')
    await expect(home).toBeVisible()
    await expect(home.getByTestId('home-attention')).toBeVisible()
    await expect(home.getByTestId('home-campaigns')).toBeVisible()
    await expect(home.getByTestId('home-tracking')).toBeVisible()
    await expect(home.getByTestId('home-automation')).toBeVisible()
    await expect(home.getByTestId('home-revenue')).toBeVisible()
    await expect(home.getByTestId('home-tops')).toBeVisible()

    // The operations centre comes before the journey strip and the board: first thing on the page.
    const homeY = (await home.boundingBox())!.y
    const board = page.getByTestId('journey-strip')
    if (await board.count()) expect((await board.boundingBox())!.y).toBeGreaterThan(homeY)
  })

  test('an attention row opens what it counts', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const row = page.getByTestId('operations-home').locator('[data-testid^="home-attention-"]:not([data-testid="home-attention-clear"])').first()
    if (await row.count()) {
      await row.click()
      await expect(page).not.toHaveURL(/^[^?]*\/dashboard$/)
    }
  })

  test('a rep sees their own nine answers and none of the team’s health, ranking or campaigns', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')

    await expect(page.getByTestId('ops-answers')).toBeVisible()
    for (const hidden of ['home-campaigns', 'home-tracking', 'home-automation', 'home-ranking', 'home-top-campaign', 'home-top-partner']) {
      await expect(page.getByTestId(hidden)).toHaveCount(0)
    }
  })

  test('the panels that carry money or the team are not drawn for a client', async ({ page }) => {
    await authenticateAs(page, ALPHA.client.email)
    await page.goto('/dashboard')
    await expect(page.getByTestId('ops-answers')).toBeVisible()
    for (const hidden of ['home-revenue', 'home-ranking', 'home-campaigns', 'home-automation']) await expect(page.getByTestId(hidden)).toHaveCount(0)
  })
})
