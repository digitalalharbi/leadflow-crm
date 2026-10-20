import { request as pwRequest, expect, test } from '@playwright/test'
import { API_URL, apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Is tracking working: eight platforms, each judged on what it delivered, and what to do about it. */
test.describe('Tracking health center', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  async function openHealth(page: import('@playwright/test').Page) {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByTestId('tracking-tabs').getByRole('tab', { name: 'الصحة' }).click()

    return page.getByTestId('health-panel')
  }

  test('the eight platforms are each a card with a status, a ring, a last event and a trend', async ({ page }) => {
    const panel = await openHealth(page)

    for (const platform of ['meta', 'tiktok', 'snapchat', 'google_ads', 'x', 'linkedin', 'whatsapp', 'landing_page']) {
      const card = panel.getByTestId(`health-${platform}`)
      await expect(card, platform).toBeVisible()
      await expect(card.getByTestId(`health-${platform}-status`)).toHaveText(/سليم|يحتاج انتباه|في خطر|غير متصل/)
      await expect(card.getByTestId(`health-${platform}-ring`)).toBeVisible()
      await expect(card.getByTestId(`health-${platform}-last`)).toHaveText(/\S/)
      await expect(card.getByTestId(`health-${platform}-trend`)).toBeVisible()
    }

    for (const stat of ['hs-connected', 'hs-receiving', 'hs-risk', 'hs-unattributed']) {
      await expect(panel.getByTestId(`${stat}-value`)).toHaveText(/\S/)
    }
  })

  test('a landing page that reports events becomes a receiving platform', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    let token = ''
    try {
      const made = await apiPost(admin, '/api/v1/tracking/links', { name: 'صفحة هبوط الصحة', channel: 'landing_page', destination_url: 'https://landing.example.test/h' })
      expect(made.ok(), await made.text()).toBeTruthy()
      token = (await made.json()).data.link.token
    } finally {
      await admin.dispose()
    }

    const api = await pwRequest.newContext({ baseURL: API_URL })
    for (const event of ['visit', 'form_start', 'form_submit']) {
      expect((await api.post(`/api/v1/track/${token}`, { data: { event } })).status()).toBe(204)
    }
    await api.dispose()

    const panel = await openHealth(page)
    const card = panel.getByTestId('health-landing_page')

    // At least the three the page just reported (the workspace may hold landing-page leads of its own too).
    await expect.poll(async () => Number.parseInt((await card.getByTestId('health-landing_page-events').innerText()).replace(/\D/g, '') || '0', 10)).toBeGreaterThanOrEqual(3)
    await expect(card).toHaveAttribute('data-status', /healthy|attention/)
    await expect(card.getByTestId('health-landing_page-last')).not.toContainText('لم يصل')
    await expect(panel.getByTestId('hs-receiving-value')).not.toHaveText(/^0\//)
  })

  test('what is wrong is said in a sentence with a number in it', async ({ page }) => {
    const panel = await openHealth(page)
    const recs = panel.getByTestId('health-recommendations')

    // The fixtures hold leads with no campaign and no UTM: the centre says how many.
    await expect(recs).toBeVisible()
    await expect(recs.locator('li').first()).toContainText(/\d/)
    await expect(recs.locator('li[data-severity]').first()).toBeVisible()
  })

  test('the period can be changed', async ({ page }) => {
    const panel = await openHealth(page)

    const asked = page.waitForResponse((r) => r.url().includes('/tracking/health') && r.url().includes('days=30'))
    await panel.getByTestId('health-days-30').click()
    const body = await (await asked).json()

    expect(body.data.days).toBe(30)
    await expect(panel.getByTestId('health-days-30')).toHaveAttribute('aria-pressed', 'true')
    expect(body.data.platforms[0].trend).toHaveLength(30)
  })

  test('the attribution tab is what a recommendation about attribution points to', async ({ page }) => {
    const panel = await openHealth(page)
    const go = panel.getByTestId('health-recommendations').locator('li[data-key="unattributed"] button')

    if ((await go.count()) > 0) {
      await go.click()
      await expect(page.getByTestId('attribution-panel')).toBeVisible()
    } else {
      // Nothing to point at is also a valid state: every lead is attributed.
      await expect(panel.getByTestId('hs-unattributed-value')).toHaveText(/^0%|—/)
    }
  })
})
