import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Whether leads are still arriving.
 *
 * The list of connected sources cannot answer it: a form that stopped posting
 * eleven days ago still shows a green badge and a valid secret. The only
 * evidence of arrival is arrivals, which is what this card is for.
 */
test.describe('وصول العملاء — ingestion health', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/integrations')

    /*
     * The arrivals chart lives behind the page's detail toggle, which the
     * density work closed by default: the grid of sources answers "is anything
     * wrong" in the first viewport, and this card is what you open after it
     * has. Opening it here is part of reaching the screen under test — the
     * assertions below are unchanged.
     */
    await page.getByTestId('toggle-integration-detail').click()
  })

  test('it shows arrivals over time with the duplicate share', async ({ page }) => {
    const card = page.getByTestId('ingestion-card')

    await expect(card).toBeVisible()
    // Both measures named, so identity is never carried by colour alone.
    await expect(card).toContainText('إجمالي الواصل')
    await expect(card).toContainText('نسبة المكرر')

    // The fixtures record a duplicate for every seventh lead, so the rate is
    // a real number rather than the dash an empty workspace shows.
    await expect(card).not.toContainText('لم يصل أي عميل')
    await expect(card.locator('text=/%/')).toBeVisible()
  })

  test('each source says when it was last heard from', async ({ page }) => {
    const sources = page.getByTestId('ingestion-sources')

    await expect(sources).toBeVisible()

    const text = await sources.innerText()

    expect(text).toContain('Meta')
    expect(text).toContain('آخر عميل منذ')

    /*
     * The timestamp used to come back as a naked 'Y-m-d H:i:s', which the
     * browser reads as local time — three hours of drift against a UTC
     * database in Asia/Riyadh. A lead that arrived a minute ago reported as
     * three hours stale, and a three-hour outage reported as none.
     */
    expect(text).not.toMatch(/آخر عميل منذ \d+ ساعات/)
  })

  /**
   * Six sources, five palette slots.
   *
   * The row count is the real invariant and it holds whatever the fixtures
   * contain: a generated sixth hue is exactly the colour nobody can tell from
   * the fifth, so the tail folds into one labelled bucket that still carries
   * its whole total.
   */
  test('more sources than colours fold into one labelled bucket', async ({ page }) => {
    const sources = page.getByTestId('ingestion-sources')
    await expect(sources).toBeVisible()

    const rows = sources.locator('li')
    await expect(rows.first()).toBeVisible()

    expect(await rows.count()).toBeLessThanOrEqual(6)
    await expect(sources).toContainText('مصادر أخرى')
  })

  /** The obligation that lets a chart encode counts as heights. */
  test('the daily figures are available as a table', async ({ page }) => {
    await page.getByTestId('ingestion-card-table-toggle').click()

    const card = page.getByTestId('ingestion-card')
    await expect(card.getByRole('columnheader', { name: 'عملاء جدد' })).toBeVisible()
    await expect(card.getByRole('columnheader', { name: 'مكرر' })).toBeVisible()
  })
})
