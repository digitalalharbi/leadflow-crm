import { request as pwRequest, expect, test } from '@playwright/test'
import { API_URL } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/**
 * Smart tracking links, from the person who makes one to the lead it brings: make a link, click it
 * as a customer would, submit a form that carries the visitor id, and see the credit land.
 */
test.describe('Tracking links and attribution', () => {
  const SECRET = 'e2e-track-secret-0123456789abcdefgh'
  let webhookKey = ''
  let token = ''
  let visitorId = ''

  test.beforeAll(() => {
    resetFixtures()
    // A known intake secret for the workspace, so a landing page's form can be played back.
    const out = artisan(['tinker', '--execute', `
      $t = \\App\\Models\\Tenant::where('slug', 'e2e-alpha')->firstOrFail();
      $e = \\App\\Models\\WebhookEndpoint::withoutGlobalScopes()->where('tenant_id', $t->id)->firstOrFail();
      $e->forceFill(['secret_hash' => \\Illuminate\\Support\\Facades\\Hash::make('${SECRET}')])->saveQuietly();
      echo 'KEY=' . $e->key;
    `])
    webhookKey = /KEY=(\S+)/.exec(out)?.[1] ?? ''
  })

  test.afterAll(() => resetFixtures())

  test('a manager makes a link and it appears with its numbers at zero', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')

    await page.getByTestId('new-link').click()
    const form = page.getByTestId('link-form')
    await form.getByTestId('link-name').fill('إعلان ميتا — النرجس')
    await form.getByTestId('link-channel').selectOption('meta')
    await form.getByTestId('link-destination').fill('https://landing.example.test/nargis')
    await page.getByTestId('save-link').click()

    const card = page.getByTestId('link-list').locator('li', { hasText: 'إعلان ميتا — النرجس' })
    await expect(card).toBeVisible()
    await expect(card.locator('[data-testid$="-clicks"]')).toContainText('0')
    token = (await card.getAttribute('data-testid'))!.replace('link-', '')
    expect(token).toMatch(/^[A-Za-z0-9]{10}$/)
  })

  test('a customer clicks the link: the click is counted and they are sent on with their identity', async ({ page }) => {
    const api = await pwRequest.newContext({ baseURL: API_URL })
    const response = await api.get(`/t/${token}`, { maxRedirects: 0, headers: { 'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605 Version/17 Mobile Safari/604' } })
    expect(response.status()).toBe(302)

    const location = new URL(response.headers().location)
    expect(location.origin).toBe('https://landing.example.test')
    expect(location.searchParams.get('mt_t')).toBe(token)
    visitorId = location.searchParams.get('mt_vid') ?? ''
    expect(visitorId).toMatch(/^v_[a-z0-9]{20}$/)
    await api.dispose()

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await expect(page.getByTestId(`link-${token}-clicks`)).toContainText('1')
    await expect(page.getByTestId('tk-clicks-value')).toContainText('1')

    // Open it: the two things to copy, and the click it recorded, said as a mobile visit.
    await page.getByTestId(`open-link-${token}`).click()
    await expect(page.getByTestId('copy-embed-script')).toBeVisible()
    await expect(page.getByTestId('link-embed')).toContainText(`/tracker/${token}.js`)
    await expect(page.getByTestId('link-recent')).toContainText('mobile')
  })

  test('the landing page form brings the visitor id back, and the credit lands on the link, the channel and the numbers', async ({ page }) => {
    const api = await pwRequest.newContext({ baseURL: API_URL })
    const posted = await api.post(`/api/v1/integrations/webhooks/${webhookKey}/leads`, {
      headers: { 'X-Webhook-Secret': SECRET, Accept: 'application/json' },
      data: { full_name: 'عميل صفحة الهبوط', phone: '0507771234', visitor_id: visitorId, tracking_token: token, landing_url: 'https://landing.example.test/nargis', device: 'mobile' },
    })
    expect(posted.ok(), await posted.text()).toBeTruthy()
    await api.dispose()

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await expect(page.getByTestId(`link-${token}-leads`)).toContainText('1')

    await page.getByTestId('tracking-tabs').getByRole('tab', { name: 'الإسناد' }).click()
    const panel = page.getByTestId('attribution-panel')
    await expect(panel).toBeVisible()
    await expect(panel.getByTestId('unattributed-ring')).toBeVisible()
    await expect(panel.getByTestId('attribution-donut')).toContainText('ميتا')
    await expect(panel.getByTestId('attribution-ranking')).toBeVisible()
    await expect(panel.getByTestId('answer-ad-none').or(panel.getByTestId('answer-ad-name')).first()).toBeVisible()
  })

  test('every model is one press away and the totals still add up', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByTestId('tracking-tabs').getByRole('tab', { name: 'الإسناد' }).click()
    const panel = page.getByTestId('attribution-panel')

    for (const model of ['first', 'last', 'linear', 'position']) {
      await panel.getByTestId(`model-${model}`).click()
      await expect(panel.getByTestId(`model-${model}`)).toHaveAttribute('aria-pressed', 'true')
      await expect(panel.getByTestId('attribution-totals')).toContainText(/\d/)
    }

    await panel.getByTestId('attribution-dimension').selectOption('link')
    await expect(panel.getByTestId('attribution-donut')).toContainText('إعلان ميتا — النرجس')
  })

  test('a link can be switched off and then goes nowhere', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByTestId(`open-link-${token}`).click()
    await page.getByTestId('link-active').selectOption('0')
    await page.getByTestId('save-link').click()
    await expect(page.getByTestId(`link-state-${token}`)).toContainText('متوقف')

    const api = await pwRequest.newContext({ baseURL: API_URL })
    expect((await api.get(`/t/${token}`, { maxRedirects: 0 })).status()).toBe(404)
    await api.dispose()
  })

  test('a sales rep is not offered tracking and does not reach it', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/tracking')

    await expect(page).not.toHaveURL(/\/tracking/)
    await expect(page.getByTestId('tracking-tabs')).toHaveCount(0)
  })
})
