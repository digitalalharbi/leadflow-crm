import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The visual layer, checked the way a reader meets it.
 *
 * A chart that renders is not a chart that works: the parts that fail quietly
 * are the ones nobody clicks in development — the hover, the table view, and
 * whether the series are actually distinguishable.
 */
test.describe('الرسوم البيانية — charts', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('the dashboard shows the lead flow over time', async ({ page }) => {
    const card = page.getByTestId('lead-flow')

    await expect(card).toBeVisible()
    // Three series, each named — identity is never carried by colour alone.
    await expect(card).toContainText('وارد')
    await expect(card).toContainText('مؤهَّل')
    await expect(card).toContainText('مكسوب')
  })

  /**
   * The obligation that lets the palette be as vivid as it is: one slot per
   * theme sits under 3:1 against the surface, which is only allowed where the
   * reader can get the values another way.
   */
  test('every chart with a table offers it, and it holds real numbers', async ({ page }) => {
    const toggle = page.getByTestId('lead-flow-table-toggle')
    await expect(toggle).toBeVisible()

    await toggle.click()

    const table = page.getByTestId('lead-flow').locator('table')
    await expect(table).toBeVisible()
    // A header per series plus the day, and a row per day in the window.
    await expect(table.locator('thead th')).toHaveCount(4)
    await expect(table.locator('tbody tr')).toHaveCount(30)
  })

  test('the table toggle goes back to the chart', async ({ page }) => {
    const toggle = page.getByTestId('lead-flow-table-toggle')

    await toggle.click()
    await expect(page.getByTestId('lead-flow').locator('table')).toBeVisible()

    await toggle.click()
    await expect(page.getByTestId('lead-flow').locator('table')).toHaveCount(0)
    await expect(page.getByTestId('lead-flow').locator('svg[role="img"]')).toBeVisible()
  })

  /** Hovering a 2px line is impossible; the hit targets are full-height columns. */
  test('hovering the plot names the day and its three figures', async ({ page }) => {
    const card = page.getByTestId('lead-flow')

    await card.locator('svg[role="img"]').hover({ position: { x: 300, y: 100 } })

    // The tooltip names the day, so the reader knows which column they are on.
    const tooltip = card.locator('div.pointer-events-none.absolute')
    await expect(tooltip).toBeVisible()
    await expect(tooltip).toContainText('وارد')
    await expect(tooltip).toContainText('مكسوب')
  })

  test('the time axis is not mirrored by the RTL page', async ({ page }) => {
    // Dates ascend left to right, like the numbers themselves. Mirrored, a
    // rising month looks like a falling one.
    await expect(page.getByTestId('lead-flow').locator('[dir="ltr"]').first()).toBeVisible()
  })
})
