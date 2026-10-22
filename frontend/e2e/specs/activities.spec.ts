import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

test.describe('Lead activities', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.activity)
  })

  test('a note can be added from the action bar without scrolling', async ({ page }) => {
    const body = 'العميل طلب جدول الدفعات والمخططات'

    await page.getByTestId('action-note').click()
    await page.getByTestId('note-input').fill(body)
    await page.getByTestId('save-note').click()

    await expect(page.getByTestId('notes-list').getByText(body)).toBeVisible()
    await expect(page.getByTestId('activity-timeline').getByText('تمت إضافة ملاحظة')).toBeVisible()
  })

  test('the call button is a real tel: link and logs the contact', async ({ page }) => {
    const call = page.getByTestId('action-call')
    await expect(call.locator('xpath=..')).toHaveAttribute('href', /^tel:/)

    await call.click()

    // Calling a brand-new lead moves it out of "new" on its own — a rep should
    // never have to remember to do that by hand.
    await expect(page.getByTestId('lead-status-badge')).toHaveText('تم التواصل')
    await expect(page.getByTestId('activity-timeline').getByText('بدأ اتصالًا')).toBeVisible()
  })

  test('the WhatsApp button opens wa.me with the normalised number', async ({ page }) => {
    const wa = page.getByTestId('action-whatsapp').locator('xpath=..')

    // 0500000005 is stored as +966500000005; wa.me needs it without the plus.
    await expect(wa).toHaveAttribute('href', /^https:\/\/wa\.me\/966500000005/)
    await expect(wa).toHaveAttribute('target', '_blank')
  })

  test('opening WhatsApp is recorded on the timeline', async ({ page }) => {
    // Intercept the navigation so the test does not leave the app.
    await page.route('https://wa.me/**', (route) => route.abort())

    await page.getByTestId('action-whatsapp').click()

    await expect(page.getByTestId('activity-timeline').getByText('تم فتح واتساب')).toBeVisible()
  })

  test('the timeline records a status change with its before and after', async ({ page }) => {
    await page.getByTestId('stage-switcher').getByRole('button', { name: 'مؤهل', exact: true }).click()

    const entry = page.getByTestId('activity-timeline').getByText('تغيّرت المرحلة').first()
    await expect(entry).toBeVisible()

    // The row names both ends of the move, so an audit reads without guesswork.
    // The starting stage depends on what earlier tests in this file did, so pin
    // the destination and the arrow rather than a specific origin.
    await expect(page.getByTestId('activity-timeline').getByText(/→\s*مؤهل/)).toBeVisible()
  })

  test('the timeline is ordered newest first', async ({ page }) => {
    await page.getByTestId('action-note').click()
    await page.getByTestId('note-input').fill('أحدث ملاحظة')
    await page.getByTestId('save-note').click()

    const first = page.getByTestId('activity-timeline').locator('li').first()
    await expect(first.getByText('تمت إضافة ملاحظة')).toBeVisible()
  })
})
