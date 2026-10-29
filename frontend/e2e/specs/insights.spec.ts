import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** What to look at first: anything outside its normal range, then a few facts with a place to go. */
test.describe('Insights and anomalies', () => {
  test.beforeAll(() => {
    resetFixtures()
    artisan(['tinker', '--execute', `
      $tenant = \\App\\Models\\Tenant::where('slug', 'e2e-alpha')->firstOrFail();
      \\Illuminate\\Support\\Facades\\DB::table('anomalies')->insert([
        ['uuid' => (string) \\Illuminate\\Support\\Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'lead_spike', 'subject' => '', 'severity' => 'critical', 'detected_on' => now()->toDateString(), 'observed' => 71, 'expected' => 20, 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => (string) \\Illuminate\\Support\\Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'spend_no_qualified', 'subject' => 'حملة الاختبار', 'severity' => 'warning', 'detected_on' => now()->toDateString(), 'observed' => 900, 'expected' => 0, 'created_at' => now(), 'updated_at' => now()],
      ]);
      $lead = \\App\\Models\\Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->first();
      \\Illuminate\\Support\\Facades\\DB::table('reservations')->insert(['uuid' => (string) \\Illuminate\\Support\\Str::uuid(), 'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'status' => 'pending', 'expires_at' => now()->addHours(4), 'created_at' => now(), 'updated_at' => now()]);
    `])
  })

  test.afterAll(() => resetFixtures())

  test('the dashboard opens on what moved and the few facts to look at, each with a place to go', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const strip = page.getByTestId('insights')
    await expect(strip).toBeVisible()

    // Anything outside its normal range comes first, with the numbers: what it was against what it usually is.
    await expect(page.getByTestId('anomaly-lead_spike')).toHaveAttribute('data-severity', 'critical')
    await expect(page.getByTestId('anomaly-lead_spike')).toContainText('71 / 20')
    await expect(page.getByTestId('anomaly-spend_no_qualified')).toContainText('حملة الاختبار')

    // Then short facts — and each is a link.
    const card = page.getByTestId('insight-holds_expiring')
    await expect(card).toBeVisible()
    await expect(card.locator('a')).toHaveAttribute('href', '/visits')
  })

  test('an anomaly is dismissed with one press and does not come back', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    await page.getByTestId('dismiss-spend_no_qualified').click()
    await expect(page.getByTestId('anomaly-spend_no_qualified')).toHaveCount(0)

    await page.reload()
    await expect(page.getByTestId('anomaly-lead_spike')).toBeVisible()
    await expect(page.getByTestId('anomaly-spend_no_qualified')).toHaveCount(0)
  })

  test('a rep sees facts about their own work, and no team-wide anomalies', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    await expect(page.getByTestId('anomaly-lead_spike')).toHaveCount(0)
    await expect(page.getByTestId('dismiss-lead_spike')).toHaveCount(0)
  })
})
