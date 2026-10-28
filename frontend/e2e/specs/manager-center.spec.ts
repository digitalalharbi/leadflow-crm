import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The manager's one screen — and nobody else's. */
test.describe('Manager command center', () => {
  test.beforeAll(() => resetFixtures())

  test('shows the nine figures, the SLA ring, the funnel, the team, the sources and the trend', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const center = page.getByTestId('manager-center')
    await expect(center).toBeVisible()

    for (const key of ['leads-today', 'qualified', 'overdue', 'stalled', 'response', 'appointments', 'conversion', 'revenue']) {
      await expect(center.getByTestId(`kpi-${key}-value`), `${key} tile`).toHaveText(/\S/)
    }

    await expect(center.getByTestId('sla-ring')).toBeVisible()
    await expect(center.getByTestId('sla-overdue')).toHaveText(/\d/)
    await expect(center.getByTestId('sla-stalled')).toBeVisible()
    for (const card of ['manager-funnel', 'manager-team', 'manager-temperature', 'manager-sources', 'manager-trend']) {
      await expect(center.getByTestId(card), card).toBeVisible()
    }
  })

  test('shows how much the automation did: rings, time to qualify, recovered, lost to silence, revenue by quality and a trend', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const automation = page.getByTestId('manager-center').getByTestId('manager-automation')
    await expect(automation).toBeVisible()

    for (const ring of ['coverage', 'human', 'lost']) {
      await expect(automation.getByTestId(`ring-${ring}`), `${ring} ring`).toBeVisible()
      // A percentage, or a dash when there is nothing to divide — never blank.
      await expect(automation.getByTestId(`ring-${ring}-value`), `${ring} value`).toHaveText(/^(\d[\d.,٠-٩]*%|—)$/)
    }
    await expect(automation.getByTestId('automation-qualify-value')).toHaveText(/\S/)
    await expect(automation.getByTestId('automation-recovered-value')).toHaveText(/\d/)
    await expect(automation.getByTestId('automation-trend')).toBeVisible()

    // The money by lead quality is a donut, or says plainly that nothing was won.
    const revenue = page.getByTestId('manager-revenue-quality')
    await expect(revenue).toBeVisible()
    await expect(revenue.locator('svg').or(revenue.getByTestId('revenue-quality-empty')).first()).toBeVisible()
  })

  test('the automation figures follow the period', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    const center = page.getByTestId('manager-center')

    const asked = page.waitForResponse((r) => r.url().includes('/dashboard/manager') && r.url().includes('days=7'))
    await center.getByTestId('manager-period-7').click()
    const body = await (await asked).json()

    expect(body.data.automation.trend).toHaveLength(7)
    await expect(center.getByTestId('manager-automation')).toBeVisible()
  })

  test('the period can be changed and the tiles follow', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    const center = page.getByTestId('manager-center')
    await expect(center.getByTestId('kpi-period-value')).toHaveText(/\d/)

    await center.getByTestId('manager-period-7').click()
    await expect(center.getByTestId('manager-period-7')).toHaveAttribute('aria-pressed', 'true')
    await expect(center.getByTestId('kpi-period')).toContainText('7')
  })

  test('a tile opens the list it counts', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    await page.getByTestId('kpi-stalled').click()
    await expect(page).toHaveURL(/view=stalled/)
    await expect(page.getByTestId('segment-stalled')).toHaveAttribute('aria-pressed', 'true')
  })

  test('a sales rep does not see it, and does not ask for it', async ({ page }) => {
    const asked: string[] = []
    page.on('request', (request) => { if (request.url().includes('/dashboard/manager')) asked.push(request.url()) })

    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    await expect(page.getByTestId('toggle-analytics')).toBeVisible()
    await expect(page.getByTestId('manager-center')).toHaveCount(0)
    expect(asked).toEqual([])
  })
})
