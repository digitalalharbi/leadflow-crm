import { expect, test } from '@playwright/test'
import { ALPHA, BETA, PASSWORD } from '../fixtures/data'
import { apiGet, apiLogin, apiPlatformLogin, apiPost } from '../fixtures/api'
import { authenticateAs, signInPlatform } from '../fixtures/pages'
import { artisan, linkFromEmail, resetFixtures } from '../fixtures/seed'

const OWNER = 'owner@e2e-saas.test'

interface Tenant { id: string; slug: string }
interface Plan { id: string; key: string }

/** The platform's own session, for setting a company's plan or lifecycle the way the console does. */
async function asPlatform<T>(fn: (session: Awaited<ReturnType<typeof apiPlatformLogin>>) => Promise<T>): Promise<T> {
  const session = await apiPlatformLogin(OWNER)

  try {
    return await fn(session)
  } finally {
    await session.dispose()
  }
}

async function tenantId(slug: string): Promise<string> {
  return asPlatform(async (session) => {
    const body = await (await session.context.get(`/api/v1/super-admin/tenants?search=${slug}`)).json()

    return (body.data as Tenant[]).find((t) => t.slug === slug)!.id
  })
}

/**
 * Mohtam sold to many companies: what a company's plan and lifecycle do to its screens, a person who belongs to two
 * companies moving between them, and the platform's support access — each as a person would meet it.
 */
test.describe('SaaS', () => {
  test.beforeAll(() => {
    resetFixtures()
    artisan(['leadflow:create-super-admin', `--email=${OWNER}`, `--password=${PASSWORD}`])
  })

  test('a feature outside the plan is not offered, and its address says the plan decides it', async ({ page }) => {
    const alpha = await tenantId(ALPHA.slug)
    const starter = await asPlatform(async (s) => (await apiGet<{ plans: Plan[] }>(s, '/api/v1/super-admin/plans')).plans.find((p) => p.key === 'starter')!)
    await asPlatform((s) => apiPost(s, `/api/v1/super-admin/tenants/${alpha}/subscription/activate`, { plan_id: starter.id }))

    try {
      await authenticateAs(page, ALPHA.admin.email)
      await page.goto('/')
      await expect(page.getByTestId('page-title')).toBeVisible()
      await expect(page.getByRole('link', { name: 'الأتمتة', exact: true })).toHaveCount(0)

      await page.goto('/automation')
      await expect(page.getByTestId('feature-locked')).toBeVisible()
      await expect(page.getByTestId('feature-locked')).toContainText('غير مشمول في باقتك')

      // The billing tab says what the plan includes, from the server.
      await page.goto('/settings')
      await page.getByTestId('settings-tab-subscription').click()
      await expect(page.getByTestId('billing-plan')).toHaveText('Starter')
      await expect(page.locator('[data-feature="automations"]')).toHaveAttribute('data-on', 'false')
    } finally {
      const enterprise = await asPlatform(async (s) => (await apiGet<{ plans: Plan[] }>(s, '/api/v1/super-admin/plans')).plans.find((p) => p.key === 'enterprise')!)
      await asPlatform((s) => apiPost(s, `/api/v1/super-admin/tenants/${alpha}/subscription/activate`, { plan_id: enterprise.id }))
    }
  })

  test('a suspended company sees why, keeps its data, and opens again when reactivated', async ({ page }) => {
    const beta = await tenantId(BETA.slug)
    await asPlatform((s) => apiPost(s, `/api/v1/super-admin/tenants/${beta}/subscription/suspend`, { reason: 'E2E' }))

    try {
      await authenticateAs(page, BETA.admin.email)
      await page.goto('/leads')
      await expect(page.getByTestId('subscription-required')).toBeVisible()
      await expect(page.getByTestId('subscription-required')).toHaveAttribute('data-state', 'suspended')
      await expect(page.getByTestId('subscription-required')).toContainText('بياناتك محفوظة')
    } finally {
      await asPlatform((s) => apiPost(s, `/api/v1/super-admin/tenants/${beta}/subscription/reactivate`))
    }

    await page.reload()
    await expect(page.getByTestId('subscription-required')).toHaveCount(0)
    await expect(page.getByText(BETA.secretLead)).toBeVisible()
  })

  test('one person in two companies switches between them, and each company stays its own', async ({ page }) => {
    // ALPHA's admin proves their inbox with a reset link, as anyone joining a second company would have.
    const reset = await page.request.post(`${process.env.E2E_API_URL}/api/v1/auth/password/forgot`, { data: { email: ALPHA.admin.email } })
    expect(reset.ok()).toBeTruthy()
    await page.goto(`/set-password/${linkFromEmail(ALPHA.admin.email, 'set-password')}`)
    await page.getByTestId('new-password').fill(PASSWORD)
    await page.getByTestId('confirm-password').fill(PASSWORD)
    await page.getByTestId('set-password-submit').click()
    await expect(page).not.toHaveURL(/set-password/)

    // BETA invites them; they accept with their own password.
    const beta = await apiLogin(BETA.admin.email)
    try {
      expect((await apiPost(beta, '/api/v1/invitations', { email: ALPHA.admin.email, role: 'manager' })).ok()).toBeTruthy()
    } finally {
      await beta.dispose()
    }
    await page.goto(`/invite/${linkFromEmail(ALPHA.admin.email, 'invite')}`)
    await expect(page.getByTestId('invite-company')).toContainText(BETA.companyName)
    await page.getByTestId('invite-password').fill(PASSWORD)
    await page.getByTestId('invite-accept').click()
    await expect(page).not.toHaveURL(/\/invite\//)

    // Now in BETA: BETA's lead is there.
    await page.goto('/leads')
    await expect(page.getByText(BETA.secretLead)).toBeVisible()

    // The switcher lists both; moving to ALPHA leaves BETA's data behind.
    await page.getByTestId('account-menu').click()
    await expect(page.getByTestId('workspace-switcher')).toBeVisible()
    await page.getByTestId('workspace-switcher').getByRole('button', { name: new RegExp(ALPHA.companyName) }).click()
    await expect(page.getByTestId('page-title')).toBeVisible()
    await page.goto('/leads')
    await expect(page.getByText(BETA.secretLead)).toHaveCount(0)
  })

  test('the platform enters a company as support, says so on every screen, and returns to the console', async ({ page }) => {
    // Through the platform's own login form: the support session swaps the token, and nothing may put the old one back.
    await signInPlatform(page, OWNER)
    await page.getByTestId(`tenant-row-${ALPHA.slug}`).click()

    const panel = page.getByRole('dialog')
    await panel.getByTestId('support-reason').fill('E2E ticket: check the import')
    await panel.getByTestId('support-enter').click()

    await expect(page.getByTestId('support-banner')).toBeVisible()
    await page.goto('/leads')
    await expect(page.getByTestId('support-banner')).toBeVisible()

    await page.getByTestId('support-stop').click()
    await expect(page).toHaveURL(/\/platform/)
    await expect(page.getByRole('heading', { name: 'إدارة المنصة', level: 1 })).toBeVisible()

    // Both trails recorded it.
    const log = await asPlatform(async (s) => (await (await s.context.get('/api/v1/super-admin/audit?action=impersonation')).json()).data as { action: string }[])
    expect(log.map((r) => r.action)).toEqual(expect.arrayContaining(['impersonation.started', 'impersonation.ended']))
  })
})
