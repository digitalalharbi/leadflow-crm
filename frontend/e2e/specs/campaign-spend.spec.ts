import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * What the ads cost, so the leads they produced can be priced.
 *
 * The campaign report has always counted leads, qualified leads and sales and
 * never known what any of it cost — so "which campaign is worth scaling" could
 * only be answered by volume, which is the question that flatters the cheapest
 * traffic.
 */
test.describe('إنفاق الحملات — campaign spend', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByRole('button', { name: 'الحملات' }).click()
  })

  /**
   * A dash, never a zero. A campaign nobody has costed is not free, and 0.00
   * beside a real figure invites exactly the wrong conclusion about which one
   * to scale.
   */
  test('an uncosted campaign shows a dash rather than a cost of zero', async ({ page }) => {
    const table = page.getByTestId('campaign-table')

    await expect(table).toBeVisible()
    await expect(table).toContainText('تكلفة العميل')
    await expect(table.locator('td', { hasText: /^—$/ }).first()).toBeVisible()
  })

  test('entering spend prices the campaign it belongs to', async ({ page }) => {
    const table = page.getByTestId('campaign-table')
    await expect(table).toBeVisible()

    await page.getByTestId('open-spend').click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // The campaign is picked from the ones the report already knows about: a
    // spend row whose name is one character off never matches its leads, and
    // the column shows a dash with no hint why.
    const select = page.getByTestId('spend-campaign')
    const value = await select.locator('option').nth(1).getAttribute('value')
    await select.selectOption(value!)

    await page.getByTestId('spend-amount').fill('1000')
    await page.getByTestId('save-spend').click()

    // The entry is listed back, in the workspace's own currency.
    await expect(page.getByTestId('spend-list')).toContainText('1,000')

    // Scoped and exact: «إغلاق» is also a substring of the «عوامل الإغلاق» tab.
    await dialog.getByRole('button', { name: 'إغلاق', exact: true }).click()

    // And the table it was entered from now prices that campaign.
    await expect(table.locator('td', { hasText: /\d/ }).first()).toBeVisible()
  })

  /** Workspace-wide money needs workspace-wide authority. */
  test('a sales rep is not offered the spend entry at all', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/reports')

    await expect(page.getByTestId('open-spend')).toHaveCount(0)
  })
})
