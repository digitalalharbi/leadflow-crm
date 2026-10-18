import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The leads already carry campaigns; the hourly job turns them into rows. */
function attributeCampaigns() {
  const backend = process.env.E2E_BACKEND_DIR ?? '../backend'
  execFileSync('php', ['artisan', 'leadflow:sync-campaigns', '--tenant=e2e-alpha'], { cwd: backend, stdio: 'ignore' })
}

test.describe('الحملات — the campaign command center', () => {
  test.beforeAll(() => {
    resetFixtures()
    attributeCampaigns()
  })

  test.afterAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('opens with the numbers, the funnel from form to deal, and campaigns as cards', async ({ page }) => {
    await page.goto('/campaigns')

    for (const id of ['ck-spend', 'ck-leads', 'ck-qualified', 'ck-cpl', 'ck-cpql', 'ck-roas', 'campaign-funnel', 'campaign-ranking']) {
      await expect(page.getByTestId(id)).toBeVisible()
    }

    // No ad account is connected here, so there is no spend — and the screen says so
    // rather than drawing zeros.
    await expect(page.getByTestId('ck-spend')).toHaveText('—')

    expect(await page.getByTestId('campaign-card').count()).toBeGreaterThan(0)
  })

  test('says which platforms are wired, and never calls an unbuilt one available', async ({ page }) => {
    await page.goto('/campaigns')

    await expect(page.getByTestId('platform-meta')).toHaveAttribute('data-state', 'available')
    for (const key of ['tiktok', 'google_ads', 'snapchat', 'x', 'linkedin']) {
      await expect(page.getByTestId(`platform-${key}`)).toHaveAttribute('data-state', 'coming')
    }
  })

  test('a campaign opens into its ten figures, its quality and its ads', async ({ page }) => {
    await page.goto('/campaigns')
    await page.getByTestId('campaign-card').first().click()

    await expect(page.getByTestId('campaign-detail')).toBeVisible()
    await expect(page.getByTestId('campaign-figures').locator('[data-testid^="cf-"]')).toHaveCount(10)
    await expect(page.getByTestId('campaign-detail-funnel')).toBeVisible()
    await expect(page.getByTestId('campaign-quality-donut')).toBeVisible()
  })

  test('a change is filed for approval, and a campaign the platform does not know cannot be touched', async ({ page }) => {
    await page.goto('/campaigns')
    await page.getByTestId('campaign-card').first().click()

    await page.getByTestId('campaign-budget').click()
    await page.getByTestId('proposal-new-budget').fill('500')
    await page.getByTestId('proposal-reason').fill('اختبار من المتصفح')
    await page.getByTestId('proposal-submit').click()

    // Known only from its leads: the gateway records the advice and refuses to run it.
    const card = page.getByTestId('proposal-card').first()
    await expect(card).toHaveAttribute('data-status', 'blocked')
    await expect(card.getByTestId('proposal-blocks')).toContainText('ليست مربوطة بالمنصة')
    await expect(card.getByTestId('proposal-approve')).toHaveCount(0)

    await card.getByTestId('proposal-reject').click()
    await expect(page.getByTestId('proposal-card').first()).toHaveAttribute('data-status', 'rejected')
  })

  test('client rules are saved and read back', async ({ page }) => {
    await page.goto('/campaigns')
    await page.getByTestId('open-client-rules').click()

    await page.getByTestId('rule-maxChange').fill('15')
    await page.getByTestId('rule-forbidden').fill('مضمون، مجاني')
    await page.getByTestId('rules-save').click()
    await expect(page.getByTestId('client-rules-drawer')).toBeHidden()

    await page.getByTestId('open-client-rules').click()
    await expect(page.getByTestId('rule-maxChange')).toHaveValue('15')
    await expect(page.getByTestId('rule-forbidden')).toHaveValue('مضمون, مجاني')
  })

  test('a sales rep is not offered campaigns at all', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/')
    await expect(page.locator('a[href="/campaigns"]')).toHaveCount(0)
  })
})
