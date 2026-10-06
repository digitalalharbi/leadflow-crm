import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Does any of it actually predict a sale?
 *
 * Every other report measures effort — how fast the team called, what the
 * scoring engine thought, how many leads a platform sent. None of them says
 * whether the effort paid, and a workspace cannot act on a speed figure until
 * it knows speed is worth paying for.
 */
test.describe('عوامل الإغلاق — conversion drivers', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByRole('button', { name: 'عوامل الإغلاق' }).click()
  })

  test('speed is measured against the outcome, not against itself', async ({ page }) => {
    const card = page.getByTestId('drivers-speed')

    await expect(card).toBeVisible()
    await expect(card).toContainText('خلال 5 دقائق')
    await expect(card).toContainText('بعد أكثر من يوم')

    // The bucket that is usually the largest and always the worst performing.
    // A chart of only the leads somebody got round to calling is a chart of
    // the team's good days.
    await expect(card).toContainText('لم يتم التواصل')
  })

  test('the score bands report their own win rate', async ({ page }) => {
    const card = page.getByTestId('drivers-score')

    await expect(card).toBeVisible()
    await expect(card).toContainText('تقييم 80 فأكثر')
    await expect(card).toContainText('تقييم أقل من 20')
    await expect(card).toContainText('%')
  })

  /**
   * A win rate with no window is a number nobody can argue with, which is the
   * opposite of useful. Leads too young to have converted are excluded, and
   * the card says so rather than leaving the reader to wonder.
   */
  test('both cards state the cohort they were cut from', async ({ page }) => {
    await expect(page.getByTestId('drivers-speed')).toContainText('يشمل العملاء الذين وصلوا قبل')
    await expect(page.getByTestId('drivers-score')).toContainText('يشمل العملاء الذين وصلوا قبل')
  })

  /** An empty band shows a dash. Zero would read as "none of these convert". */
  test('an empty band is a dash, never a zero', async ({ page }) => {
    const card = page.getByTestId('drivers-speed')

    /*
     * `toContainText` rather than reading `innerText` once. The card renders
     * its header before the query resolves, so a single read catches the title
     * and nothing else — which is how this test failed the first time it ran.
     */
    await expect(card).toContainText('لا عملاء في هذه الشريحة')
    await expect(card).toContainText('—')
  })

  /** The obligation that lets a chart encode a rate as a length. */
  test('the figures are available as a table', async ({ page }) => {
    await page.getByTestId('drivers-speed-table-toggle').click()

    const card = page.getByTestId('drivers-speed')
    await expect(card.getByRole('columnheader', { name: 'أثر سرعة التواصل' })).toBeVisible()
    await expect(card.getByRole('cell', { name: 'لم يتم التواصل' })).toBeVisible()
  })
})
