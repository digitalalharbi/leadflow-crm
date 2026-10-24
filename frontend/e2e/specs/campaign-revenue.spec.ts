import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** From the riyal spent to the riyal earned. */
test.describe('الحملات — from spend to revenue', () => {
  test.beforeAll(() => {
    resetFixtures()
    artisan(['leadflow:sync-campaigns', '--tenant=e2e-alpha'])
    // The first campaign with leads costs 2,500 and one of its leads bought for 900,000.
    artisan(['tinker', '--execute', `
      $c = \\App\\Models\\Campaign::withoutGlobalScopes()->whereIn('id', \\App\\Models\\Lead::withoutGlobalScopes()->whereNotNull('campaign_ref_id')->pluck('campaign_ref_id'))->orderBy('id')->firstOrFail();
      \\App\\Models\\CampaignSpend::withoutGlobalScopes()->create(['tenant_id' => $c->tenant_id, 'platform' => $c->platform, 'campaign_name' => $c->name, 'spend_date' => now()->toDateString(), 'amount_minor' => 250000, 'currency' => 'SAR', 'source' => 'manual']);
      \\App\\Models\\Lead::withoutGlobalScopes()->whereKey(\\App\\Models\\Lead::withoutGlobalScopes()->where('campaign_ref_id', $c->id)->orderBy('id')->value('id'))->update(['status' => 'won', 'deal_value_minor' => 90000000, 'won_at' => now()]);
    `])
  })

  test.afterAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/campaigns')
    await page.getByRole('tab', { name: 'من الإنفاق إلى الإيراد' }).click()
    await expect(page.getByTestId('revenue-panel')).toBeVisible()
  })

  test('ten figures, each with what it did against the period before', async ({ page }) => {
    for (const key of ['spend', 'leads', 'interested', 'visits', 'reservations', 'won', 'revenue', 'roas', 'cac', 'cpl']) {
      await expect(page.getByTestId(`rk-${key}`)).toBeVisible()
    }

    await expect(page.getByTestId('rk-spend-value')).toContainText('2')
    await expect(page.getByTestId('rk-won-value')).not.toHaveText('0')
    await expect(page.getByTestId('rk-roas-value')).toContainText('x')
  })

  test('the funnel runs lead to sale with what each step cost', async ({ page }) => {
    const funnel = page.getByTestId('revenue-funnel')
    await expect(funnel).toBeVisible()

    for (const label of ['عملاء', 'مؤهلون', 'مهتمون', 'زيارات', 'حجوزات', 'مبيعات']) {
      await expect(funnel).toContainText(label)
    }
  })

  test('the trend switches between money and every step, one axis at a time', async ({ page }) => {
    await expect(page.getByTestId('revenue-trend')).toBeVisible()
    await expect(page.getByTestId('trend-revenue')).toHaveAttribute('aria-pressed', 'true')

    await page.getByTestId('trend-leads').click()
    await expect(page.getByTestId('trend-leads')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('revenue-trend-total')).not.toHaveText('')
  })

  test('rings, a ranking you can switch, and a heatmap of where each funnel narrows', async ({ page }) => {
    await expect(page.getByTestId('mix-platforms')).toBeVisible()
    await expect(page.getByTestId('mix-revenue')).toBeVisible()

    await expect(page.getByTestId('ranking-bars')).toBeVisible()
    await page.getByTestId('ranking-wasted').click()
    await expect(page.getByTestId('ranking-wasted')).toHaveAttribute('aria-pressed', 'true')

    await expect(page.getByTestId('revenue-heatmap')).toBeVisible()
    expect(await page.getByTestId('heat-cell').count()).toBeGreaterThanOrEqual(5)
  })

  test('a campaign opens from the table, down to its ads', async ({ page }) => {
    const row = page.getByTestId('revenue-table').getByTestId('revenue-row-link').first()
    await expect(row).toBeVisible()
    await row.click()

    await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}/)
  })

  test('it never scrolls the page sideways at a laptop width', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow).toBeLessThanOrEqual(1)
  })
})
