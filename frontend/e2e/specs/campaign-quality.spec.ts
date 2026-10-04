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

    await page.getByTestId('tab-campaigns').click()

    // The chart answers "which campaign" at a glance; the table carries the
    // figures underneath it. Both have to be there.
    await expect(page.getByTestId('report-campaign-quality')).toBeVisible()

    const table = page.getByTestId('campaign-table')
    await expect(table).toBeVisible()
    await expect(table).toContainText('حملة الاختبار')

    // The share is the point of the report; a lead count alone hides a bad campaign.
    await expect(table).toContainText('نسبة التأهيل')
    await expect(table).toContainText('%')
  })

  test('the headline figures are rates, not only totals', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/reports')

    const rates = page.getByTestId('report-rates')
    await expect(rates).toBeVisible()

    // Five rates, every one of them a percentage of the stage before it.
    for (const label of ['نسبة التواصل', 'نسبة الرد', 'نسبة التأهيل', 'نسبة المواعيد', 'نسبة الإغلاق']) {
      await expect(rates).toContainText(label)
    }
  })

  test('a sales rep cannot open the reports page at all', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/reports')

    // Reports are an export-level permission; the nav does not offer it either.
    await expect(page.getByRole('link', { name: 'التقارير' })).toHaveCount(0)
  })
})
