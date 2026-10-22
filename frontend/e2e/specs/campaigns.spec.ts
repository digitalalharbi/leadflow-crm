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

  test('says which platforms are wired, and never calls an unconnected one connected', async ({ page }) => {
    await page.goto('/campaigns')

    // Every platform has an adapter now; none is connected in this workspace.
    for (const key of ['meta', 'tiktok', 'google_ads', 'snapchat', 'x', 'linkedin']) {
      await expect(page.getByTestId(`platform-${key}`)).toHaveAttribute('data-state', 'available')
    }
  })

  test('integrations show each ad platform with its proven state and what it can do', async ({ page }) => {
    await page.goto('/integrations')
    await page.getByTestId('integrations-tabs').getByRole('tab', { name: 'حسابات الإعلانات' }).click()
    const panel = page.getByTestId('ad-connections')

    for (const key of ['meta', 'tiktok', 'google_ads', 'snapchat', 'x', 'linkedin']) {
      await expect(panel.getByTestId(`ad-connection-${key}`).getByTestId('ladder')).toHaveAttribute('data-state', 'not_configured')
    }

    // TikTok cannot copy an ad group; the card says so instead of offering it.
    await expect(panel.getByTestId('ad-connection-tiktok').locator('[data-supported="no"]').first()).toBeVisible()

    // The connect drawer asks for exactly what the platform needs. (Saving is
    // not exercised here: it tests the connection against the real platform.)
    await panel.getByTestId('connect-x').click()
    for (const key of ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret']) {
      await expect(page.getByTestId(`field-${key}`)).toBeVisible()
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

  test('the agents can be asked to analyse now, and say what they saw', async ({ page }) => {
    await page.goto('/campaigns')
    const panel = page.getByTestId('agents-panel')

    for (const key of ['orchestrator', 'analyst', 'campaign']) {
      await expect(panel.getByTestId(`agent-${key}`)).toBeVisible()
    }

    await panel.getByTestId('run-analysis').click()
    // Without spend nothing can be judged, and the panel says so rather than inventing findings.
    await expect(panel).toContainText('لا شيء يستدعي الانتباه')
    await expect(panel.getByTestId('agent-analyst')).not.toContainText('لم يعمل بعد')
  })

  test('reports join marketing and sales, by platform, project and company', async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('tab-marketing').click()

    const tab = page.getByTestId('marketing-sales')
    await expect(tab.getByTestId('marketing-group-card').first()).toBeVisible()
    const platforms = await tab.getByTestId('marketing-group-card').count()
    expect(platforms).toBeGreaterThan(1)

    await tab.getByTestId('marketing-group').getByRole('tab', { name: 'حسب المشروع' }).click()
    await expect(tab.getByTestId('marketing-group-card').first()).toBeVisible()
  })

  test('the reports agent writes a weekly report that says where its numbers came from', async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('tab-weekly').click()
    await page.getByTestId('generate-report').click()

    await page.getByTestId('weekly-report-card').first().click()
    const drawer = page.getByTestId('weekly-report-drawer')
    await expect(drawer.getByTestId('report-notes')).toContainText('الفواتير الرسمية هي المرجع')
  })

  test('a project opens into its own campaigns, priced and qualified', async ({ page }) => {
    await page.goto('/projects')
    await page.locator('a[href^="/projects/"]').first().click()
    await page.getByTestId('tab-campaigns').click()

    const cards = page.getByTestId('project-campaign-cards').getByTestId('campaign-card')
    await expect(cards.first()).toBeVisible()

    // Same campaign, same screen: the card leads into the campaign itself.
    await cards.first().click()
    await expect(page.getByTestId('campaign-detail')).toBeVisible()
  })

  test('the sales-quality report drills from platform to creative', async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('tab-quality').click()
    const report = page.getByTestId('quality-report')
    await expect(report.getByTestId('quality-table').locator('tbody tr').first()).toBeVisible()

    await report.getByTestId('quality-level').getByRole('tab', { name: 'الحملة' }).click()
    await expect(report.getByTestId('quality-table').locator('tbody tr').first()).toBeVisible()
  })

  test('the ad accounts view says whether results reach each platform', async ({ page }) => {
    await page.goto('/integrations')
    await page.getByTestId('integrations-tabs').getByRole('tab', { name: 'حسابات الإعلانات' }).click()
    for (const key of ['meta', 'tiktok', 'google_ads', 'snapchat', 'linkedin', 'x']) {
      await expect(page.getByTestId(`conversion-${key}`)).toBeVisible()
    }
  })

  test('a sales rep is not offered campaigns at all', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/')
    await expect(page.locator('a[href="/campaigns"]')).toHaveCount(0)
  })
})
