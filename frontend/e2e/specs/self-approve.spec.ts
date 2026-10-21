import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/**
 * Safe self-approve: off until the admin turns it on; then a person's own pause runs at once in
 * their name — and, with no ad account connected, the policy's "no" is shown as exactly that. A
 * budget change past the guard still waits in the inbox.
 */
test.describe('Safe self-approve', () => {
  test.beforeAll(() => {
    resetFixtures()
    artisan(['leadflow:sync-campaigns', '--tenant=e2e-alpha'])
    // One campaign that exists on its platform, so a change to it can really be proposed.
    artisan(['tinker', '--execute', `
      $tenant = \\App\\Models\\Tenant::where('slug', 'e2e-alpha')->firstOrFail();
      app(\\App\\Support\\TenantContext::class)->withTenant($tenant, function () {
        \\App\\Models\\Campaign::create(['platform' => 'meta', 'external_id' => 'self-1', 'name' => 'حملة الموافقة', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 20000, 'currency' => 'SAR']);
      });
    `])
  })
  test.afterAll(() => resetFixtures())

  test('off by default; switched on, a pause runs at once and a budget change past the guard still waits', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)

    // Off: the settings say so, and the operations dialog asks for approval.
    await page.goto('/settings')
    await page.getByTestId('settings-tab-approvals').click()
    await expect(page.getByTestId('self-approve-mode')).toHaveAttribute('data-enabled', 'false')
    await expect(page.getByTestId('self-approve-always-manual')).toContainText('حملة جديدة')
    await expect(page.getByTestId('self-approve-action-pause')).toBeDisabled()

    // On, for pause only, with a 10% budget guard.
    await page.getByTestId('self-approve-enabled').click()
    await expect(page.getByTestId('self-approve-mode')).toHaveAttribute('data-enabled', 'true')
    await expect(page.getByTestId('self-approve-enabled-source')).toContainText('من المساحة')
    await page.getByTestId('self-approve-action-pause').click()
    await expect(page.getByTestId('self-approve-action-pause')).toBeChecked()
    await page.getByTestId('self-approve-action-update_budget').click()
    await page.getByTestId('self-approve-guard-input').fill('10')
    await page.getByTestId('self-approve-guard-input').blur()
    await expect(page.getByTestId('self-approve-guard')).toContainText('10%')

    // The pause runs at once — and, with no account connected, the policy's no is said as such.
    await page.goto('/campaigns')
    await page.getByRole('tab', { name: 'التشغيل' }).click()
    await expect(page.getByTestId('campaign-operations')).toBeVisible()
    const row = page.locator('[data-testid^="ops-row-"]').filter({ hasText: 'حملة الموافقة' })
    await expect(row).toBeVisible()
    const id = (await row.getAttribute('data-testid'))!.replace('ops-row-', '')
    await page.getByTestId(`ops-menu-${id}`).click()
    await page.getByTestId(`ops-pause-${id}`).click()
    await expect(page.getByTestId('ops-dialog')).toHaveAttribute('data-instant', 'true')
    await expect(page.getByText('يُنفَّذ فورًا باسمك')).toBeVisible()
    await page.getByTestId('ops-reason').fill('التكلفة أعلى من المتوسط')
    await page.getByTestId('ops-submit').click()
    await expect(page.getByText('وافقت الموافقة التلقائية لكن القواعد أوقفته')).toBeVisible()
    // The toast sits over the row's menu until it goes.
    await expect(page.getByText('وافقت الموافقة التلقائية لكن القواعد أوقفته')).toBeHidden({ timeout: 15_000 })

    const admin = await apiLogin(ALPHA.admin.email)
    const proposals = (await (await admin.context.get(`/api/v1/action-proposals?campaign_id=${id}`)).json()).data.proposals as { action: string; status: string; approval: string; policy: { blocks: string[] } }[]
    const pause = proposals.find((p) => p.action === 'pause')!
    expect(pause.approval).toBe('self')
    expect(pause.status).toBe('blocked')
    expect(pause.policy.blocks).toContain('not_connected')

    // A 15% budget change is past the guard (and inside the client's own 20%): it waits for a person, as before.
    await page.getByTestId(`ops-menu-${id}`).click()
    await page.getByTestId(`ops-budget-${id}`).click()
    await expect(page.getByTestId('ops-dialog')).toHaveAttribute('data-instant', 'true')
    await page.getByTestId('ops-budget-input').fill('230')
    await page.getByTestId('ops-reason').fill('نرفع الميزانية للموسم')
    await page.getByTestId('ops-submit').click()
    await expect(page.getByTestId(`ops-pending-${id}`)).toBeVisible()
    const again = (await (await admin.context.get(`/api/v1/action-proposals?campaign_id=${id}`)).json()).data.proposals as { action: string; status: string; approval: string }[]
    const budget = again.find((p) => p.action === 'update_budget')!
    expect(budget.status).toBe('pending')
    expect(budget.approval).toBe('manual')

    // The inbox card says which one the mode handled.
    await page.getByTestId(`ops-pending-${id}`).click()
    await expect(page.getByTestId('ops-pending-drawer').locator('[data-testid="proposal-card"][data-approval="self"]')).toHaveCount(1)
    await expect(page.getByTestId('ops-pending-drawer').locator('[data-testid="proposal-card"][data-approval="manual"]')).toHaveCount(1)

    // Every automatic run is on the trail.
    const trail = (await (await admin.context.get('/api/v1/audit?category=campaigns')).json()).data.entries as { action: string }[]
    expect(trail.some((e) => e.action === 'action_proposal.self_approved')).toBe(true)
    await admin.dispose()
  })

  test('only an admin sees or changes it', async ({ page }) => {
    await authenticateAs(page, ALPHA.manager.email)
    await page.goto('/settings')
    await expect(page.getByTestId('settings-tab-approvals')).toHaveCount(0)
    const manager = await apiLogin(ALPHA.manager.email)
    expect((await manager.context.patch('/api/v1/settings/self-approval', { data: { enabled: true } })).status()).toBe(403)
    await manager.dispose()
  })
})
