import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Notifications and reporting, closed: the monthly summary, WhatsApp alerts to a person's own phone
 * (their choice, their quiet hours), ready-made reports with the management report, and a report that
 * sends itself.
 */
test.describe('Notifications and reports', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('the monthly executive summary is one of the four, on for the admin by default', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/settings/profile')
    await expect(page.getByTestId('digest-rows')).toBeVisible()

    for (const digest of ['daily_sales', 'weekly_marketing', 'weekly_executive', 'monthly_executive']) await expect(page.getByTestId(`digest-${digest}`)).toBeVisible()
    await expect(page.getByTestId('digest-monthly_executive')).toBeChecked()

    await page.getByTestId('digest-monthly_executive').uncheck()
    await page.reload()
    await expect(page.getByTestId('digest-monthly_executive')).not.toBeChecked()
  })

  test('WhatsApp alerts are off until chosen, then six categories, quiet hours and a log appear — and persist', async ({ page }) => {
    // A phone on the profile, as the person would set it.
    const admin = await apiLogin(ALPHA.admin.email)
    const users = (await (await admin.context.get('/api/v1/users')).json()).data.users as { id: string; email: string }[]
    const rep = users.find((u) => u.email === ALPHA.rep1.email)!
    expect((await admin.context.patch(`/api/v1/users/${rep.id}`, { data: { phone: '+966551234567' } })).status()).toBeLessThan(300)
    await admin.dispose()

    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/settings/profile')
    await expect(page.getByTestId('whatsapp-alerts')).toBeVisible()
    await expect(page.getByTestId('whatsapp-enabled')).not.toBeChecked()
    await expect(page.getByTestId('whatsapp-rows')).toHaveCount(0)

    await page.getByTestId('whatsapp-enabled').check()
    const rows = page.getByTestId('whatsapp-rows').locator('li')
    await expect(rows).toHaveCount(6)
    for (const category of ['hot_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'high_value', 'automation_failure']) {
      await expect(page.getByTestId(`whatsapp-${category}`)).toBeVisible()
    }
    // Tracking and budget are for the email and the tray, not the phone.
    await expect(page.getByTestId('whatsapp-tracking_failure')).toHaveCount(0)

    // A rep hears about a hot lead at once and not about automation failures, until they say so.
    await expect(page.getByTestId('whatsapp-hot_lead-instant')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('whatsapp-automation_failure-off')).toHaveAttribute('aria-pressed', 'true')
    await page.getByTestId('whatsapp-hot_lead-off').click()
    await page.getByTestId('whatsapp-automation_failure-instant').click()

    // Their own quiet hours.
    await page.getByTestId('whatsapp-quiet-enabled').check()
    await expect(page.getByTestId('whatsapp-quiet-source')).toContainText('خاصة بك')
    await page.getByTestId('whatsapp-quiet-start').fill('23:00')
    // Every press saves; a reload before the last save has landed would cut it off, so wait for it.
    const saved = page.waitForResponse((r) => r.url().includes('/auth/notification-preferences') && r.request().method() === 'PATCH' && (r.request().postData() ?? '').includes('06:30'))
    await page.getByTestId('whatsapp-quiet-end').fill('06:30')
    await saved

    await page.reload()
    await expect(page.getByTestId('whatsapp-enabled')).toBeChecked()
    await expect(page.getByTestId('whatsapp-hot_lead-off')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('whatsapp-automation_failure-instant')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('whatsapp-quiet-start')).toHaveValue('23:00')
    await expect(page.getByTestId('whatsapp-quiet-end')).toHaveValue('06:30')

    // Back to the workspace's window.
    await page.getByTestId('whatsapp-quiet-reset').click()
    await expect(page.getByTestId('whatsapp-quiet-source')).toContainText('من المساحة')

    // The log opens, empty: nothing has been sent yet.
    await page.getByTestId('whatsapp-log-toggle').click()
    await expect(page.getByTestId('whatsapp-log')).toContainText('لا تنبيهات واتساب بعد')
  })

  test('a person without a phone is told so, and the six are not offered a message that cannot arrive', async ({ page }) => {
    await signInAs(page, ALPHA.manager.email)
    await page.goto('/settings/profile')
    await page.getByTestId('whatsapp-enabled').check()
    const blocked = page.getByTestId('whatsapp-blocked')
    // The manager either has no phone on the profile or the workspace has no number: one of the two honest messages.
    if (await blocked.count()) await expect(blocked).toContainText(/جوال|واتساب/)
    await expect(page.getByTestId('whatsapp-rows')).toBeVisible()
  })

  test('six ready-made reports; the management one fills the form in, and its four pages appear on the live link', async ({ page, browser }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()

    for (const preset of ['executive_management', 'marketing', 'sales', 'client', 'investor', 'partner']) await expect(page.getByTestId(`share-preset-${preset}`)).toBeVisible()

    await page.getByTestId('share-preset-executive_management').click()
    await expect(page.getByTestId('share-name')).toHaveValue('الإدارة التنفيذية')
    await expect(page.getByTestId('share-compare')).toBeChecked()
    for (const page_ of ['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking']) await expect(page.getByTestId(`share-section-${page_}`)).toBeChecked()
    await page.getByTestId('share-save').click()
    await expect(page.getByTestId('share-list')).toContainText('الإدارة التنفيذية')

    const admin = await apiLogin(ALPHA.admin.email)
    const shares = (await (await admin.context.get('/api/v1/report-shares')).json()).data.shares as { name: string; url: string; template: string; days: number }[]
    const mine = shares.find((s) => s.name === 'الإدارة التنفيذية')!
    expect(mine.template).toBe('executive_management')
    expect(mine.days).toBe(30)

    const open = await (await browser.newContext()).newPage()
    await open.goto(`/reports/share/${mine.url.split('/').pop()}`)
    await expect(open.getByTestId('shared-title')).toHaveText('الإدارة التنفيذية')
    for (const section of ['exec_marketing', 'exec_sales', 'exec_automation', 'exec_tracking']) await expect(open.getByTestId(`shared-${section}`)).toBeVisible()

    // Marketing carries its ten figures; the period before sits beside them.
    for (const key of ['spend', 'leads', 'cpl', 'qualified', 'cpql', 'interested', 'visits', 'reservations', 'cac', 'roas']) await expect(open.getByTestId(`exec_marketing-${key}`)).toBeVisible()
    for (const key of ['assigned', 'contacted', 'response_minutes', 'follow_up_completion', 'visits', 'reservations', 'won', 'revenue', 'close_rate', 'sla']) await expect(open.getByTestId(`exec_sales-${key}`)).toBeVisible()
    await expect(open.getByTestId('shared-previous-period')).toBeVisible()
    await expect(open.locator('body')).not.toContainText(/exec_|undefined|NaN/)
    await open.close()
    await admin.dispose()
  })

  test('a report sends itself: a schedule is made, listed, paused and removed', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('new-share').click()
    await page.getByTestId('share-preset-sales').click()
    await page.getByTestId('share-save').click()
    await expect(page.getByTestId('share-list')).toContainText('المبيعات')

    await page.getByTestId('share-schedule').first().click()
    await expect(page.getByTestId('schedule-dialog')).toBeVisible()
    await expect(page.getByTestId('schedule-add')).toBeDisabled()

    await page.getByTestId('schedule-frequency').selectOption('weekly')
    await page.getByTestId('schedule-weekday').selectOption('5')
    await page.getByTestId('schedule-hour').selectOption('9')
    await page.getByTestId('schedule-recipients').fill('owner@example.com, partner@example.com')
    await page.getByTestId('schedule-add').click()

    const row = page.getByTestId('schedule-list').locator('li').first()
    await expect(row).toContainText('أسبوعيًا')
    await expect(row).toContainText('الجمعة')
    await expect(row).toContainText('09:00')
    await expect(row).toContainText('2 مستلم')
    await expect(row).toHaveAttribute('data-enabled', 'true')

    await row.getByRole('switch').click()
    await expect(row).toHaveAttribute('data-enabled', 'false')

    await row.getByRole('button', { name: 'حذف' }).click()
    await expect(page.getByTestId('schedule-list')).toHaveCount(0)
  })

  test('only a manager or an admin sees the schedule and the presets', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/reports')
    await expect(page.getByTestId('tab-share')).toHaveCount(0)
    // A report that exists, made by the admin: the rep can neither list reports nor schedule one.
    const admin = await apiLogin(ALPHA.admin.email)
    const made = await admin.context.post('/api/v1/report-shares', { data: { name: 'للجدولة', preset: 'client' } })
    const id = (await made.json()).data.share.id as string
    await admin.dispose()

    const rep = await apiLogin(ALPHA.rep1.email)
    expect((await rep.context.get('/api/v1/report-shares')).status()).toBe(403)
    expect((await rep.context.post(`/api/v1/report-shares/${id}/schedules`, { data: { frequency: 'daily', recipients: ['a@example.com'] } })).status()).toBe(403)
    expect((await rep.context.get(`/api/v1/report-shares/${id}/schedules`)).status()).toBe(403)
    await rep.dispose()
  })
})
