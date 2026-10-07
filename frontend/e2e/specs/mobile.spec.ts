import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The phone layout, which is where a sales rep actually works.
 *
 * Only the screens a rep uses between visits are covered here; re-running the
 * whole suite at a second viewport would cost minutes to catch the same bugs.
 */
test.describe('Mobile layout', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('the dashboard fits the screen with no sideways scrolling', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'مهام اليوم' })).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('the sidebar is a drawer that opens and closes', async ({ page }) => {
    const nav = page.getByRole('link', { name: 'العملاء المحتملون' })
    await expect(nav).not.toBeInViewport()

    await page.getByRole('banner').getByRole('button').first().click()
    await expect(nav).toBeInViewport()

    await nav.click()
    await expect(page).toHaveURL(/\/leads/)
    // Choosing a destination closes the drawer rather than leaving it over the page.
    await expect(page.getByRole('heading', { name: 'العملاء المحتملون', level: 1 })).toBeVisible()
  })

  test('the leads list shows stage and interest without horizontal scrolling', async ({ page }) => {
    await page.goto('/leads')
    await expect(page.locator('table tbody tr').first()).toBeVisible()

    // Status and temperature move under the name on a phone, so the two things
    // a rep scans for are visible without swiping the table.
    const firstRow = page.locator('table tbody tr').first()
    await expect(firstRow.getByText(/جديد|تم التواصل|مؤهل|موعد|مكسوب|مفقود/).first()).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('a lead can be worked from the phone', async ({ page }) => {
    await page.goto('/leads')
    await page.getByTestId('lead-search').fill(LEADS.activity)
    await page.getByRole('link', { name: LEADS.activity }).click()

    // Every quick action is reachable without scrolling into the page body.
    await expect(page.getByTestId('lead-quick-actions')).toBeInViewport()
    await expect(page.getByTestId('action-call')).toBeVisible()
    await expect(page.getByTestId('action-whatsapp')).toBeVisible()
    await expect(page.getByTestId('action-note')).toBeVisible()
  })

  /**
   * The screens an admin opens, swept for sideways scroll.
   *
   * The rep's screens above are checked one by one because each has something
   * specific to assert. These have one thing in common and it is the one that
   * breaks: a grid item's automatic minimum size is its min-content width, so a
   * single card holding something that does not wrap — a webhook URL — sized
   * the column to 689px inside a 343px screen and pushed the whole integrations
   * page off to the side. The `truncate` on it could not help, because the
   * column had already been sized to fit it.
   *
   * Cheap: six navigations, not a second run of the suite.
   */
  for (const path of ['/integrations', '/automation', '/reports', '/data-quality', '/settings', '/users']) {
    test(`${path} fits the screen with no sideways scrolling`, async ({ page }) => {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow, `${path} scrolls sideways on a phone`).toBeLessThanOrEqual(1)
    })
  }

  test('the follow-ups page is usable on a phone', async ({ page }) => {
    await page.goto('/follow-ups?bucket=overdue')

    await expect(page.locator('ul > li').first()).toBeVisible()
    await expect(page.locator('ul > li').first().getByRole('button', { name: 'إتمام' })).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
})
