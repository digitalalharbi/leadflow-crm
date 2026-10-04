import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { gotoLeads, signInAs, waitForLeadsTable } from '../fixtures/pages'

/**
 * The filter bar.
 *
 * The chips are the point: a filtered list that does not say what it is
 * filtered by is how a rep concludes their leads have disappeared. Each chip
 * also has to remove only its own filter.
 */
test.describe('Lead filters', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await gotoLeads(page)
  })

  test('an applied filter is named on a chip', async ({ page }) => {
    await page.getByTestId('toggle-filters').click()
    await page.getByTestId('filter-status').selectOption('qualified')

    await expect(page.getByTestId('filter-chips')).toBeVisible()
    await expect(page.getByTestId('chip-status')).toBeVisible()
  })

  test('a chip removes its own filter and leaves the others', async ({ page }) => {
    await page.getByTestId('toggle-filters').click()
    await page.getByTestId('filter-status').selectOption('qualified')
    await page.getByTestId('filter-temperature').selectOption('hot')

    await expect(page.getByTestId('chip-status')).toBeVisible()
    await expect(page.getByTestId('chip-temperature')).toBeVisible()

    await page.getByTestId('chip-status').click()

    await expect(page.getByTestId('chip-status')).toHaveCount(0)
    await expect(page.getByTestId('chip-temperature')).toBeVisible()
  })

  test('clearing removes every filter at once', async ({ page }) => {
    await page.getByTestId('toggle-filters').click()
    await page.getByTestId('filter-status').selectOption('qualified')
    await page.getByTestId('filter-temperature').selectOption('hot')

    await page.getByTestId('clear-filters').click()

    await expect(page.getByTestId('filter-chips')).toHaveCount(0)
    await waitForLeadsTable(page)
  })

  test('the search box is a chip too, so a stale search is visible', async ({ page }) => {
    await page.getByTestId('lead-search').fill('لا-أحد-بهذا-الاسم')

    await expect(page.getByText('لا توجد نتائج مطابقة للتصفية الحالية.')).toBeVisible()

    await page.getByTestId('lead-search').fill('')
    await waitForLeadsTable(page)
  })
})
