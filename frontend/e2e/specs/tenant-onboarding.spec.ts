import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, apiPlatformLogin, apiPost } from '../fixtures/api'
import { PASSWORD } from '../fixtures/data'
import { signIn, signInAs, signInPlatform } from '../fixtures/pages'
import { linkFromEmail, resetFixtures, ensurePlatformOwner } from '../fixtures/seed'

/**
 * The commercial promise: a new customer is onboarded without a code change, a
 * migration or a deploy. This drives the whole path — the owner creates the
 * client, the client's admin signs in to their own branded workspace, and the
 * first lead arrives through the endpoint created with them.
 */
test.describe('Tenant onboarding', () => {
  // Fixed, not timestamped: Playwright may re-evaluate a spec module between
  // tests, so anything derived from Date.now() can differ from test to test.
  // The seeder removes this tenant, so each run still starts from nothing.
  const slug = 'e2e-onboarding'
  const company = 'شركة الاختبار للتطوير'
  const systemName = 'اختبار CRM'
  const adminEmail = 'admin@e2e-onboarding.test'
  const createdColour = '#c2410c'
  const logo = 'https://cdn.example.test/e2e-onboarding.png'
  const colour = '#9333ea'

  test.beforeAll(() => {
    resetFixtures()
    // The platform owner is not part of the tenant fixtures.
    ensurePlatformOwner('owner@e2e.test', PASSWORD)
  })

  test('a platform owner creates a client that is immediately usable', async ({ page }) => {
    // The platform owner's own door.
    await signInPlatform(page, 'owner@e2e.test')

    await page.getByRole('button', { name: 'عميل جديد' }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('اسم الشركة').fill(company)
    await dialog.getByLabel('اسم النظام').fill(systemName)
    await dialog.getByLabel('النطاق الفرعي').fill(slug)

    // The whole identity is set here: everything a client needs to look like
    // their own product, entered once by the operator.
    await dialog.getByLabel('رابط الشعار').fill(logo)
    await dialog.getByRole('textbox', { name: 'اللون الأساسي' }).fill(createdColour)
    await dialog.getByLabel('العملة').selectOption('AED')
    await dialog.getByLabel('المنطقة الزمنية').selectOption('Asia/Dubai')

    await dialog.getByLabel('اسم المدير').fill('مدير العميل الجديد')
    await dialog.getByLabel('بريد المدير').fill(adminEmail)
    // No password is typed for the owner: they get a link and choose their own.
    await expect(dialog.getByTestId('owner-setup-note')).toBeVisible()
    await dialog.getByRole('button', { name: 'إنشاء' }).click()

    // The webhook endpoint is handed over at creation, shown exactly once.
    const secretDialog = page.getByRole('dialog')
    await expect(secretDialog.getByText('رابط استقبال العملاء جاهز')).toBeVisible()
    await expect(secretDialog.getByText(/integrations\/webhooks\//)).toBeVisible()
    await secretDialog.getByRole('button', { name: 'إغلاق' }).click()

    await expect(page.getByText(company)).toBeVisible()
  })

  test('the new owner chooses their own password from the emailed setup link', async ({ page }) => {
    const token = linkFromEmail(adminEmail, 'set-password')
    await page.goto(`/set-password/${token}`)
    await page.getByTestId('new-password').fill(PASSWORD)
    await page.getByTestId('confirm-password').fill(PASSWORD)
    await page.getByTestId('set-password-submit').click()
    await expect(page).not.toHaveURL(/set-password/)
    await expect(page.getByTestId('page-title')).toBeVisible()
  })

  test('the identity the operator entered is the one the client gets', async () => {
    // Set on the creation form, visible on the client's own login screen before
    // anyone has signed in or touched settings.
    const session = await apiPlatformLogin('owner@e2e.test')
    try {
      const branding = await apiGet<{
        company_name: string
        system_name: string
        logo_url: string
        primary_color: string
        currency: string
        timezone: string
      }>(session, `/api/v1/branding?tenant=${slug}`)

      expect(branding.company_name).toBe(company)
      expect(branding.system_name).toBe(systemName)
      expect(branding.logo_url).toBe(logo)
      expect(branding.primary_color.toLowerCase()).toBe(createdColour)
      expect(branding.currency).toBe('AED')
      expect(branding.timezone).toBe('Asia/Dubai')
    } finally {
      await session.dispose()
    }
  })

  test('the new admin signs in to their own branded workspace', async ({ page }) => {
    await page.goto(`/login?tenant=${slug}`)

    // Branded before anyone authenticates.
    await expect(page.getByText(systemName).first()).toBeVisible()
    await expect(page.getByText(company)).toBeVisible()

    await signIn(page, adminEmail)

    await expect(page.getByText(systemName).first()).toBeVisible()
    // The tab is `<screen> — <the workspace's own name>`.
    await expect(page).toHaveTitle(`لوحة التحكم — ${systemName}`)

    // A brand-new tenant starts empty, and says so usefully.
    await page.goto('/leads')
    await expect(page.getByText('لا يوجد عملاء محتملون حتى الآن')).toBeVisible()
    // The header and the empty state both offer the action, which is the point:
    // a brand-new tenant is told what to do next wherever they are looking.
    await expect(page.getByRole('button', { name: 'إضافة عميل' })).toHaveCount(2)

    // The onboarding checklist points at what is still to do.
    await page.goto('/dashboard')
    await expect(page.getByText('لنجهّز نظامك')).toBeVisible()
  })

  test('the admin can set their own identity without any code change', async ({ page }) => {
    await signInAs(page, adminEmail)
    await page.goto('/settings')

    // Identity and workspace basics are separate sections now.
    await page.getByTestId('settings-tab-branding').click()
    await page.getByLabel('اللون الأساسي').fill(colour)
    await page.getByLabel('بريد الدعم').fill(`support@${slug}.test`)
    await page.getByRole('button', { name: 'حفظ' }).first().click()
    await expect(page.getByText('تم الحفظ.')).toBeVisible()

    await page.getByTestId('settings-tab-general').click()
    await page.getByLabel('العملة').fill('AED')
    await page.getByLabel('المنطقة الزمنية').selectOption('Asia/Dubai')
    await page.getByRole('button', { name: 'حفظ' }).first().click()
    await expect(page.getByText('تم الحفظ.')).toBeVisible()

    // What a visitor to their login page now sees.
    const session = await apiLogin(adminEmail)
    try {
      const branding = await apiGet<{
        primary_color: string
        currency: string
        timezone: string
        support_email: string
      }>(session, `/api/v1/branding?tenant=${slug}`)

      expect(branding.primary_color.toLowerCase()).toBe(colour)
      expect(branding.currency).toBe('AED')
      expect(branding.timezone).toBe('Asia/Dubai')
      expect(branding.support_email).toBe(`support@${slug}.test`)
    } finally {
      await session.dispose()
    }
  })

  test('the first lead arrives through the endpoint created with the tenant', async ({ page }) => {
    // Issue a fresh endpoint, since the provisioning secret is shown only once.
    const session = await apiLogin(adminEmail)
    let url = ''
    let secret = ''

    try {
      const integrations = await apiGet<{
        integrations: Array<{ integration: { id: string } | null; provider: string }>
      }>(session, '/api/v1/integrations')

      const webhook = integrations.integrations.find((i) => i.provider === 'generic_webhook')
      const created = await apiPost(session, `/api/v1/integrations/${webhook!.integration!.id}/webhooks`, {
        label: 'E2E onboarding',
      })

      const body = await created.json()
      url = body.data.endpoint.url
      secret = body.data.secret
    } finally {
      await session.dispose()
    }

    const response = await page.request.post(url, {
      headers: { 'X-Webhook-Secret': secret, Accept: 'application/json' },
      data: {
        name: 'أول عميل للمستأجر الجديد',
        mobile: '0501239876',
        city: 'دبي',
        campaign: 'حملة الإطلاق',
      },
      failOnStatusCode: false,
    })

    expect(response.status()).toBe(200)

    await signInAs(page, adminEmail)
    await page.goto('/leads')

    await expect(page.getByRole('link', { name: 'أول عميل للمستأجر الجديد' })).toBeVisible()
    // And the empty state is gone, because the tenant now has data.
    await expect(page.getByText('لا يوجد عملاء محتملون حتى الآن')).toHaveCount(0)
  })
})
