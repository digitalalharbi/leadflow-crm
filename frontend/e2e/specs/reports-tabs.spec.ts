import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * Reports is five questions sharing one period.
 *
 * The thing worth protecting is that last part: a manager sets a date range to
 * ask about campaigns, switches to the team tab, and the period must still be
 * the one they chose. A tab that resets the filters makes the page a set of
 * unrelated reports that happen to live on one screen.
 */
test.describe('Reports', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await expect(page.getByRole('heading', { name: 'التقارير', level: 1 })).toBeVisible()
  })

  test('every tab is reachable and renders its own report', async ({ page }) => {
    await expect(page.getByTestId('tab-overview')).toBeVisible()

    await page.getByTestId('tab-campaigns').click()
    await expect(page.getByTestId('tab-campaigns')).toHaveAttribute('aria-current', 'true')

    await page.getByTestId('tab-platforms').click()
    await expect(page.getByTestId('tab-platforms')).toHaveAttribute('aria-current', 'true')

    await page.getByTestId('tab-team').click()
    await expect(page.getByTestId('tab-team')).toHaveAttribute('aria-current', 'true')

    await page.getByTestId('tab-lost').click()
    await expect(page.getByTestId('tab-lost')).toHaveAttribute('aria-current', 'true')
  })

  test('the period survives switching tabs', async ({ page }) => {
    const from = page.getByTestId('report-date-from')
    await from.fill('2026-01-01')

    await page.getByTestId('tab-team').click()
    await expect(from).toHaveValue('2026-01-01')

    await page.getByTestId('tab-campaigns').click()
    await expect(from).toHaveValue('2026-01-01')
  })

  test('the empty date field shows a hint a reader can read', async ({ page }) => {
    // Chromium renders its own Arabic date hint as reversed, unshaped text, so
    // the field has to supply its own. Asserting the readable one is present
    // is the only way this regression is visible to a test at all.
    await expect(page.getByText('من', { exact: true }).first()).toBeVisible()
    await expect(page.getByText('إلى', { exact: true }).first()).toBeVisible()
  })

  test('filters can be cleared once they are set', async ({ page }) => {
    await page.getByTestId('report-date-from').fill('2026-01-01')

    const clear = page.getByTestId('report-clear')
    await expect(clear).toBeVisible()
    await clear.click()

    await expect(page.getByTestId('report-date-from')).toHaveValue('')
  })

  test('the team report measures people without ranking them', async ({ page }) => {
    await page.getByTestId('tab-team').click()

    // Every rep who owns a lead appears. Scoped to the table: the name is also
    // one of the owner filter's options.
    await expect(page.getByRole('cell', { name: ALPHA.rep1.name })).toBeVisible()

    // And deliberately no league position: the report is measurements, not a
    // scoreboard, and a rank column is how that turns into one.
    await expect(page.getByText('الترتيب')).toHaveCount(0)
  })
})
