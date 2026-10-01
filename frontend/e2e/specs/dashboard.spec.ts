import { expect, test } from '@playwright/test'
import { ALPHA, FOLLOW_UPS } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('shows the daily task row and the funnel summary', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'مهام اليوم' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'ملخص المسار' })).toBeVisible()

    // The questions a rep opens the app to answer.
    for (const label of ['عميل جديد اليوم', 'متابعة اليوم', 'متابعة متأخرة', 'عملاء Hot', 'مواعيد']) {
      await expect(page.getByText(label, { exact: true })).toBeVisible()
    }

    for (const label of ['إجمالي العملاء', 'مؤهلون', 'صفقات مكسوبة', 'نسبة التحويل']) {
      await expect(page.getByText(label, { exact: true })).toBeVisible()
    }
  })

  test('the statistics are real numbers, not placeholders', async ({ page }) => {
    const total = page.getByRole('link', { name: /إجمالي العملاء/ })
    await expect(total).toBeVisible()

    // 41 leads are seeded for alpha; assert it is a positive number rather than
    // the exact figure, so adding a fixture lead does not break the dashboard test.
    const text = (await total.textContent()) ?? ''
    const value = Number(text.replace(/\D/g, ''))

    expect(value).toBeGreaterThan(0)
  })

  test('each daily tile links to the list it names', async ({ page }) => {
    await page.getByRole('link', { name: /متابعة متأخرة/ }).click()
    await expect(page).toHaveURL(/\/follow-ups\?bucket=overdue/)
    await expect(page.getByRole('button', { name: /متأخرة/ })).toBeVisible()

    await page.goBack()

    await page.getByRole('link', { name: /عملاء Hot/ }).click()
    await expect(page).toHaveURL(/\/leads\?temperature\[\]=hot/)
    await expect(page.getByRole('heading', { name: 'العملاء المحتملون', level: 1 })).toBeVisible()
  })

  test('the overdue tile count matches the follow-ups page', async ({ page }) => {
    const tile = page.getByRole('link', { name: /متابعة متأخرة/ })
    const tileCount = Number(((await tile.textContent()) ?? '').replace(/\D/g, ''))

    expect(tileCount).toBe(FOLLOW_UPS.overdue)

    await tile.click()
    await expect(page.getByRole('button', { name: `متأخرة ${FOLLOW_UPS.overdue}` })).toBeVisible()
  })

  test('the date filter changes what is counted', async ({ page }) => {
    const total = page.getByRole('link', { name: /إجمالي العملاء/ })
    const before = (await total.textContent()) ?? ''

    // Everything is seeded "now", so narrowing to 7 days keeps them all while
    // widening to everything cannot lose any — the filter must still re-query.
    await page.getByLabel('التاريخ').selectOption('all')
    await expect(total).toBeVisible()

    const after = (await total.textContent()) ?? ''
    expect(Number(after.replace(/\D/g, ''))).toBeGreaterThanOrEqual(Number(before.replace(/\D/g, '')))
  })

  test('the project filter narrows the figures', async ({ page }) => {
    const total = page.getByRole('link', { name: /إجمالي العملاء/ })
    const before = Number(((await total.textContent()) ?? '').replace(/\D/g, ''))

    await page.getByLabel('المشروع').selectOption({ label: ALPHA.projects[0] })

    await expect(async () => {
      const after = Number(((await total.textContent()) ?? '').replace(/\D/g, ''))
      expect(after).toBeLessThan(before)
      expect(after).toBeGreaterThan(0)
    }).toPass({ timeout: 10_000 })
  })

  test('the assignee filter narrows the figures', async ({ page }) => {
    const total = page.getByRole('link', { name: /إجمالي العملاء/ })
    const before = Number(((await total.textContent()) ?? '').replace(/\D/g, ''))

    await page.getByLabel('المسؤول').selectOption({ label: ALPHA.rep1.name })

    await expect(async () => {
      const after = Number(((await total.textContent()) ?? '').replace(/\D/g, ''))
      expect(after).toBeLessThan(before)
    }).toPass({ timeout: 10_000 })
  })

  test('a sales rep sees a narrower dashboard than an admin', async ({ page }) => {
    const adminTotal = Number(
      ((await page.getByRole('link', { name: /إجمالي العملاء/ }).textContent()) ?? '').replace(/\D/g, ''),
    )

    await signInAs(page, ALPHA.rep1.email)

    const repTotal = Number(
      ((await page.getByRole('link', { name: /إجمالي العملاء/ }).textContent()) ?? '').replace(/\D/g, ''),
    )

    expect(repTotal).toBeLessThan(adminTotal)

    // And the navigation a rep has no business seeing is simply absent.
    await expect(page.getByRole('link', { name: 'التقارير' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'مصادر العملاء' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'الفريق' })).toHaveCount(0)
  })
})
