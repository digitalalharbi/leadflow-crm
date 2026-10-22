import { expect, test, type Page } from '@playwright/test'
import { apiGet, apiLogin, apiPost, type ApiSession } from '../../fixtures/api'
import { ALPHA, PASSWORD } from '../../fixtures/data'
import { authenticateAs } from '../../fixtures/pages'
import { artisan, resetFixtures } from '../../fixtures/seed'
import { expectNoSidewaysScroll } from './helpers'

/**
 * The phone's sales flow, followed the way a rep works it: open the work screen, open the lead, read the
 * conversation, call, say how it went, and be on the next lead. Nothing here is mocked except where a case
 * is about the number or the connection itself (an unusable number, a workspace that sends WhatsApp from
 * inside the product, no network): the events happen on the API and are read back from it.
 *
 * Runs on the phone projects only (iPhone and Android profiles); the laptop keeps its own lead screen.
 */
test.describe('Mobile sales flow', () => {
  test.skip(({ isMobile }) => !isMobile, 'the sales screens are the phone’s; the laptop has its own')
  test.beforeEach(() => resetFixtures())

  /** A phone dialler is not under test, only the request to open it: stop the page navigating to `tel:` so the run stays in the app. */
  async function holdTheDialler(page: Page) {
    await page.addInitScript(() => {
      document.addEventListener('click', (event) => {
        const link = (event.target as HTMLElement | null)?.closest?.('a[href^="tel:"]')
        if (link) event.preventDefault()
      }, true)
    })
  }

  async function asRep(page: Page) {
    await holdTheDialler(page)
    await authenticateAs(page, ALPHA.rep1.email)
  }

  /** The lead the work queue puts first for rep one, read from the API so the test knows who the screen should show. */
  async function firstInQueue() {
    const rep = await apiLogin(ALPHA.rep1.email)

    try {
      const next = await apiGet<{ lead: { id: string; full_name: string; phone: string; whatsapp: string | null } }>(rep, '/api/v1/work/next')

      return { id: next.lead.id, name: next.lead.full_name, phone: next.lead.phone, whatsapp: next.lead.whatsapp }
    } finally {
      await rep.dispose()
    }
  }

  async function leadFacts(id: string) {
    const rep = await apiLogin(ALPHA.rep1.email)

    try {
      return await apiGet<{ calls: { status: string; user?: unknown }[]; activities: { type: string }[]; lead: { status: string } }>(rep, `/api/v1/leads/${id}`)
    } finally {
      await rep.dispose()
    }
  }

  test('Work Now shows one lead: who, how interested, which project, how long, why now and what next — and nothing of the laptop board', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto('/work')

    await expect(page.getByTestId('mobile-work')).toBeVisible()
    await expect(page.getByTestId('work-lead-name')).toHaveText(first.name)
    await expect(page.getByTestId('mobile-waiting')).toContainText('ينتظر منذ')
    await expect(page.getByTestId('work-reason')).toContainText('لماذا الآن')
    await expect(page.getByTestId('mobile-next-action')).toBeVisible()
    await expect(page.getByTestId('work-open-lead')).toBeVisible()

    // The queue list, the pulse and the timeline are the laptop's; a phone shows one lead.
    await expect(page.locator('[data-testid^="queue-item-"]')).toHaveCount(0)
    await expect(page.getByTestId('sales-bar')).toBeVisible()
    await expectNoSidewaysScroll(page, 'work now')
  })

  test('the lead opens from Work Now with its header, four large buttons, the short read and the conversation as the biggest part', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto('/work')
    await page.getByTestId('work-open-lead').click()

    await expect(page).toHaveURL(new RegExp(`/leads/${first.id}`))
    await expect(page.getByTestId('mobile-lead')).toBeVisible()
    await expect(page.getByTestId('mobile-lead-name')).toHaveText(first.name)
    await expect(page.getByTestId('mobile-lead-badges')).toBeVisible()
    await expect(page.getByTestId('mobile-why-now')).toContainText('لماذا الآن')

    // The four ways to reach them, thumb-sized.
    for (const id of ['quick-whatsapp', 'quick-call', 'quick-message', 'quick-appointment']) {
      const box = (await page.getByTestId(id).boundingBox())!
      expect(box.height, id).toBeGreaterThanOrEqual(44)
      expect(box.width, id).toBeGreaterThanOrEqual(44)
    }

    // The conversation is the largest block on the screen, not a card beside a sidebar.
    const conversation = (await page.getByTestId('mobile-conversation').boundingBox())!
    const intel = (await page.getByTestId('mobile-intel').boundingBox())!
    expect(conversation.height).toBeGreaterThan(intel.height)
    expect(conversation.height).toBeGreaterThanOrEqual(300)

    await expectNoSidewaysScroll(page, 'lead')
  })

  test('the action bar stays at the bottom edge while the page scrolls, with a thumb-sized target and the safe-area padding', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)
    const bar = page.getByTestId('sales-bar')
    await expect(bar).toBeVisible()

    const viewport = page.viewportSize()!
    const before = (await bar.boundingBox())!
    expect(Math.abs(before.y + before.height - viewport.height)).toBeLessThanOrEqual(1)

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
    await page.waitForTimeout(250)
    const after = (await bar.boundingBox())!
    expect(after.y).toBeCloseTo(before.y, 0)

    for (const id of ['sales-whatsapp', 'sales-call', 'sales-outcome', 'sales-next']) {
      expect((await page.getByTestId(id).boundingBox())!.height, id).toBeGreaterThanOrEqual(44)
    }

    // In a home-screen app the bar is padded by the device's own inset; in a browser the inset is 0 and the floor still applies.
    const padding = await bar.evaluate((el) => Number.parseFloat(getComputedStyle(el).paddingBottom))
    expect(padding).toBeGreaterThanOrEqual(8)
  })

  test('Call opens the dialler with the lead’s own number and records that a call was STARTED — not that it happened', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)

    const call = page.getByTestId('sales-call')
    await expect(call).toHaveAttribute('href', /^tel:\+?\d{7,15}$/)
    // The lead's own number, digit for digit — not one typed in anywhere else.
    expect((await call.getAttribute('href'))!.replace(/\D/g, '')).toBe(first.phone.replace(/\D/g, ''))

    const logged = page.waitForResponse((r) => r.url().includes(`/leads/${first.id}/contact`) && r.request().method() === 'POST')
    await call.click()
    expect((await logged).ok()).toBe(true)
    await expect(page.getByText('بدأ الاتصال')).toBeVisible()

    // On the server: a call record exists, and nothing says it was answered; the timeline says a call was started.
    const facts = await leadFacts(first.id)
    expect(facts.calls.length).toBeGreaterThan(0)
    expect(facts.calls[0].status).not.toMatch(/answered|completed/)
    expect(facts.activities.some((a) => a.type === 'called')).toBe(true)
  })

  test('coming back from the dialler opens the outcome sheet by itself', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)

    await page.getByTestId('sales-call').click()
    await expect(page.getByTestId('outcome-sheet')).toHaveCount(0)

    // The page is shown again after the dialler.
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))
    await expect(page.getByTestId('outcome-sheet')).toBeVisible()
  })

  test('a number that cannot be dialled is shown as such and nothing is recorded', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)

    // The lead as the API would return it, with a phone that is not a number.
    await page.route(`**/api/v1/leads/${first.id}`, async (route) => {
      const response = await route.fetch()
      const body = await response.json()
      body.data.lead.phone = 'ليس رقما'
      await route.fulfill({ response, json: body })
    })

    let contactCalls = 0
    page.on('request', (request) => { if (request.url().includes('/contact') && request.method() === 'POST') contactCalls++ })

    await page.goto(`/leads/${first.id}`)
    await expect(page.getByTestId('sales-problem')).toContainText('غير صالح')
    await expect(page.getByTestId('sales-call')).toBeDisabled()
    await expect(page.getByTestId('sales-call')).not.toHaveAttribute('href')

    await page.getByTestId('sales-call').click({ force: true }).catch(() => undefined)
    await page.waitForTimeout(300)
    expect(contactCalls).toBe(0)
  })

  test('WhatsApp opens WhatsApp by its deep link when the workspace sends from elsewhere, and records only that it was opened', async ({ page, context }) => {
    const first = await firstInQueue()
    test.skip(!first.whatsapp, 'the first lead has no WhatsApp number')
    await asRep(page)
    await page.route('**/api/v1/whatsapp/capability', (route) => route.fulfill({ json: { success: true, data: { can_send: false, mode: 'mock' } } }))
    await page.goto(`/leads/${first.id}`)

    const link = page.getByTestId('sales-whatsapp')
    await expect(link).toHaveAttribute('href', /^https:\/\/wa\.me\/\d{7,15}$/)
    await expect(link).toHaveAttribute('target', '_blank')

    const logged = page.waitForResponse((r) => r.url().includes(`/leads/${first.id}/contact`) && r.request().method() === 'POST')
    const popup = context.waitForEvent('page').catch(() => null)
    await link.click()
    expect((await logged).ok()).toBe(true)
    ;(await popup)?.close().catch(() => undefined)

    expect((await leadFacts(first.id)).activities.some((a) => a.type === 'whatsapp_clicked')).toBe(true)
  })

  test('WhatsApp goes to the lead’s own thread when the workspace sends from inside the product — no new window, composer ready', async ({ page, context }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.route('**/api/v1/whatsapp/capability', (route) => route.fulfill({ json: { success: true, data: { can_send: true, mode: 'live' } } }))
    await page.goto(`/leads/${first.id}`)

    const opened: string[] = []
    context.on('page', (opened_) => opened.push(opened_.url()))

    await expect(page.getByTestId('sales-whatsapp')).not.toHaveAttribute('href')
    await page.getByTestId('sales-whatsapp').click()
    await expect(page.getByTestId('mobile-message-input')).toBeFocused()
    expect(opened).toHaveLength(0)
  })

  test('the conversation shows who sent each message, when, over which channel, and how many are new', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    const at = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString()
    const message = (id: string, direction: 'inbound' | 'outbound', body: string, minutes: number, extra: Record<string, unknown> = {}) => ({
      id, direction, channel: 'whatsapp', message_type: 'text', template_name: null, body, status: direction === 'inbound' ? 'received' : 'read',
      sent_at: at(minutes), delivered_at: null, read_at: null, failed_at: null, failure_code: null, failure_reason: null, sent_by: null, created_at: at(minutes), ...extra,
    })

    await page.route(`**/api/v1/leads/${first.id}/messages**`, (route) => route.fulfill({
      json: {
        success: true,
        data: [
          message('m3', 'inbound', 'هل الوحدة متاحة للمعاينة غدًا؟', 2),
          message('m2', 'inbound', 'السلام عليكم، أريد التفاصيل', 9),
          message('m1', 'outbound', 'أهلًا، سأرسل لك البروشور', 20, { sent_by: { id: 'u1', name: 'مندوب أول' } }),
        ],
        meta: { current_page: 1, last_page: 1, per_page: 30, total: 3 },
      },
    }))
    await page.goto(`/leads/${first.id}`)

    const thread = page.getByTestId('conversation-thread')
    await expect(thread.getByTestId('message-inbound')).toHaveCount(2)
    await expect(thread.getByTestId('message-outbound')).toHaveCount(1)
    await expect(thread).toContainText('مندوب أول')
    await expect(thread).toContainText('العميل')
    await expect(page.getByTestId('mobile-conversation')).toContainText('واتساب')
    // Two customer messages since our last reply.
    await expect(page.getByTestId('conversation-unread')).toContainText('رسالتان جديدتان')
    await expectNoSidewaysScroll(page, 'conversation')
  })

  test('saying how it went saves once and lands on the next lead; pressing Save twice sends one request', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)

    const saves: string[] = []
    page.on('request', (request) => { if (request.url().includes('/work/outcome') && request.method() === 'POST') saves.push(request.postData() ?? '') })

    await page.getByTestId('sales-outcome').click()
    await expect(page.getByTestId('outcome-sheet')).toBeVisible()
    await expect(page.getByTestId('outcome-save')).toBeDisabled()
    await page.getByTestId('outcome-interested').click()
    await page.getByTestId('outcome-note').fill('طلب مكالمة بعد الظهر')

    // Two quick presses, as an impatient thumb does on a slow connection.
    await page.getByTestId('outcome-save').dblclick()
    await expect(page).not.toHaveURL(new RegExp(`/leads/${first.id}`))
    await expect(page.getByTestId('mobile-lead').or(page.getByTestId('mobile-work')).first()).toBeVisible()

    expect(saves).toHaveLength(1)
    expect(JSON.parse(saves[0]).outcome).toBe('interested')
    expect(JSON.parse(saves[0]).lead_id).toBe(first.id)
  })

  test('Next moves to a different lead without recording anything, and never goes back to the one just left', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)

    await page.getByTestId('sales-next').click()
    await expect(page).not.toHaveURL(new RegExp(`/leads/${first.id}`))
    await expect(page.getByTestId('mobile-lead')).toBeVisible()

    const second = page.url()
    await page.getByTestId('sales-next').click()
    await expect(page).not.toHaveURL(second)
    expect(page.url()).not.toContain(first.id)

    expect((await leadFacts(first.id)).lead.status).toBe('contacted')
  })

  test('with no connection the outcome cannot be saved, says why, and nothing is queued; it works again when the connection returns', async ({ page, context }) => {
    const first = await firstInQueue()
    await asRep(page)
    await page.goto(`/leads/${first.id}`)
    await expect(page.getByTestId('mobile-lead')).toBeVisible()

    const saves: string[] = []
    page.on('request', (request) => { if (request.url().includes('/work/outcome')) saves.push(request.url()) })

    await context.setOffline(true)
    await expect(page.getByTestId('sales-problem')).toContainText('غير متصل')
    await page.getByTestId('sales-outcome').click()
    await page.getByTestId('outcome-won').click()
    await expect(page.getByTestId('outcome-offline')).toBeVisible()
    await expect(page.getByTestId('outcome-save')).toBeDisabled()
    expect(saves).toHaveLength(0)

    await context.setOffline(false)
    await expect(page.getByTestId('outcome-offline')).toHaveCount(0)
    await expect(page.getByTestId('outcome-save')).toBeEnabled()
  })

  test('the secondary details are a bottom sheet, and the code for them is only fetched when it is opened', async ({ page }) => {
    const first = await firstInQueue()
    await asRep(page)

    const urls: string[] = []
    page.on('request', (request) => urls.push(request.url()))

    await page.goto(`/leads/${first.id}`)
    await expect(page.getByTestId('mobile-lead')).toBeVisible()
    await page.waitForLoadState('networkidle')

    // A phone never downloads the laptop's lead page or its charts to work a lead.
    expect(urls.filter((u) => /\/pages\/LeadDetail|components\/charts\/|JourneyTimeline/.test(u))).toEqual([])

    await page.getByTestId('mobile-more-info').click()
    await expect(page.getByTestId('lead-secondary')).toBeVisible()
    expect(urls.some((u) => /LeadSecondary/.test(u))).toBe(true)

    // It is a sheet from the bottom edge, inside the screen.
    const dialog = page.getByRole('dialog').last()
    await expect.poll(async () => { const b = (await dialog.boundingBox())!; return b.y + b.height }).toBeLessThanOrEqual(page.viewportSize()!.height + 1)
  })

  test('a notification opens the lead it is about, not the dashboard', async ({ page }) => {
    // An alert for rep one about a specific lead: assigned to them by the admin.
    const admin: ApiSession = await apiLogin(ALPHA.admin.email)
    const rep: ApiSession = await apiLogin(ALPHA.rep1.email)

    try {
      const me = (await apiGet<{ user: { id: string } }>(rep, '/api/v1/auth/me')).user.id
      const created = await apiPost(admin, '/api/v1/leads', { full_name: `عميل الإشعار ${Date.now()}`, phone: `05${String(Date.now()).slice(-8)}`, assigned_to: me })
      expect(created.ok(), await created.text()).toBeTruthy()
      const leadId = (await created.json()).data.lead.id as string

      // The kinds a rep must not miss all carry the lead; a hot-lead alert is raised for this one too.
      artisan(['tinker', '--execute', `\\App\\Domain\\Notifications\\ImportantAlerts::hotLead(\\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '${leadId}')->firstOrFail());`], { QUEUE_CONNECTION: 'sync' })

      await asRep(page)
      await page.goto('/leads')
      await page.getByTestId('notifications-toggle').click()
      const item = page.getByTestId('notification-item').first()
      await expect(item).toBeVisible()
      await item.click()

      await expect(page).toHaveURL(new RegExp(`/leads/${leadId}`))
      await expect(page.getByTestId('mobile-lead')).toBeVisible()
    } finally {
      await admin.dispose()
      await rep.dispose()
    }
  })

  test('a link to a lead opened with the session expired returns to that lead after signing in', async ({ page }) => {
    const first = await firstInQueue()

    await page.goto(`/leads/${first.id}`)
    await expect(page).toHaveURL(/\/login/)

    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.rep1.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    await expect(page).toHaveURL(new RegExp(`/leads/${first.id}`))
    await expect(page.getByTestId('mobile-lead')).toBeVisible()
  })

  for (const width of [360, 375, 390, 430]) {
    test(`${width}px: Work Now and the lead fit without sideways scroll, and the bar's four buttons all fit`, async ({ page }) => {
      const first = await firstInQueue()
      await asRep(page)
      await page.setViewportSize({ width, height: 800 })

      for (const path of ['/work', `/leads/${first.id}`]) {
        await page.goto(path)
        await expect(page.getByTestId('sales-bar')).toBeVisible()
        // The WhatsApp control becomes a link once the workspace's capability is known; measure after it has.
        await page.waitForLoadState('networkidle')
        await expectNoSidewaysScroll(page, `${width}px ${path}`)

        for (const id of ['sales-whatsapp', 'sales-call', 'sales-outcome', 'sales-next']) {
          const box = (await page.getByTestId(id).boundingBox())!
          expect(box.x, `${id} at ${width}`).toBeGreaterThanOrEqual(0)
          expect(box.x + box.width, `${id} at ${width}`).toBeLessThanOrEqual(width + 1)
        }
      }
    })
  }
})
