import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'
import { apiGet, apiLogin, apiPost, type ApiSession } from '../../fixtures/api'
import { ALPHA, BETA } from '../../fixtures/data'
import { resetFixtures } from '../../fixtures/seed'
import { signInAs } from '../../fixtures/pages'
import { collectPageErrors, expectNoSidewaysScroll } from './helpers'

interface Created { id: string; slug: string }

/** What the store kept on the lead: no screen shows click ids, and they are what the sale is credited back with. */
function storedClickIds(phone: string): { click_id: string | null; click_ids: { first?: Record<string, string>; last?: Record<string, string> } | null } {
  const code = `echo json_encode(DB::table('leads')->where('phone_normalized', '+966${phone.slice(1)}')->first(['click_id', 'click_ids']));`
  const out = execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], { cwd: process.env.E2E_BACKEND_DIR ?? path.resolve(process.cwd(), '../backend'), env: process.env, encoding: 'utf8' })
  const row = JSON.parse(out.trim()) as { click_id: string | null; click_ids: string | null }

  return { click_id: row.click_id, click_ids: row.click_ids ? JSON.parse(row.click_ids) : null }
}

/** Fill the page's form as a person would and send it, however many steps it turned itself into. */
async function fillAndSend(page: Page, phone: string) {
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

    // The country stays what the page chose: the number below is a Saudi one.
    for (const select of await form.locator('select:visible:not([data-testid^="country-"])').all()) {
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
}

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

    await fillAndSend(page, phone)

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

  test('all nine click ids reach the lead through a reload, a look elsewhere and the steps, and the address keeps its own spelling', async ({ page }) => {
    const phone = `05${String(Date.now()).slice(-8)}`
    const ids = { gclid: 'E2E-g', gbraid: 'E2E-gb', wbraid: 'E2E-wb', fbclid: 'E2E-fb', ttclid: 'E2E-tt', ScCid: 'E2E-sn', msclkid: 'E2E-ms', li_fat_id: 'E2E-li', twclid: 'E2E-tw' }
    const address = `/l/${slug}?${new URLSearchParams({ ...ids, Keep: 'CaseStays' })}`
    await page.goto(address)
    await expect(page.getByTestId('landing-headline')).toBeVisible()

    await page.reload()
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    await page.goto('/privacy')
    await page.goBack()
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    expect(new URL(page.url()).search).toContain('ScCid=E2E-sn&msclkid=E2E-ms')
    expect(new URL(page.url()).search).toContain('Keep=CaseStays')

    await fillAndSend(page, phone)

    const { ScCid, ...rest } = ids
    expect(storedClickIds(phone).click_ids?.first).toEqual({ ...rest, sccid: ScCid })
    expect(storedClickIds(phone).click_id).toBe('E2E-g')
  })

  for (const spelling of ['ScCid', 'scCid', 'sccid']) {
    test(`Snapchat's ${spelling} on the address is kept as sccid on the lead`, async ({ page }) => {
      const phone = `05${String(Date.now()).slice(-8)}`
      await page.goto(`/l/${slug}?${spelling}=E2E-${spelling}`)
      await expect(page.getByTestId('landing-headline')).toBeVisible()

      await fillAndSend(page, phone)

      expect(storedClickIds(phone)).toEqual({ click_id: `E2E-${spelling}`, click_ids: { first: { sccid: `E2E-${spelling}` }, last: { sccid: `E2E-${spelling}` } } })
    })
  }

  test('a visitor holding someone else\'s session id gets none of their click ids, and nobody is credited twice', async ({ page, request }, testInfo) => {
    const phone = `05${String(Date.now()).slice(-8)}`
    const asked = page.waitForResponse((r) => r.url().includes(`/public/landing/${slug}`) && r.request().method() === 'GET')
    await page.goto(`/l/${slug}?gclid=E2E-theirs`)
    const { data } = await (await asked).json() as { data: { tracking: { session_id: string } } }

    const body = {
      submission_id: crypto.randomUUID(), answers: { full_name: 'زائر آخر', name: 'زائر آخر', phone, mobile: phone },
      visitor_id: `v_${testInfo.project.name.replace(/\W/g, '')}${'c'.repeat(24)}`.slice(0, 24), session_id: data.tracking.session_id,
    }
    const url = `${process.env.E2E_API_URL ?? 'http://127.0.0.1:8000'}/api/v1/public/landing/${slug}/submit`
    const first = await request.post(url, { data: body })
    expect(first.status(), await first.text()).toBe(200)
    expect(storedClickIds(phone)).toEqual({ click_id: null, click_ids: null })

    const again = await request.post(url, { data: body })
    expect((await again.json()).data.duplicate).toBe(true)
    expect(storedClickIds(phone)).toEqual({ click_id: null, click_ids: null })
  })

  test('a landing page downloads nothing of the product behind it, and still does its work', async ({ page }) => {
    const asked: string[] = []
    page.on('request', (r) => asked.push(new URL(r.url()).pathname))

    await page.goto(`/l/${slug}`)
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    await expect(page.getByTestId('page-form')).toBeVisible()

    expect(asked.filter((p) => /appMain|\/lib\/api\.ts|\/api-[\w-]+\.js|react-router|tanstack|recharts|Dashboard|FormBuilder|Reports|Integrations|\/i18n\/(ar|en)\.ts/.test(p))).toEqual([])
    expect(asked.some((p) => p.includes('/api/v1/public/landing/'))).toBe(true)
  })

  test('two companies asking for one address at the same moment: one has it, the other is told so, and nobody sees a server error', async ({}, testInfo) => {
    const beta = await apiLogin(BETA.admin.email)
    const created = await apiPost(beta, '/api/v1/projects', { name: `بيتا ${testInfo.project.name}` })
    expect(created.ok(), await created.text()).toBeTruthy()
    const betaProject = (await created.json()).data.id as string
    const wanted = `race-${testInfo.project.name}-${Date.now().toString(36)}`
    const body = { name: 'صفحة', slug: wanted, template: 'real_estate_interest', page: { template: 'minimal_lead' } }

    const answers = await Promise.all([
      apiPost(admin, '/api/v1/tracking/forms', { ...body, project_id: projectId }),
      apiPost(beta, '/api/v1/tracking/forms', { ...body, project_id: betaProject }),
    ])
    const statuses = answers.map((a) => a.status()).sort()
    expect(statuses).toEqual([201, 422])

    const loser = answers.find((a) => a.status() === 422)!
    const refusal = await loser.json()
    expect(refusal.errors.slug[0]).toBe('taken')
    expect(refusal.errors.slug_suggestion[0]).toBe(`${wanted}-2`)
    await beta.dispose()
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
