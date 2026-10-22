import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The four milestones of one lead: arrived, first contact, qualified, closed.
 *
 * Deliberately the same four the velocity chart measures across the workspace.
 * A manager reading "the median lead waits twelve minutes for a first call" and
 * a rep opening a lead that waited three days have to be looking at the same
 * spine, or the two screens are describing different companies.
 */
test.describe('رحلة العميل — the lead journey', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('a closed lead shows every step and the gap between them', async ({ page }) => {
    await openLeadByName(page, LEADS.won)

    const journey = page.getByTestId('lead-journey')
    await expect(journey).toBeVisible()

    const text = await journey.innerText()

    for (const label of ['وصل العميل', 'أول تواصل', 'التأهيل', 'تم البيع']) {
      expect(text).toContain(label)
    }

    // The gaps, in words, next to the milestone each one leads to.
    expect(text).toContain('بعد 12 دقيقة')
    expect(text).toContain('بعد 6 ساعات')
    expect(text).toContain('بعد 9 أيام')

    // A finished journey is not waiting for anything.
    expect(text).not.toContain('ينتظر هنا')
  })

  /**
   * An open lead's fourth milestone is "الإغلاق", not "فُقد". Deriving it from
   * `won_at ?? lost_at` would print "lost" under every live lead in the
   * workspace — greyed out, but spelled out.
   */
  test('an open lead is not described as lost', async ({ page }) => {
    await openLeadByName(page, LEADS.open)

    const journey = page.getByTestId('lead-journey')
    await expect(journey).toBeVisible()

    const text = await journey.innerText()

    expect(text).toContain('الإغلاق')
    expect(text).not.toContain('فُقد')
    expect(text).not.toContain('تم البيع')

    // The one figure a rep can act on: how long it has sat where it is.
    expect(text).toContain('ينتظر هنا منذ')
  })

  test('a lost lead says so, and stops its clock', async ({ page }) => {
    await openLeadByName(page, LEADS.closedLost)

    const journey = page.getByTestId('lead-journey')
    const text = await journey.innerText()

    expect(text).toContain('فُقد')
    expect(text).not.toContain('ينتظر هنا')
    // It was never contacted, so those two steps are honestly empty.
    expect(text).toContain('لم يحدث بعد')
  })

  /** The obligation that lets a chart encode a duration as a length. */
  test('the figures are available as a table', async ({ page }) => {
    await openLeadByName(page, LEADS.won)

    await page.getByTestId('lead-journey-card-table-toggle').click()

    const card = page.getByTestId('lead-journey-card')
    await expect(card.getByRole('columnheader', { name: 'المرحلة' })).toBeVisible()
    await expect(card.getByRole('cell', { name: 'تم البيع' })).toBeVisible()
    await expect(card.getByRole('cell', { name: '9 أيام' })).toBeVisible()
  })
})
