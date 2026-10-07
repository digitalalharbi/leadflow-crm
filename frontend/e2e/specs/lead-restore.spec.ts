import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * A deleted lead whose number comes back.
 *
 * The record is restored — deliberately, because for inbound traffic the person
 * really did come back. What was wrong is that it happened in silence: the lead
 * reappeared in the list with the name, notes and owner somebody had removed,
 * and the only thing they were told was that the number "already exists",
 * about a record they could not see.
 */
test.describe('استعادة عميل محذوف — restoring a deleted lead', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  const add = async (page: import('@playwright/test').Page, name: string, phone: string) => {
    await page.goto('/leads')
    await page.getByRole('button', { name: 'إضافة عميل' }).click()
    await page.getByLabel(/الاسم/).first().fill(name)
    await page.getByPlaceholder('05xxxxxxxx').fill(phone)
    await page.getByRole('button', { name: 'إنشاء' }).click()
  }

  test('the screen says the record was restored, not that it already exists', async ({ page }) => {
    const phone = `0500${Date.now().toString().slice(-6)}`

    await add(page, 'عميل سيُحذف', phone)
    await expect(page.getByText('عميل سيُحذف').first()).toBeVisible()

    await page.getByText('عميل سيُحذف').first().click()
    await page.getByRole('button', { name: 'حذف' }).first().click()
    await page.getByRole('dialog').getByRole('button', { name: 'حذف' }).click()
    await expect(page).toHaveURL(/\/leads(\?|$)/)

    await add(page, 'اسم جديد', phone)

    // The distinction that matters: restored, not merely duplicate.
    await expect(page.getByText(/محذوف/)).toBeVisible()
  })

  /** The lead's own timeline is what answers "why is this here again?". */
  test('the restore is written on the timeline', async ({ page }) => {
    const phone = `0500${Date.now().toString().slice(-6)}`

    await add(page, 'عميل العودة', phone)
    await page.getByText('عميل العودة').first().click()
    await page.getByRole('button', { name: 'حذف' }).first().click()
    await page.getByRole('dialog').getByRole('button', { name: 'حذف' }).click()

    await add(page, 'عميل العودة', phone)

    await page.getByText('عميل العودة').first().click()
    await expect(page.getByText('عاد العميل بعد حذفه — استُعيد السجل')).toBeVisible()
  })
})
