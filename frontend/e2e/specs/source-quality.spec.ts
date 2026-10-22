import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Where leads come from, ranked by how far they get. */
test.describe('Source quality', () => {
  test.beforeAll(() => resetFixtures())

  test('sources are ranked, each with the shape of its funnel', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-quality').click()
    const report = page.getByTestId('quality-report')

    await report.getByTestId('quality-level').getByRole('tab', { name: 'المصدر' }).click()
    const ranking = report.getByTestId('quality-ranking')
    await expect(ranking).toBeVisible()

    const rows = ranking.getByTestId('quality-rank')
    await expect(rows.first()).toBeVisible()
    for (const rate of ['valid_rate', 'contactable_rate', 'engaged_rate', 'qualified_rate', 'appointment_rate', 'won_rate']) {
      await expect(rows.first().locator(`[data-rate="${rate}"]`)).toBeVisible()
    }

    // Best first.
    const indexes = (await ranking.getByTestId('quality-index').allInnerTexts()).map(Number)
    expect(indexes).toEqual([...indexes].toSorted((a, b) => b - a))

    // And the table below carries the same sources, with reachability and revenue per lead.
    await expect(report.getByTestId('quality-table').locator('tbody tr').first()).toBeVisible()
    await expect(report.getByTestId('quality-table')).toContainText('إيراد/عميل')
    await expect(report.getByTestId('quality-table')).toContainText('قابلون للتواصل')
  })
})
