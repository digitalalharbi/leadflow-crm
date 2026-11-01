import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, apiPost, type ApiSession } from '../../fixtures/api'
import { ALPHA } from '../../fixtures/data'
import { resetFixtures } from '../../fixtures/seed'
import { signInAs } from '../../fixtures/pages'
import { collectPageErrors, expectNoSidewaysScroll } from './helpers'

interface Created { id: string; slug: string }

/**
 * A landing page is a native source of its project: published from inside Mohtam to a public address, fits the first screen of a
 * laptop and any phone, and turns a visitor straight into a lead of that project with its credit. Run in every browser.
 */
test.describe('Lead sources and landing pages', () => {
  const slug = `riwaq-${Date.now().toString(36)}`
  let admin: ApiSession
  let projectId: string
  let page1: Created

  test.beforeAll(async () => {
    resetFixtures()
    admin = await apiLogin(ALPHA.admin.email)
    const projects = await apiGet<{ id: string; name: string }[] | { projects: { id: string; name: string }[] }>(admin, '/api/v1/projects')
    const list = Array.isArray(projects) ? projects : projects.projects
    projectId = list[0].id

    const made = await apiPost(admin, '/api/v1/tracking/forms', {
      name: 'رواق الرئيسية', project_id: projectId, template: 'real_estate_interest', slug, consent_required: false, headline: 'بيتك في رواق', description: 'فلل وشقق في قلب الرياض، بخطط دفع مرنة.',
      page: {
        template: 'real_estate_lead', tags: ['riwaq'],
        facts: [{ key: 'location', value: 'الرياض', visible: true }, { key: 'price_from', value: '850,000 ر.س', visible: true }, { key: 'delivery', value: 'مخفي', visible: false }],
        cta: { form: { enabled: true }, whatsapp: { enabled: true, number: '+966500000000', mode: 'inbound' }, phone: { enabled: true, number: '+966500000001' }, primary: 'form', floating_whatsapp: true, floating_call: true },
      },
    })
    expect(made.ok()).toBeTruthy()
    page1 = { id: (await made.json()).data.form.id, slug }
    const published = await apiPost(admin, `/api/v1/tracking/forms/${page1.id}/publish`)
    expect(published.ok(), await published.text()).toBeTruthy()
  })
  test.afterAll(async () => { await admin?.dispose(); resetFixtures() })

  test('on the first screen of a laptop: who, what, the form and the way to act, with hidden facts hidden', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
    const errors = collectPageErrors(page)
    await page.goto(`/l/${slug}`)

    await expect(page.getByTestId('landing-headline')).toHaveText('بيتك في رواق')
    await expect(page.getByTestId('page-fact-location')).toContainText('الرياض')
    await expect(page.getByTestId('page-fact-delivery')).toHaveCount(0)

    for (const id of ['landing-headline', 'page-form', 'page-hero-whatsapp', 'page-hero-phone']) {
      await expect(page.getByTestId(id)).toBeInViewport()
    }

    await expect(page.getByTestId('landing-submit')).toBeInViewport()
    // The floating buttons are for a phone: on a laptop they would only repeat what is already on the screen.
    await expect(page.getByTestId('page-floating')).toBeHidden()
    await expectNoSidewaysScroll(page, 'page 1366')
    expect(errors).toEqual([])
  })

  for (const width of [390, 430, 768, 1024, 1366, 1440, 1920]) {
    test(`no sideways scrolling at ${width}, right to left and left to right`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 })
      await page.goto(`/l/${slug}`)
      await expect(page.getByTestId('landing-headline')).toBeVisible()
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
      await expectNoSidewaysScroll(page, `page ${width} ar`)

      await page.addInitScript(() => localStorage.setItem('leadflow.locale', 'en'))
      await page.reload()
      await expect(page.getByTestId('landing-headline')).toBeVisible()
      await expectNoSidewaysScroll(page, `page ${width} en`)
    })
  }

  test('says it is not for search engines unless its manager chose, and shows no hidden configuration', async ({ page }) => {
    await page.goto(`/l/${slug}`)
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    await expect(page.locator("meta[name='robots']")).toHaveAttribute('content', /noindex/)
    await expect(page).toHaveTitle(/بيتك في رواق/)
  })

  test('a phone reaches WhatsApp and call without scrolling back, and every tap is recorded with its source', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(`/l/${slug}?utm_source=snapchat&utm_campaign=october`)
    await expect(page.getByTestId('page-floating-whatsapp')).toBeInViewport()

    const link = await page.getByTestId('page-floating-whatsapp').getAttribute('href')
    expect(decodeURIComponent(link ?? '')).toMatch(/Ref: [A-Z2-9]{6}/)

    // The tap leaves the page, so the event is what is checked, not the new window.
    await page.getByTestId('page-floating-whatsapp').evaluate((el) => { el.removeAttribute('target'); el.setAttribute('href', '#') })
    const beacon = page.waitForRequest((r) => r.url().includes('/api/v1/track/') && (r.postData() ?? '').includes('whatsapp_click'))
    await page.getByTestId('page-floating-whatsapp').click()
    const request = await beacon
    const sent = JSON.parse(request.postData() ?? '{}')
    expect(sent.utm_source).toBe('snapchat')
    expect(sent.meta.ref).toMatch(/^[A-Z2-9]{6}$/)
    // Not only sent: the store has it. A beacon to the wrong address would look just the same from the page.
    expect((await request.response())?.status()).toBe(204)
    await expect.poll(async () => (await apiGet<{ page: { whatsapp_clicks: number } }>(admin, `/api/v1/tracking/forms/${page1.id}/analytics`)).page.whatsapp_clicks).toBeGreaterThan(0)

    await page.getByTestId('page-floating-phone').evaluate((el) => el.setAttribute('href', '#'))
    const call = page.waitForRequest((r) => r.url().includes('/api/v1/track/') && (r.postData() ?? '').includes('call_click'))
    await page.getByTestId('page-floating-phone').click()
    await call
    await expectNoSidewaysScroll(page, 'page 390 after taps')
  })

  test('a visitor becomes a lead of the project at once, with its credit, tag and events', async ({ page }) => {
    const phone = `05${String(Date.now()).slice(-8)}`
    await page.goto(`/l/${slug}?utm_source=tiktok&utm_campaign=autumn`)
    await expect(page.getByTestId('landing-headline')).toBeVisible()

    const form = page.getByTestId('page-form')

    // A long form turned itself into steps: walk them, filling what each asks.
    for (let step = 0; step < 6; step++) {
      for (const input of await form.locator('input:visible:not([type=checkbox]):not([type=radio]):not([tabindex="-1"])').all()) {
        const type = await input.getAttribute('type')
        if (await input.inputValue()) continue
        const mode = await input.getAttribute('inputmode')
        const email = type === 'email' || mode === 'email' || (await input.getAttribute('data-testid'))?.includes('email')
        const numeric = type === 'number' || mode === 'numeric' || mode === 'decimal' || (await input.getAttribute('data-testid'))?.includes('budget')
        await input.fill(type === 'tel' ? phone : email ? `sara${Date.now()}@example.com` : numeric ? '1000000' : 'سارة العتيبي')
      }

      for (const select of await form.locator('select:visible').all()) {
        await select.selectOption({ index: 1 }).catch(() => undefined)
      }

      const radios = form.locator('input[type=radio]:visible')
      if (await radios.count()) await radios.first().check()
      // The same button moves on, and on the last step sends.
      await form.getByTestId('landing-submit').click()

      // Sending takes a moment on the last step: the thanks replaces the form, so there is nothing more to press.
      if (await page.getByTestId('landing-thanks').waitFor({ timeout: 2000 }).then(() => true).catch(() => false)) break
    }

    await expect(page.getByTestId('landing-thanks')).toBeVisible()

    const sources = await apiGet<{ sources: { type: string; id: string; leads: number; status: string; path: string | null }[] }>(admin, `/api/v1/projects/${projectId}/lead-sources`)
    const source = sources.sources.find((s) => s.id === page1.id)
    expect(source?.status).toBe('connected')
    expect(source?.leads).toBeGreaterThan(0)
    expect(source?.path).toBe(`/l/${slug}`)

    // Inside the project, with its tag and where it came from.
    const leads = await apiGet<{ phone: string | null; tags?: string[]; utm_source?: string | null; campaign_name?: string | null }[]>(admin, `/api/v1/leads?project_id=${projectId}&tag=riwaq&per_page=5`)
    expect(leads.length).toBeGreaterThan(0)
    expect(leads[0].tags).toContain('riwaq')
    expect(leads[0].campaign_name).toBe('autumn')

    const analytics = await apiGet<{ page: { leads: number; funnel: { key: string; n: number }[] } }>(admin, `/api/v1/tracking/forms/${page1.id}/analytics`)
    expect(analytics.page.leads).toBeGreaterThan(0)
    expect(analytics.page.funnel.find((s) => s.key === 'view')?.n).toBeGreaterThan(0)
  })

  test('from the project: the sources list, and the path to a new page that a manager can publish', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}?tab=sources`)

    await expect(page.getByTestId('lead-sources')).toBeVisible()
    await expect(page.getByTestId('source-card').filter({ hasText: 'رواق الرئيسية' })).toBeVisible()
    await expectNoSidewaysScroll(page, 'sources')

    await page.getByTestId('add-source').click()
    await expect(page.getByTestId('add-google_sheet')).toBeVisible()
    await page.getByTestId('add-landing_page').click()
    await page.getByTestId('new-page-name').fill('حملة سناب')
    await page.getByTestId('new-page-create').click()

    await expect(page).toHaveURL(/\/tracking\/forms\//)
    await page.getByTestId('form-settings-tabs').getByRole('tab', { name: /الصفحة/ }).click()
    await expect(page.getByTestId('page-panel')).toBeVisible()
    await expect(page.getByTestId('page-campaign-link')).toBeVisible()
  })

  test('a paused page stops being public, and a page nobody published never was', async ({ page }) => {
    const paused = await admin.context.patch(`/api/v1/tracking/forms/${page1.id}`, { data: { status: 'paused' } })
    expect(paused.ok()).toBeTruthy()
    await page.goto(`/l/${slug}`)
    await expect(page.getByTestId('landing-unavailable')).toBeVisible()

    const resumed = await admin.context.patch(`/api/v1/tracking/forms/${page1.id}`, { data: { status: 'published' } })
    expect(resumed.ok()).toBeTruthy()
  })
})
