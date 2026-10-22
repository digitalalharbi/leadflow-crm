import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * What is quietly wrong with the data the workspace paid to collect.
 *
 * The assertion that matters is the drill-down. The backend honoured the
 * filter and the frontend silently dropped it, so the page showed every lead
 * under a heading that promised ten — the count and its list disagreeing in
 * the one place a manager went looking for the difference. An API test could
 * not see it, because the API was right.
 */
test.describe('جودة البيانات — data quality', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/data-quality')
  })

  test('it reports the six checks', async ({ page }) => {
    const checks = page.getByTestId('quality-checks')

    for (const key of [
      'unreachable_phones', 'duplicates', 'missing_attribution',
      'unassigned', 'stale', 'missing_campaign',
    ]) {
      await expect(checks.getByTestId(`check-${key}`)).toBeVisible()
    }
  })

  test('a count opens exactly the leads it counted', async ({ page }) => {
    const card = page.getByTestId('check-missing_campaign')
    // The count element, not the card: the card also carries the share.
    const shown = Number((await page.getByTestId('check-missing_campaign-count').textContent()) ?? 0)

    test.skip(shown === 0, 'nothing to drill into in this fixture set')

    await card.click()
    await expect(page).toHaveURL(/data_issue=missing_campaign/)

    // The filtered list, not the whole list. Counting rows is what catches the
    // frontend dropping a filter the API understood perfectly well.
    const rows = page.locator('table tbody tr')
    await expect(rows).toHaveCount(shown)
  })

  test('a clean check is not a link to an empty list', async ({ page }) => {
    const card = page.getByTestId('check-duplicates')
    const shown = Number((await page.getByTestId('check-duplicates-count').textContent()) ?? 0)

    test.skip(shown !== 0, 'this fixture set has duplicates')

    // A dead end dressed up as an action is worse than no action.
    await expect(card.locator('xpath=ancestor-or-self::a')).toHaveCount(0)
  })

  test('integration health is shown, broken or not', async ({ page }) => {
    await expect(page.getByTestId('integration-health')).toBeVisible()
  })

  test('a sales rep is not offered the screen', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)

    await expect(page.locator('a[href="/data-quality"]')).toHaveCount(0)
  })

  test('the issue count reads as Arabic', async ({ page }) => {
    const subtitle = page.getByTestId('page-subtitle')
    const text = (await subtitle.textContent()) ?? ''
    const count = Number(text.match(/\d+/)?.[0] ?? 0)

    if (count >= 11 && count <= 99) {
      expect(text).toContain('مشكلة')
    }
    expect(text).not.toContain('dataQuality.')
  })

  /**
   * A count on its own is not a finding. Ten leads with no campaign is a
   * quarter of a small workspace and a rounding error in a large one, and this
   * screen showed the same "10" either way.
   */
  test('each issue is stated as a share of the workspace, not only a count', async ({ page }) => {
    const card = page.getByTestId('check-missing_campaign')

    await expect(card).toBeVisible()
    // "N عميل من TOTAL — P%" — the denominator is the point.
    await expect(card).toContainText(/من \d+ — \d+(\.\d)?%/)
  })

  /** A clean check has nothing to draw. A zero-width bar is not information. */
  test('a clean check shows no bar at all', async ({ page }) => {
    const clean = page.getByTestId('check-unreachable_phones')

    await expect(clean).toBeVisible()
    await expect(clean.locator('[data-chart-mark]')).toHaveCount(0)
  })
})
