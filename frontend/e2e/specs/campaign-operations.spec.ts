import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/**
 * Campaign operations: the table with quality, why and what to do — and the five things a person
 * can do to a campaign, each filed for approval rather than made.
 */
test.describe('Campaign operations', () => {
  test.beforeAll(() => {
    resetFixtures()
    // The seeded leads already carry campaigns; this turns them into campaign rows.
    artisan(['leadflow:sync-campaigns', '--tenant=e2e-alpha'])
    // One campaign that exists on its platform, so a change to it can really be proposed.
    artisan(['tinker', '--execute', `
      \\App\\Support\\TenantContext::class;
      $tenant = \\App\\Models\\Tenant::where('slug', 'e2e-alpha')->firstOrFail();
      app(\\App\\Support\\TenantContext::class)->withTenant($tenant, function () {
        $c = \\App\\Models\\Campaign::create(['platform' => 'meta', 'external_id' => 'ops-1', 'name' => 'حملة التشغيل', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 20000, 'currency' => 'SAR']);
        \\App\\Models\\MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 150000, 'currency' => 'SAR']);
      });
    `])
  })
  test.afterAll(() => resetFixtures())

  async function open(page: import('@playwright/test').Page, email: string) {
    await authenticateAs(page, email)
    await page.goto('/campaigns')
    await page.getByRole('tab', { name: 'التشغيل' }).click()
    await expect(page.getByTestId('campaign-operations')).toBeVisible()
  }

  test('every campaign is a row with its quality, and a row opens to why and what to do', async ({ page }) => {
    await open(page, ALPHA.admin.email)

    const rows = page.locator('[data-testid^="ops-row-"]')
    await expect(rows.first()).toBeVisible()
    expect(await rows.count()).toBeGreaterThan(0)
    await expect(page.getByTestId('ops-classes')).toBeVisible()

    // The class filter narrows to a class; "all" brings everything back.
    const total = await rows.count()
    await page.getByTestId('ops-class-critical').click()
    expect(await rows.count()).toBeLessThanOrEqual(total)
    await page.getByTestId('ops-class-all').click()
    await expect(rows).toHaveCount(total)

    await page.locator('[data-testid^="ops-toggle-"]').first().click()
    await expect(page.locator('[data-testid^="ops-detail-"]').first()).toBeVisible()
  })

  test('a change is filed for approval, once, and the campaign waits for a decision', async ({ page }) => {
    await open(page, ALPHA.admin.email)

    const row = page.locator('[data-testid^="ops-row-"]').filter({ hasText: 'حملة التشغيل' })
    await expect(row).toBeVisible()
    const id = (await row.getAttribute('data-testid'))!.replace('ops-row-', '')
    await page.getByTestId(`ops-menu-${id}`).click()
    // No delete anywhere in the menu.
    await expect(page.getByRole('menuitem', { name: /حذف/ })).toHaveCount(0)
    await page.getByTestId(`ops-budget-${id}`).click()
    await page.getByTestId('ops-budget-input').fill('215')
    await page.getByTestId('ops-reason').fill('التكلفة أعلى من المتوسط')
    await page.getByTestId('ops-submit').click()

    await expect(page.getByTestId(`ops-pending-${id}`)).toBeVisible()
    await page.getByTestId(`ops-pending-${id}`).click()
    await expect(page.getByTestId('ops-pending-drawer')).toContainText('التكلفة أعلى من المتوسط')
  })

  test('tracking health opens for a campaign and sync says there is nothing connected to read', async ({ page }) => {
    await open(page, ALPHA.admin.email)

    const id = (await page.locator('[data-testid^="ops-row-"]').first().getAttribute('data-testid'))!.replace('ops-row-', '')

    await page.getByTestId(`ops-menu-${id}`).click()
    await page.getByTestId(`ops-tracking-${id}`).click()
    await expect(page.getByTestId('ops-tracking-drawer')).toBeVisible()
    await expect(page.getByTestId('tracking-links')).toBeVisible()
    await page.keyboard.press('Escape')

    await page.getByTestId(`ops-menu-${id}`).click()
    await page.getByTestId(`ops-sync-${id}`).click()
    await expect(page.getByText('لا حساب مربوط بهذه المنصة')).toBeVisible()
  })

  test('somebody who reads campaigns but does not run them is offered nothing to press', async ({ page }) => {
    await open(page, ALPHA.finance.email)

    const id = (await page.locator('[data-testid^="ops-row-"]').first().getAttribute('data-testid'))!.replace('ops-row-', '')
    await page.getByTestId(`ops-menu-${id}`).click()
    await expect(page.getByTestId(`ops-tracking-${id}`)).toBeVisible()
    for (const act of ['pause', 'resume', 'budget', 'status', 'sync']) await expect(page.getByTestId(`ops-${act}-${id}`)).toHaveCount(0)
  })
})
