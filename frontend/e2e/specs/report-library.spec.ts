import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA, BETA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { codeFromEmail, resetFixtures } from '../fixtures/seed'

/**
 * التقارير and تقارير العملاء: a report is made as a draft, published, shared with each client on a link of their own,
 * noted, exported and archived — and the client sees one short address, the official funnel, and nothing that was not
 * switched on.
 */
test.describe('Reports library and client access', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  const tokenOf = (url: string) => url.split('/').pop()!

  async function api<T>(path: string, init?: { method?: 'GET' | 'POST' | 'PATCH' | 'PUT'; data?: unknown; who?: string }): Promise<{ status: number; body: T }> {
    const session = await apiLogin(init?.who ?? ALPHA.admin.email)

    try {
      const response = await session.context.fetch(path, { method: init?.method ?? 'GET', data: init?.data })

      return { status: response.status(), body: (await response.json()) as T }
    } finally {
      await session.dispose()
    }
  }

  test('a draft is made, published, duplicated and archived from the library', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/report-library')

    await page.getByTestId('new-share').click()
    await page.getByTestId('share-name').fill('تقرير مسودة')
    await page.getByTestId('builder-client').fill('مؤسسة الأفق')
    await page.getByTestId('share-as-draft').check()
    await page.getByTestId('share-save').click()

    const card = page.locator('[data-testid^="share-"][data-state="draft"]').filter({ hasText: 'تقرير مسودة' })
    await expect(card).toBeVisible()
    await expect(card.getByTestId('share-state')).toHaveText('مسودة')
    await expect(card).toContainText('مؤسسة الأفق')

    // A draft does not open for anybody who has the address.
    const list = await api<{ data: { shares: { id: string; name: string; url: string }[] } }>('/api/v1/report-shares')
    const mine = list.body.data.shares.find((s) => s.name === 'تقرير مسودة')!
    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/r/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-gone')).toBeVisible()

    await card.getByTestId('share-publish').click()
    await expect(page.locator(`[data-testid="share-${mine.id}"]`)).toHaveAttribute('data-state', 'live')

    await open.goto(`/r/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير مسودة')
    await expect(open.getByTestId('shared-client')).toContainText('مؤسسة الأفق')
    await expect(open.getByTestId('shared-updated')).toBeVisible()

    // The address is one short token on the public domain: no id, no long path.
    expect(new URL(mine.url).pathname).toMatch(/^\/r\/[A-Za-z0-9]{22}$/)

    await page.locator(`[data-testid="share-${mine.id}"]`).getByTestId('share-duplicate').click()
    await expect(page.locator('[data-testid^="share-"][data-state="draft"]').filter({ hasText: 'تقرير مسودة (نسخة)' })).toBeVisible()

    await page.locator(`[data-testid="share-${mine.id}"]`).getByTestId('share-archive').click()
    await expect(page.locator(`[data-testid="share-${mine.id}"]`)).toHaveCount(0)
    await open.goto(`/r/${tokenOf(mine.url)}`)
    await expect(open.getByTestId('shared-gone')).toBeVisible()

    await page.getByTestId('library-filter-archived').click()
    await expect(page.locator(`[data-testid="share-${mine.id}"]`)).toHaveAttribute('data-state', 'archived')
    await anonymous.close()
  })

  test('each client gets a link of their own and the access center lists and closes them', async ({ page, browser }) => {
    const made = await api<{ data: { share: { id: string; url: string } } }>('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير العملاء', template: 'client', client_name: 'العميل الأول' } })
    const id = made.body.data.share.id
    const second = await api<{ data: { link: { id: string; url: string } } }>(`/api/v1/report-shares/${id}/links`, { method: 'POST', data: { client_name: 'العميل الثاني', password: 'pw-2222' } })
    expect(second.status).toBe(201)

    await signInAs(page, ALPHA.admin.email)
    await page.goto('/client-reports')
    const rows = page.getByTestId('client-list').locator('li')
    await expect(rows.filter({ hasText: 'العميل الثاني' })).toBeVisible()
    await expect(rows.filter({ hasText: 'العميل الثاني' })).toContainText('كلمة مرور')

    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/r/${tokenOf(second.body.data.link.url)}`)
    await expect(open.getByTestId('shared-password')).toBeVisible()
    await open.getByTestId('shared-password-input').fill('pw-2222')
    await open.getByTestId('shared-password-submit').click()
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير العملاء')

    // Closing the second client's door leaves the first one open.
    await rows.filter({ hasText: 'العميل الثاني' }).getByTestId('client-revoke').click()
    await expect(rows.filter({ hasText: 'العميل الثاني' })).toHaveAttribute('data-status', 'revoked')
    await open.goto(`/r/${tokenOf(second.body.data.link.url)}`)
    await expect(open.getByTestId('shared-gone')).toBeVisible()
    await open.goto(`/r/${tokenOf(made.body.data.share.url)}`)
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير العملاء')
    await anonymous.close()
  })

  test('a link for named people asks for an emailed code', async ({ browser }) => {
    const made = await api<{ data: { share: { id: string } } }>('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير برمز', template: 'client' } })
    const link = await api<{ data: { link: { url: string } } }>(`/api/v1/report-shares/${made.body.data.share.id}/links`, { method: 'POST', data: { allowed_emails: ['client@firm.test'], require_otp: true } })
    expect(link.status).toBe(201)

    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/r/${tokenOf(link.body.data.link.url)}`)
    await expect(open.getByTestId('shared-otp')).toBeVisible()
    await expect(open.getByTestId('shared-title')).toHaveCount(0)

    await open.getByTestId('shared-otp-email').fill('client@firm.test')
    await open.getByTestId('shared-otp-send').click()
    await expect(open.getByTestId('shared-otp-sent')).toBeVisible()

    await open.getByTestId('shared-otp-code').fill('000000')
    await open.getByTestId('shared-otp-verify').click()
    await expect(open.getByTestId('shared-otp-wrong')).toBeVisible()

    await open.getByTestId('shared-otp-code').fill(codeFromEmail('client@firm.test'))
    await open.getByTestId('shared-otp-verify').click()
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير برمز')
    await anonymous.close()
  })

  test('the official funnel is drawn on the dashboard and on the client report, with awareness honest about its data', async ({ page, browser }) => {
    const made = await api<{ data: { share: { url: string } } }>('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير المسار', template: 'full_funnel' } })

    await signInAs(page, ALPHA.admin.email)
    await page.goto('/')
    await page.getByTestId('toggle-analytics').click()
    const dashboard = page.getByTestId('funnel-card')
    await expect(dashboard).toBeVisible()
    for (const label of ['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة']) await expect(dashboard).toContainText(label)

    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/r/${tokenOf(made.body.data.share.url)}`)
    const funnel = open.getByTestId('shared-funnel')
    await expect(funnel).toBeVisible()
    for (const label of ['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة']) await expect(funnel).toContainText(label)
    // No ad account and no tracked traffic: awareness says so instead of drawing a zero.
    await expect(funnel.getByTestId('re-funnel-awareness')).toHaveAttribute('data-available', 'false')
    await expect(funnel.getByTestId('re-funnel-awareness')).toContainText('غير متاح')
    // The attention panel is for a person, and the client copy has no way into the product.
    await expect(open.locator('a[href^="/leads"], a[href^="/campaigns"]')).toHaveCount(0)
    await anonymous.close()
  })

  test('notes appear for the client, history keeps who changed what, and the team can export', async ({ page, browser }) => {
    const made = await api<{ data: { share: { id: string; url: string } } }>('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير ملاحظات', template: 'executive' } })
    const id = made.body.data.share.id

    await signInAs(page, ALPHA.admin.email)
    await page.goto('/report-library')
    const card = page.locator(`[data-testid="share-${id}"]`)
    await card.getByTestId('share-notes').click()
    await page.getByTestId('note-input-executive').fill('أداء الشهر أفضل من المتوقع.')
    await page.getByTestId('note-save-executive').click()
    await expect(page.getByTestId('note-executive')).toContainText('بواسطة')
    await page.keyboard.press('Escape')

    const anonymous = await browser.newContext()
    const open = await anonymous.newPage()
    await open.goto(`/r/${tokenOf(made.body.data.share.url)}`)
    await expect(open.getByTestId('shared-notes')).toContainText('أداء الشهر أفضل من المتوقع.')
    await anonymous.close()

    await card.getByTestId('share-history').click()
    await expect(page.getByTestId('activity-list')).toContainText('أُضيفت ملاحظة')
    await expect(page.getByTestId('revision-list')).toContainText('الملاحظات')
    await page.keyboard.press('Escape')

    const download = page.waitForEvent('download')
    await card.getByTestId('share-export-csv').click()
    expect((await download).suggestedFilename()).toMatch(/\.csv$/)
  })

  test('one workspace never sees another’s reports or links', async ({ page }) => {
    await api('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير ألفا الخاص', template: 'client', client_name: 'عميل ألفا' } })

    await signInAs(page, BETA.admin.email)
    await page.goto('/report-library')
    await expect(page.getByTestId('share-tab')).toBeVisible()
    await expect(page.getByTestId('share-tab')).not.toContainText('تقرير ألفا الخاص')
    await page.goto('/client-reports')
    await expect(page.getByText('عميل ألفا')).toHaveCount(0)
  })

  test('the management report fits a phone: every section, no sideways scroll', async ({ browser }) => {
    const made = await api<{ data: { share: { url: string } } }>('/api/v1/report-shares', { method: 'POST', data: { name: 'تقرير الجوال', template: 'management', options: { expose_team: true } } })
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
    const open = await context.newPage()
    await open.goto(`/r/${tokenOf(made.body.data.share.url)}`)
    await expect(open.getByTestId('shared-title')).toBeVisible()
    await expect(open.getByTestId('shared-funnel')).toBeVisible()
    await expect(open.getByTestId('shared-marketing')).toBeVisible()
    await expect(open.getByTestId('shared-sales')).toBeVisible()
    await expect(open.getByTestId('shared-attention')).toBeVisible()
    expect(await open.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)
    await context.close()
  })
})
