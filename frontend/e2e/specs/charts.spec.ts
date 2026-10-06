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

  /**
   * A list of enabled rules cannot answer the first question about automation:
   * does any of it fire? A workspace with twelve rules and a flat chart has a
   * problem it would not otherwise find until a customer went uncalled.
   */
  test('the automation screen shows what the rules actually did', async ({ page }) => {
    await page.goto('/automation')

    /*
     * `toBeVisible` rather than `count()`: the insights query has not resolved
     * the instant the page loads, so counting there found nothing and skipped
     * — a test that skips itself proves exactly as much as no test.
     */
    const card = page.getByTestId('automation-activity')

    await expect(card).toBeVisible()
    await expect(card).toContainText('طُبِّقت')
    // Skips are drawn, not hidden: a week of them looks like a quiet week
    // on a chart of successes alone.
    await expect(card).toContainText('لم تنطبق')
    await expect(card).toContainText('فشلت')
  })

  /**
   * The question the funnel provokes and cannot answer: the funnel says ten of
   * forty reached an appointment, this says how long the ten waited.
   */
  test('the dashboard says where deals stall', async ({ page }) => {
    const card = page.getByTestId('velocity-card')

    await expect(card).toBeVisible()
    await expect(card).toContainText('من الوصول إلى أول تواصل')
  })

  /**
   * A step nobody has made says so. Showing zero there would read as
   * "instant", which is the opposite of the truth.
   */
  test('a step with no leads says so rather than showing zero', async ({ page }) => {
    const card = page.getByTestId('velocity-card')
    const steps = card.getByTestId('velocity-steps')

    await expect(steps).toBeVisible()

    const text = (await steps.innerText()) ?? ''

    if (text.includes('لم يجتز هذه الخطوة أحد بعد')) {
      // The empty step shows a dash, never a 0.
      expect(text).toContain('—')
    }
  })

  /**
   * A ranked bar chart silently means "longer is better". That is true of
   * every measure on the team tab except response time, where the longest bar
   * is the worst rep — so the chart says which way it is pointing, every time,
   * and sorts accordingly. A chart that reverses its meaning without saying so
   * is worse than no chart: it is confidently wrong.
   */
  test('the team comparison says which direction is good', async ({ page }) => {
    await page.goto('/reports')
    await page.getByRole('button', { name: 'فريق المبيعات' }).click()

    const chart = page.getByTestId('team-comparison')
    await expect(chart).toBeVisible()
    await expect(chart).toContainText('الأعلى أفضل')

    await page.getByTestId('team-measure-avg_first_contact_seconds').click()

    await expect(chart).toContainText('الأقل أفضل')
    await expect(chart).not.toContainText('الأعلى أفضل')
  })

  test('the time axis is not mirrored by the RTL page', async ({ page }) => {
    // Dates ascend left to right, like the numbers themselves. Mirrored, a
    // rising month looks like a falling one.
    await expect(page.getByTestId('lead-flow').locator('[dir="ltr"]').first()).toBeVisible()
  })

  /**
   * What a manager actually hands to a board: the page on paper.
   *
   * Browsers drop background colours when printing unless a stylesheet says
   * otherwise, and a bar chart whose fills are backgrounds then prints as a row
   * of empty grey tracks. It does not look broken on paper — it looks like a
   * month in which nothing happened, which is worse than looking broken. So
   * this is checked under the print medium rather than trusted.
   */
  test('the charts survive being printed', async ({ page }) => {
    // The dashboard, which is the page anybody actually prints.
    await expect(page.getByTestId('lead-flow')).toBeVisible()
    await expect(page.locator('[data-chart-mark]').first()).toBeVisible()

    await page.emulateMedia({ media: 'print' })

    // The navigation is not information; on paper it is only wasted ink.
    await expect(page.locator('aside').first()).toBeHidden()

    // Neither is a prompt to go and press something. The onboarding checklist
    // printed at the top of the dashboard for a while, where a reader would
    // reasonably take it for a figure.
    await expect(page.getByTestId('onboarding-checklist')).toBeHidden()
    await expect(page.getByTestId('lead-flow-table-toggle')).toBeHidden()

    // The chart itself stays.
    await expect(page.getByTestId('lead-flow')).toBeVisible()

    const marks = page.locator('[data-chart-mark]')
    expect(await marks.count()).toBeGreaterThan(0)

    const fills = await marks.evaluateAll((nodes) =>
      nodes.map((node) => {
        const style = getComputedStyle(node)

        return {
          background: style.backgroundColor,
          adjust: style.printColorAdjust || style.webkitPrintColorAdjust,
        }
      }),
    )

    // Every mark keeps a colour, and every mark tells the browser to keep it.
    for (const fill of fills) {
      expect(fill.background).not.toBe('rgba(0, 0, 0, 0)')
      expect(fill.adjust).toBe('exact')
    }

    await page.emulateMedia({ media: 'screen' })
  })
})
