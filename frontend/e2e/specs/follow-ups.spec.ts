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
    /*
     * Tomorrow at midday, in the browser's own clock.
     *
     * The field is a `datetime-local`, and the form does
     * `new Date(value).toISOString()` — so whatever is typed is read as local
     * time and converted to UTC. Feeding it a UTC wall-clock (`toISOString()`)
     * therefore subtracted the offset again: on a machine at UTC+3, "tomorrow"
     * became 21 hours away, which lands back inside today's UTC date and so in
     * the "today" bucket rather than "upcoming".
     *
     * It only failed east of UTC and only during the first hours of the UTC
     * day, which is why CI — which runs at UTC, where the conversion is a
     * no-op — never saw it. It makes the suite unrunnable locally for a few
     * hours each morning.
     *
     * Midday local is tomorrow in UTC for any offset within ±12.
     */
    const noon = new Date(Date.now() + 86_400_000)
    noon.setHours(12, 0, 0, 0)
    const pad = (n: number) => String(n).padStart(2, '0')
    const tomorrow = `${noon.getFullYear()}-${pad(noon.getMonth() + 1)}-${pad(noon.getDate())}T12:00`

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
    // What happened is now a choice the reports can count; the sentence beside
    // it is the detail a rep wants to read back later.
    await dialog.getByTestId('followup-outcome').selectOption('appointment')
    await dialog.getByTestId('followup-note').fill('تم الاتصال والعميل سيزور المعرض')
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
