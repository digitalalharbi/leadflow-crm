import { expect, test } from '@playwright/test'
import { ALPHA, FOLLOW_UPS, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

test.describe('Follow-ups', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
  })

  test('the three buckets hold what the fixture seeded', async ({ page }) => {
    await page.goto('/follow-ups')

    await expect(page.getByRole('button', { name: `متأخرة ${FOLLOW_UPS.overdue}` })).toBeVisible()
    await expect(page.getByRole('button', { name: `اليوم ${FOLLOW_UPS.today}` })).toBeVisible()
    await expect(page.getByRole('button', { name: `القادمة ${FOLLOW_UPS.upcoming}` })).toBeVisible()
  })

  test('each bucket lists only its own items and is linkable', async ({ page }) => {
    for (const [bucket, expected] of [
      ['overdue', FOLLOW_UPS.overdue],
      ['today', FOLLOW_UPS.today],
      ['upcoming', FOLLOW_UPS.upcoming],
    ] as const) {
      await page.goto(`/follow-ups?bucket=${bucket}`)
      await expect(page.locator('ul > li')).toHaveCount(expected)
    }
  })

  test('an overdue item is visibly marked as late', async ({ page }) => {
    await page.goto('/follow-ups?bucket=overdue')

    const first = page.locator('ul > li').first()
    await expect(first).toBeVisible()
    // Says how late, rather than repeating the timestamp.
    await expect(first.getByText(/منذ/)).toBeVisible()
  })

  test('a follow-up can be created from a lead and lands in the right bucket', async ({ page }) => {
    await openLeadByName(page, LEADS.search)

    await page.getByTestId('action-follow-up').click()

    const dialog = page.getByRole('dialog')
    const tomorrow = new Date(Date.now() + 86_400_000).toISOString().slice(0, 16)

    await dialog.getByLabel('موعد المتابعة').fill(tomorrow)
    await dialog.getByLabel('ملاحظة').fill('مكالمة تأكيد الموعد')
    await dialog.getByRole('button', { name: 'حفظ' }).click()

    await expect(page.getByText('تم تحديد المتابعة.')).toBeVisible()

    await page.goto('/follow-ups?bucket=upcoming')
    await expect(page.getByRole('button', { name: `القادمة ${FOLLOW_UPS.upcoming + 1}` })).toBeVisible()
    await expect(page.getByText('مكالمة تأكيد الموعد')).toBeVisible()
  })

  test('completing a follow-up removes it from the queue', async ({ page }) => {
    await page.goto('/follow-ups?bucket=overdue')

    await page.locator('ul > li').first().getByRole('button', { name: 'إتمام' }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('نتيجة المتابعة').fill('تم الاتصال والعميل سيزور المعرض')
    await dialog.getByRole('button', { name: 'تأكيد' }).click()

    await expect(page.getByText('تم إتمام المتابعة.')).toBeVisible()
    await expect(page.getByRole('button', { name: `متأخرة ${FOLLOW_UPS.overdue - 1}` })).toBeVisible()

    // And it shows up under completed rather than vanishing.
    await page.goto('/follow-ups?bucket=completed')
    await expect(page.locator('ul > li')).toHaveCount(1)
  })

  test('an empty bucket says so instead of showing a blank panel', async ({ page }) => {
    await page.goto('/follow-ups?bucket=completed')
    await expect(page.getByText('لا توجد متابعات مكتملة.')).toBeVisible()
  })

  test('a rep sees only their own follow-ups', async ({ page }) => {
    // Every seeded follow-up belongs to the admin.
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/follow-ups?bucket=overdue')

    await expect(page.getByText('لا توجد متابعات متأخرة. عمل ممتاز.')).toBeVisible()
  })
})
