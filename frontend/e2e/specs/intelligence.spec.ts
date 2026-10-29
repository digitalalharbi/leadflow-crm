import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** Sales intelligence (fair ranking), the attribution explorer, and recommendations that say what to do. */
test.describe('Intelligence', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('the team tab ranks reps by what they did with their leads, with the volume each carried', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/reports?tab=team')

    await expect(page.getByTestId('sales-intelligence')).toBeVisible()
    await expect(page.getByTestId('si-close')).toBeVisible()
    await expect(page.getByTestId('si-ranking')).toBeVisible()
    await expect(page.getByTestId('si-rank-1')).toBeVisible()
    await expect(page.getByTestId('si-diff-1')).toContainText(/[+-]?\d/)
    await expect(page.getByTestId('si-heat')).toBeVisible()

    // A rank opens to its detail.
    await page.getByTestId('si-rank-1').getByRole('button').click()
    await expect(page.getByTestId('si-detail-1')).toBeVisible()
  })

  test('a rep cannot see how the team is ranked', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/reports?tab=team')
    await expect(page.getByTestId('sales-intelligence')).toHaveCount(0)
  })

  test('the attribution explorer filters the slice and sets the four models side by side', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'الإسناد' }).click()

    await expect(page.getByTestId('attribution-filters')).toBeVisible()
    for (const f of ['project', 'campaign', 'platform', 'ad', 'creative', 'partner', 'rep']) await expect(page.getByTestId(`attr-filter-${f}`)).toBeVisible()
    await expect(page.getByTestId('attribution-compare')).toBeVisible()
    await expect(page.getByTestId('compare-grid')).toBeVisible()

    // Pick a project: a filter is on, and it can be cleared.
    await page.getByTestId('attr-filter-project').selectOption({ index: 1 })
    await expect(page.getByTestId('attr-filter-clear')).toBeVisible()
    await page.getByTestId('compare-metric-reservations').click()
    await expect(page.getByTestId('compare-metric-reservations')).toHaveAttribute('aria-pressed', 'true')
    await page.getByTestId('attr-filter-clear').click()
    await expect(page.getByTestId('attr-filter-clear')).toHaveCount(0)
  })

  test('a finding says what it means, what to do, and takes you there', async ({ page }) => {
    artisan(['tinker', '--execute', `
      $t = \\App\\Models\\Tenant::where('slug', 'e2e-alpha')->firstOrFail();
      app(\\App\\Support\\TenantContext::class)->withTenant($t, function () {
        $cheap = \\App\\Models\\Campaign::create(['platform' => 'meta', 'external_id' => 'rec-a', 'name' => 'حملة رخيصة', 'origin' => 'sync', 'status' => 'active', 'currency' => 'SAR']);
        $dear = \\App\\Models\\Campaign::create(['platform' => 'tiktok', 'external_id' => 'rec-b', 'name' => 'حملة غالية', 'origin' => 'sync', 'status' => 'active', 'currency' => 'SAR']);
        \\App\\Models\\MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $cheap->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 200000, 'currency' => 'SAR']);
        \\App\\Models\\MetricSnapshot::create(['platform' => 'tiktok', 'level' => 'campaign', 'campaign_id' => $dear->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 600000, 'currency' => 'SAR']);
        foreach ([[$cheap, 'ر', 1000], [$dear, 'غ', 2000]] as [$c, $p, $base]) {
          for ($i = 1; $i <= 20; $i++) {
            $lead = \\App\\Models\\Lead::create(['full_name' => $p.$i, 'phone_normalized' => '+966551'.($base + $i).'55', 'status' => 'contacted', 'first_contact_at' => now(), 'last_contact_at' => now()]);
            \\Illuminate\\Support\\Facades\\DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $c->id, 'created_at' => now()->subDay()]);
            if ($c->id === $dear->id && $i <= 8) { \\App\\Models\\Reservation::create(['lead_id' => $lead->id, 'status' => 'pending', 'reserved_at' => now()]); }
          }
        }
      });
    `])

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const finding = page.getByTestId('rec-cheap_but_weak')
    await expect(finding).toBeVisible()
    await expect(finding.getByTestId('rec-finding')).toContainText('حملة رخيصة')
    await expect(finding.getByTestId('rec-impact')).toContainText(/\S/)
    await expect(finding.getByTestId('rec-action')).toContainText('حملة غالية')
    await finding.getByTestId('rec-link').click()
    await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}/)
  })
})
