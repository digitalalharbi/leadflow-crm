import { expect, test } from '@playwright/test'
import { apiGet, apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * From the first click to the riyal, through the screens: a form is designed, a link is built in five steps, a stranger fills the
 * page that link opens, and the lead, the funnel, the analytics and the project all say the same thing about it.
 */
test.describe('Tracking hub', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('design the form, build the link, a stranger fills it — and every screen agrees', async ({ page, browser }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')

    // 1 — the form, designed on the screen.
    await page.getByRole('tab', { name: 'النماذج' }).click()
    await page.getByTestId('new-form').click()
    await page.getByTestId('form-name').fill('نموذج الاهتمام — الياسمين')
    await page.getByTestId('form-headline').fill('فرصتك في الياسمين')
    await page.getByTestId('add-preset').selectOption('name')
    await page.getByTestId('add-preset').selectOption('mobile')
    await page.getByTestId('form-status').selectOption('published')
    await page.getByTestId('save-form').click()
    await expect(page.getByTestId('form-list')).toContainText('نموذج الاهتمام — الياسمين')

    // 2 — the link, in five steps.
    await page.getByRole('tab', { name: 'الروابط' }).click()
    await page.getByTestId('new-link').click()
    await expect(page.getByTestId('wizard-next')).toBeDisabled()
    await page.getByTestId('wizard-project').selectOption({ label: ALPHA.projects[0] })
    await page.getByTestId('wizard-next').click()
    await page.getByTestId('wizard-channel-meta').click()
    await page.getByTestId('wizard-name').fill('إعلان ميتا الرئيسي')
    await page.getByTestId('wizard-next').click()
    await page.getByTestId('wizard-form').selectOption({ label: 'نموذج الاهتمام — الياسمين' })
    await page.getByTestId('wizard-next').click()
    await expect(page.getByTestId('wizard-utm-source')).toHaveValue('facebook')
    await page.getByTestId('wizard-next').click()
    await expect(page.getByTestId('review-url')).toContainText('utm_medium=paid_social')
    await page.getByTestId('wizard-create').click()

    const made = page.getByTestId('wizard-made')
    await expect(made).toBeVisible()
    const url = (await page.getByTestId('wizard-url').textContent()) ?? ''
    const token = url.split('/t/')[1]
    expect(token).toMatch(/^[A-Za-z0-9]{6,24}$/)
    for (const id of ['wizard-copy', 'wizard-qr', 'wizard-preview', 'wizard-test', 'wizard-analytics']) await expect(page.getByTestId(id)).toBeVisible()
    await page.getByTestId('wizard-done').click()

    // 3 — a stranger, from an ad, with a platform click id.
    const stranger = await browser.newContext({ locale: 'ar-SA' })
    const landing = await stranger.newPage()
    await landing.goto(`/l/${token}?utm_source=facebook&fbclid=E2EFBCLID1`)
    await expect(landing.getByTestId('landing-headline')).toHaveText('فرصتك في الياسمين')
    await expect(landing.locator('html')).toHaveAttribute('dir', 'rtl')
    await landing.getByTestId('field-name').fill('سلطان المتابع')
    await landing.getByTestId('field-mobile').fill('0555123456')
    await landing.getByTestId('landing-consent').check()
    await landing.getByTestId('landing-submit').click()
    await expect(landing.getByTestId('landing-thanks')).toBeVisible()
    await stranger.close()

    // 4 — the same story from the API's side: the lead, its credit, the analytics, the project.
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      const links = await apiGet<{ links: { id: string; token: string }[] }>(admin, '/api/v1/tracking/links')
      const link = links.links.find((l) => l.token === token)!
      const a = await apiGet<{ analytics: { counts: Record<string, number>; funnel: { key: string; count: number }[] }; health: { status: string } }>(admin, `/api/v1/tracking/links/${link.id}/analytics?days=7`)
      expect(a.analytics.counts.form_submissions).toBe(1)
      expect(a.analytics.counts.leads).toBe(1)
      expect(a.analytics.counts.landing_views).toBeGreaterThanOrEqual(1)
      expect(a.analytics.funnel.find((f) => f.key === 'submit')?.count).toBe(1)
      expect(['healthy', 'warning', 'critical']).toContain(a.health.status)

      const projects = await apiGet<{ projects: { id: string; name: string }[] }>(admin, '/api/v1/projects')
      const project = projects.projects.find((p) => p.name === ALPHA.projects[0])!
      const acquisition = await apiGet<{ sources: { channels: { channel: string; leads: number }[] }; events: { event: string }[] }>(admin, `/api/v1/projects/${project.id}/acquisition?days=7`)
      expect(acquisition.sources.channels.find((c) => c.channel === 'meta')?.leads).toBe(1)
      expect(acquisition.events.map((e) => e.event)).toContain('form_submitted')
    } finally {
      await admin.dispose()
    }

    // 5 — the screens say it too: the link's funnel, and the project's Acquisition tab.
    await page.goto('/tracking')
    await page.locator(`[data-testid="analytics-link-${token}"]`).click()
    await expect(page.getByTestId('funnel-submit-count')).toHaveText(/^(1|١)$/)
    await expect(page.getByTestId('analytics-health')).toBeVisible()
    await page.keyboard.press('Escape')

    await page.goto('/projects')
    await page.getByText(ALPHA.projects[0]).first().click()
    await page.getByRole('tab', { name: 'الاستقطاب' }).click()
    await expect(page.getByTestId('project-acquisition')).toBeVisible()
    await expect(page.getByTestId('acquisition-funnel-submit-count')).toHaveText(/^(1|١)$/)
    await expect(page.getByTestId(`acq-link-${token}`)).toContainText('إعلان ميتا الرئيسي')
    await expect(page.getByTestId('acquisition-diagnostics')).toBeVisible()
  })

  test('a form that cannot reach the customer is refused beside the problem, not saved half-made', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'النماذج' }).click()
    await page.getByTestId('new-form').click()
    await page.getByTestId('form-name').fill('نموذج ناقص')
    await page.getByTestId('add-preset').selectOption('city')
    await page.getByTestId('save-form').click()

    await expect(page.getByTestId('form-error')).toContainText('رقم جوال أو بريد')
    await expect(page.getByTestId('form-editor')).toBeVisible()
  })

  test('destinations: a platform can be set up, its credential is never read back, and it says what it is waiting for', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'الوجهات' }).click()
    await page.getByTestId('new-destination').click()
    await page.getByTestId('destination-kind').selectOption('ga4')
    await page.getByTestId('destination-name').fill('تحليلات الموقع')
    await page.getByTestId('config-measurement_id').fill('G-ABCD1234')
    await page.getByTestId('destination-enabled').check()
    await page.getByTestId('save-destination').click()

    const card = page.locator('[data-testid^="destination-"]').filter({ hasText: 'تحليلات الموقع' }).first()
    await expect(card).toBeVisible()
    // The page tag is enough for the browser; the server copy still needs its secret.
    await expect(card.locator('[data-testid^="readiness-"]')).toHaveAttribute('data-state', 'browser_only')

    await card.locator('[data-testid^="open-destination-"]').click()
    await page.getByTestId('credential-api_secret').fill('s3cret-value')
    await page.getByTestId('save-destination').click()
    await card.locator('[data-testid^="open-destination-"]').click()
    await expect(page.getByTestId('credential-api_secret')).toHaveValue('')
    await expect(page.getByTestId('destination-editor')).toContainText('توجد قيمة محفوظة')
  })
})
