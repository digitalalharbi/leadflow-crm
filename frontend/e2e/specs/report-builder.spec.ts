import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The report builder: sections in the order the owner chose, one kept for the team, compared with
 * the period before — and the owner can see how often the link was opened.
 */
test.describe('Report builder', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  const tokenOf = (url: string) => url.split('/').pop()!

  test('an owner orders the sections, keeps one for the team, compares, and reads the views', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()

    await page.getByTestId('share-name').fill('تقرير المستثمر')
    await page.getByTestId('share-template-investor').click()

    // Move the trend above the funnel, keep the recommendations for the team, and compare.
    await page.getByTestId('share-up-trend').click()
    await page.getByTestId('share-up-trend').click()
    await page.getByTestId('share-visibility-recommendations').selectOption('team')
    await page.getByTestId('share-compare').check()
    await page.getByTestId('share-save').click()
    await expect(page.getByTestId('share-list')).toContainText('تقرير المستثمر')

    const admin = await apiLogin(ALPHA.admin.email)
    const shares = (await (await admin.context.get('/api/v1/report-shares')).json()).data.shares as { id: string; name: string; url: string; layout: { key: string; on: boolean }[] }[]
    const mine = shares.find((s) => s.name === 'تقرير المستثمر')!
    const order = mine.layout.filter((l) => l.on).map((l) => l.key)
    expect(order.indexOf('trend')).toBeLessThan(order.indexOf('funnel'))

    // The link, as a stranger sees it: the owner's order, the comparison, no team-only section.
    const stranger = await browser.newContext()
    const open = await stranger.newPage()
    await open.goto(`/r/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير المستثمر')
    await expect(open.getByTestId('shared-previous-period')).toBeVisible()
    const shown = await open.locator('[data-section]').evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-section')))
    expect(shown.indexOf('trend')).toBeLessThan(shown.indexOf('funnel'))
    expect(shown).not.toContain('recommendations')
    await expect(open.getByTestId('shared-recommendations')).toHaveCount(0)
    await stranger.close()

    // The team's preview has it.
    await page.reload()
    await page.getByTestId('tab-share').click()
    await page.getByTestId(`share-${mine.id}`).getByTestId('share-preview').click()
    await expect(page.getByTestId('share-preview-drawer')).toBeVisible()
    await expect(page.getByTestId('share-preview-drawer').locator('[data-section="recommendations"]')).toHaveCount(1)
    await page.keyboard.press('Escape')

    // Views: the stranger's one visit.
    await page.getByTestId(`share-${mine.id}`).getByTestId('share-analytics').click()
    await expect(page.getByTestId('analytics-total')).toHaveText('1')
    await expect(page.getByTestId('analytics-unique')).toHaveText('1')
    await admin.dispose()
  })

  test('the arrows keep the order exact, and the first section cannot go up', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()

    const rows = page.locator('[data-testid^="share-row-"]')
    const first = await rows.first().getAttribute('data-testid')
    await page.getByTestId('share-up-kpi_volume').click({ trial: true }).catch(() => undefined)
    await expect(page.getByTestId('share-up-kpi_volume')).toBeDisabled()
    expect(first).toBe('share-row-kpi_volume')
    await page.getByTestId('share-down-kpi_volume').click()
    await expect(rows.first()).not.toHaveAttribute('data-testid', 'share-row-kpi_volume')
  })
})
