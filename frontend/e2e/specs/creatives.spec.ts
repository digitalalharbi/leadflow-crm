import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** One synced campaign with one ad and a day of numbers — test data, in the test database only. */
function seedAd() {
  const backend = process.env.E2E_BACKEND_DIR ?? '../backend'
  const php = `
    $t = App\\Models\\Tenant::where('slug', 'e2e-alpha')->first();
    app(App\\Support\\TenantContext::class)->withTenant($t, function () {
      $c = App\\Models\\Campaign::create(['platform' => 'meta', 'external_id' => 'e2e-c', 'name' => 'حملة الإعلانات', 'origin' => 'sync', 'status' => 'active']);
      $s = App\\Models\\AdSet::create(['campaign_id' => $c->id, 'external_id' => 'e2e-s', 'name' => 'مجموعة']);
      $cr = App\\Models\\Creative::create(['platform' => 'meta', 'external_id' => 'e2e-cr', 'format' => 'video', 'headline' => 'شقق جاهزة']);
      $ad = App\\Models\\Ad::create(['campaign_id' => $c->id, 'ad_set_id' => $s->id, 'creative_id' => $cr->id, 'external_id' => 'e2e-a', 'name' => 'فيديو الواجهة', 'status' => 'active']);
      App\\Models\\MetricSnapshot::create(['campaign_id' => $c->id, 'ad_set_id' => $s->id, 'ad_id' => $ad->id, 'platform' => 'meta', 'level' => 'ad', 'date' => now()->toDateString(), 'spend_minor' => 20000, 'impressions' => 1500, 'clicks' => 30]);
    });`
  execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], { cwd: backend, stdio: 'ignore' })
}

test.describe('الإعلانات — creative operations', () => {
  test.beforeAll(() => {
    resetFixtures()
    seedAd()
  })
  test.afterAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/creatives')
  })

  test('each ad is a preview with what it brought and six actions', async ({ page }) => {
    const card = page.getByTestId('creative-card').filter({ hasText: 'فيديو الواجهة' })
    await expect(card).toBeVisible()
    await expect(card).toContainText('2%')
    for (const id of ['act-copy', 'act-hook', 'act-variation', 'act-duplicate', 'act-pause', 'act-replace']) {
      await expect(card.getByTestId(id)).toBeVisible()
    }
  })

  test('without AI, asking the agent says so and a person writes the draft', async ({ page }) => {
    const card = page.getByTestId('creative-card').filter({ hasText: 'فيديو الواجهة' })
    await card.getByTestId('act-hook').click()

    const drawer = page.getByTestId('drafts-drawer')
    await drawer.getByTestId('drafts-ai').click()
    await expect(drawer.getByTestId('drafts-text')).toBeVisible()

    await drawer.getByTestId('drafts-text').fill('جاهزة للسكن اليوم\nاستلم مفتاحك هذا الشهر')
    await drawer.getByTestId('drafts-save').click()
    await expect(drawer.getByTestId('drafts-list')).toContainText('استلم مفتاحك')
  })

  test('pausing an ad is a proposal, not a pause', async ({ page }) => {
    const card = page.getByTestId('creative-card').filter({ hasText: 'فيديو الواجهة' })
    await card.getByTestId('act-pause').click()
    await page.getByTestId('creative-reason').fill('إرهاق واضح')
    await page.getByTestId('creative-propose').click()

    await page.goto('/campaigns')
    await expect(page.getByTestId('proposal-card').filter({ hasText: 'إرهاق واضح' })).toBeVisible()
  })
})
