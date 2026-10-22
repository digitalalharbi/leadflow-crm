import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Everything that has happened to a lead, in one coloured column that can be filtered. */
test.describe('Lead journey timeline', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/leads')
    await page.locator('a[href^="/leads/"]').first().click()
  })

  test('shows one entry per event, each with its kind and who did it', async ({ page }) => {
    await expect(page.getByTestId('activity-timeline')).toBeVisible()
    const entries = page.getByTestId('journey-entry')
    await expect(entries.first()).toBeVisible()
    await expect(entries.first()).toHaveAttribute('data-kind', /.+/)
    await expect(entries.first().getByTestId('journey-actor')).toContainText(/\S/)
  })

  test('a filter shows only that kind, and pressing it again shows all', async ({ page }) => {
    const filters = page.getByTestId('journey-filters').locator('button')
    await expect(filters.first()).toBeVisible()
    const total = await page.getByTestId('journey-entry').count()

    await filters.first().click()
    const kind = await page.getByTestId('journey-entry').first().getAttribute('data-kind')
    for (const entry of await page.getByTestId('journey-entry').all()) {
      await expect(entry).toHaveAttribute('data-kind', kind!)
    }

    await filters.first().click()
    await expect(page.getByTestId('journey-entry')).toHaveCount(total)
  })
})
