import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** A report behind a link: live, lockable, revocable, and showing only what its sections allow. */
test.describe('Live shared reports', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  async function shares() {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const response = await admin.context.get('/api/v1/report-shares')
      return (await response.json()).data.shares as { id: string; name: string; url: string; status: string; view_count: number }[]
    } finally {
      await admin.dispose()
    }
  }

  const tokenOf = (url: string) => url.split('/').pop()!

  test('a manager builds a client report in three steps, and the link opens without signing in', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()

    await page.getByTestId('share-name').fill('تقرير العميل')
    await page.getByTestId('share-template-client').click()

    // The client template shows no money, and says nothing about it.
    await expect(page.getByTestId('share-section-kpi_cost')).not.toBeChecked()
    await expect(page.getByTestId('share-section-kpi_revenue')).not.toBeChecked()
    await expect(page.getByTestId('share-money-warning')).toHaveCount(0)

    // Turning money on warns before anything is shared.
    await page.getByTestId('share-section-kpi_revenue').check()
    await expect(page.getByTestId('share-money-warning')).toBeVisible()
    await page.getByTestId('share-section-kpi_revenue').uncheck()

    await page.getByTestId('share-save').click()
    await expect(page.getByTestId('share-list')).toContainText('تقرير العميل')

    const mine = (await shares()).find((s) => s.name === 'تقرير العميل')!
    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/reports/share/${tokenOf(mine.url)}`)

    await expect(open.getByTestId('shared-title')).toHaveText('تقرير العميل')
    await expect(open.getByTestId('shared-kpis')).toBeVisible()
    await expect(open.getByTestId('sk-leads')).toBeVisible()
    await expect(open.getByTestId('shared-funnel')).toBeVisible()
    await expect(open.getByTestId('shared-trend')).toBeVisible()

    // Hidden means not there: no cost, no revenue, no team, no campaigns.
    for (const id of ['sk-spend', 'sk-cpl', 'sk-cac', 'sk-roas', 'sk-revenue', 'shared-team', 'shared-campaigns']) {
      await expect(open.getByTestId(id)).toHaveCount(0)
    }
    await expect(open.getByTestId('shared-generated')).toBeVisible()

    // The client can save the report as a PDF: the button opens the browser's print dialog, and print hides it.
    await open.evaluate(() => { (window as unknown as { __printed: number }).__printed = 0; window.print = () => { (window as unknown as { __printed: number }).__printed++ } })
    await open.getByTestId('shared-save-pdf').click()
    expect(await open.evaluate(() => (window as unknown as { __printed: number }).__printed)).toBe(1)
    await open.emulateMedia({ media: 'print' })
    await expect(open.getByTestId('shared-save-pdf')).toBeHidden()
    await anonymous.close()
  })

  test('a password is asked for, and the report opens only with it', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()
    await page.getByTestId('share-name').fill('تقرير محمي')
    await page.getByTestId('share-password').fill('pass-1234')
    await page.getByTestId('share-save').click()
    await expect(page.getByTestId('share-list')).toContainText('بكلمة مرور')

    const mine = (await shares()).find((s) => s.name === 'تقرير محمي')!
    const context = await browser.newContext()
    const open = await context.newPage()
    await open.goto(`/reports/share/${tokenOf(mine.url)}`)

    await expect(open.getByTestId('shared-password')).toBeVisible()
    await expect(open.getByTestId('sk-leads')).toHaveCount(0)

    await open.getByTestId('shared-password-input').fill('wrong-one')
    await open.getByTestId('shared-password-submit').click()
    await expect(open.getByTestId('shared-password-wrong')).toBeVisible()

    await open.getByTestId('shared-password-input').fill('pass-1234')
    await open.getByTestId('shared-password-submit').click()
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير محمي')
    await context.close()
  })

  test('revoking closes the link, and a new link opens the report again', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()
    await page.getByTestId('share-name').fill('تقرير يُوقف')
    await page.getByTestId('share-save').click()

    const before = (await shares()).find((s) => s.name === 'تقرير يُوقف')!
    const card = page.getByTestId(`share-${before.id}`)

    await card.getByTestId('share-revoke').click()
    await expect(card).toHaveAttribute('data-status', 'revoked')

    const context = await browser.newContext()
    const open = await context.newPage()
    await open.goto(`/reports/share/${tokenOf(before.url)}`)
    await expect(open.getByTestId('shared-gone')).toBeVisible()

    await card.getByTestId('share-regenerate').click()
    await expect(card).toHaveAttribute('data-status', 'active')
    const after = (await shares()).find((s) => s.id === before.id)!
    expect(after.url).not.toBe(before.url)

    // The old link stays shut; the new one opens.
    await open.goto(`/reports/share/${tokenOf(before.url)}`)
    await expect(open.getByTestId('shared-gone')).toBeVisible()
    await open.goto(`/reports/share/${tokenOf(after.url)}`)
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير يُوقف')
    await context.close()
  })

  test('opens are counted and shown beside the link', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()
    await page.getByTestId('share-name').fill('تقرير يُعدّ')
    await page.getByTestId('share-save').click()

    const mine = (await shares()).find((s) => s.name === 'تقرير يُعدّ')!
    const context = await browser.newContext()
    const open = await context.newPage()
    await open.goto(`/reports/share/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-title')).toBeVisible()
    await context.close()

    await page.reload()
    await page.getByTestId('tab-share').click()
    const card = page.getByTestId(`share-${mine.id}`)
    await expect(card.getByTestId('share-views')).not.toContainText('لم يُفتح')
    await expect(card.getByTestId('share-last')).toContainText('آخر فتح')
  })

  test('the public page fits a phone and a laptop without sideways scrolling', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    const mine = (await shares())[0]
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } })
    const open = await context.newPage()
    await open.goto(`/reports/share/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-title')).toBeVisible()
    expect(await open.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)
    await open.setViewportSize({ width: 375, height: 812 })
    expect(await open.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)
    await context.close()
  })
})
