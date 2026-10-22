import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** Brokers, influencers and the codes they hand out — and what a broker's own login may see. */
test.describe('Partners and QR codes', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('a partner is added, given a link and a QR code, and the code is an image to download or print', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/partners')

    await page.getByTestId('new-partner').click()
    await page.getByTestId('partner-name').fill('وسيط الرياض')
    await page.getByTestId('partner-commission').fill('2.5')
    await page.getByTestId('save-partner').click()

    const card = page.locator('li[data-testid^="partner-"]').filter({ hasText: 'وسيط الرياض' })
    await expect(card).toBeVisible()
    await expect(card.getByTestId('partner-commission-value')).toBeVisible()

    // A link for this partner, printed as a QR code on a billboard.
    await card.getByTestId('partner-new-link').click()
    await expect(page.getByTestId('link-partner')).toHaveValue(/.+/)
    await page.getByTestId('link-kind').selectOption('qr')
    await page.getByTestId('link-placement').selectOption('billboard')
    await page.getByTestId('save-link').click()

    await page.goto('/tracking')
    const link = page.locator('[data-testid^="link-"]').filter({ hasText: 'وسيط الرياض' }).first()
    await expect(link).toBeVisible()
    await link.locator('[data-testid^="qr-link-"]').click()

    const image = page.getByTestId('qr-image')
    await expect(image).toBeVisible()
    await expect(image).toHaveAttribute('src', /^data:image\/svg\+xml/)
    await expect(page.getByTestId('qr-download')).toHaveAttribute('download', /\.svg$/)
    await expect(page.getByTestId('qr-print')).toBeEnabled()
  })

  test('a broker login sees only its partner’s leads and links, no money and no way to anything else', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    let partnerId = ''

    try {
      const partner = await apiPost(admin, '/api/v1/partners', { name: 'وسيط الدخول', type: 'broker', commission_percent: 3 })
      partnerId = (await partner.json()).data.partner.id as string
      const link = await apiPost(admin, '/api/v1/tracking/links', { name: 'رابط وسيط الدخول', channel: 'broker', partner_id: partnerId })
      expect(link.status()).toBe(201)

      // Two leads of the broker's own project came through this partner.
      artisan(['tinker', '--execute', `
        $p = \\App\\Models\\Partner::withoutGlobalScopes()->where('uuid', '${partnerId}')->firstOrFail();
        $project = \\App\\Models\\Project::withoutGlobalScopes()->where('name', '${ALPHA.projects[1]}')->firstOrFail();
        \\App\\Models\\Lead::withoutGlobalScopes()->where('project_id', $project->id)->orderBy('id')->limit(2)->update(['partner_id' => $p->id]);
        \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.broker.email}')->update(['partner_id' => $p->id]);
      `])
      // Not that they were "assigned" anything: the project's other leads belong to nobody this broker may see.
    } finally {
      await admin.dispose()
    }

    const session = await apiLogin(ALPHA.broker.email)
    try {
      const leads = await apiGet<{ id: string }[]>(session, '/api/v1/leads')
      expect(leads).toHaveLength(2)
      const links = await apiGet<{ links: { name: string }[] }>(session, '/api/v1/tracking/links')
      expect(links.links.map((l) => l.name)).toEqual(['رابط وسيط الدخول'])

      for (const path of ['/api/v1/partners', '/api/v1/tracking/health', '/api/v1/campaigns', '/api/v1/users']) {
        expect((await session.context.get(path)).status(), path).toBe(403)
      }
    } finally {
      await session.dispose()
    }

    await authenticateAs(page, ALPHA.broker.email)
    await page.goto('/tracking')
    await expect(page.getByTestId('link-list')).toContainText('رابط وسيط الدخول')
    await expect(page.getByTestId('tk-revenue')).toHaveCount(0)
    // Analyses are not theirs: their links, and the codes printed from them — never health or attribution.
    await expect(page.getByTestId('tracking-tabs').getByRole('tab')).toHaveText(['الروابط', 'مركز QR'])
  })

  test('a partner’s shared report carries their leads and never the company’s money', async ({ browser }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    let url = ''

    try {
      const partner = await (await apiPost(admin, '/api/v1/partners', { name: 'وسيط التقرير', type: 'influencer' })).json()
      const share = await apiPost(admin, '/api/v1/report-shares', {
        name: 'تقرير الشريك', template: 'partner', partner_id: partner.data.partner.id,
        sections: { kpi_volume: true, kpi_cost: true, kpi_revenue: true, funnel: true, campaigns: true, team: true },
      })
      expect(share.status()).toBe(201)
      url = (await share.json()).data.share.url as string
    } finally {
      await admin.dispose()
    }

    const context = await browser.newContext()
    const open = await context.newPage()
    await open.goto(`/reports/share/${url.split('/').pop()}`)
    await expect(open.getByTestId('shared-title')).toHaveText('تقرير الشريك')
    for (const hidden of ['sk-spend', 'sk-cpl', 'sk-cac', 'sk-roas', 'sk-revenue', 'shared-team', 'shared-campaigns']) await expect(open.getByTestId(hidden)).toHaveCount(0)
    await expect(open.getByTestId('sk-leads')).toBeVisible()
    await context.close()
  })
})
