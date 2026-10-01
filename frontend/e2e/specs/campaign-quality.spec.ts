import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The report a developer buys the product for: which campaigns bring buyers.
 */
test.describe('Campaign quality', () => {
  test.beforeAll(() => resetFixtures())

  test('the report lists campaigns with their rates, not just their counts', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/reports')

    await page.getByRole('button', { name: 'جودة الحملات' }).click()

    const report = page.getByTestId('campaign-quality')
    await expect(report).toBeVisible()
    await expect(report).toContainText('حملة الاختبار')

    // The share is the point of the report; a lead count alone hides a bad campaign.
    await expect(report).toContainText('نسبة التأهيل')
    await expect(report).toContainText('%')
  })

  test('the headline figures include the response time', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByRole('button', { name: 'جودة الحملات' }).click()

    await expect(page.getByTestId('avg-first-contact')).toBeVisible()
  })

  test('a sales rep cannot open the reports page at all', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/reports')

    // Reports are an export-level permission; the nav does not offer it either.
    await expect(page.getByRole('link', { name: 'التقارير' })).toHaveCount(0)
  })
})
