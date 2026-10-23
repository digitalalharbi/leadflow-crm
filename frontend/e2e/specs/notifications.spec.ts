import { expect, test, type Page } from '@playwright/test'
import { apiGet, apiLogin, apiPost, inboundWhatsappMessage, postWhatsappWebhook, type ApiSession } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

interface Notice {
  id: string
  type: string
  data: { kind?: string; title?: string; body?: string; stage?: string; lead_id?: string; severity?: string; rule?: string }
}

/**
 * The five alerts a manager must never miss, each followed from the event to the bell:
 * a hot lead, an SLA breach, an automation that failed, a customer asking for a visit, and a
 * high-value lead. Nothing here is mocked — the event happens on the API, the notification is
 * read back from it, and the person's own bell shows it.
 */
test.describe('Notifications', () => {
  test.beforeAll(() => resetFixtures())

  let counter = 0
  const stamp = Date.now()

  /** A phone number no other spec uses; also how a WhatsApp reply finds its lead. */
  const phone = () => `96651${String(stamp % 100000).padStart(5, '0').slice(-5)}${String(counter++).padStart(2, '0')}`

  async function createLead(admin: ApiSession, data: Record<string, unknown>): Promise<{ id: string; name: string; phone: string }> {
    const number = phone()
    const name = `عميل الإشعارات ${counter}-${stamp}`
    const created = await apiPost(admin, '/api/v1/leads', { full_name: name, phone: `0${number.slice(3)}`, ...data })
    expect(created.ok(), await created.text()).toBeTruthy()

    return { id: (await created.json()).data.lead.id as string, name, phone: number }
  }

  async function notices(admin: ApiSession): Promise<Notice[]> {
    return (await apiGet<{ notifications: Notice[] }>(admin, '/api/v1/notifications')).notifications
  }

  /** Waits for the notification the event should have produced; the API wrote it synchronously. */
  async function noticeFor(admin: ApiSession, matches: (n: Notice) => boolean): Promise<Notice> {
    let found: Notice | undefined
    await expect.poll(async () => (found = (await notices(admin)).find(matches)) !== undefined, { timeout: 15_000 }).toBe(true)

    return found as Notice
  }

  /** The same notification, in the bell the person actually opens. */
  async function inTheBell(page: Page, text: string, kind?: string) {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')
    await page.getByTestId('notifications-toggle').click()
    // A lead nobody owns also tells the managers that it arrived, so one lead can have two notices:
    // name the kind when it is the kind that is being checked.
    const all = page.getByTestId('notification-item')
    const item = (kind ? all.and(page.locator(`[data-kind="${kind}"]`)) : all).filter({ hasText: text }).first()
    await expect(item).toBeVisible()

    return item
  }

  async function me(admin: ApiSession) {
    return (await apiGet<{ user: { id: string } }>(admin, '/api/v1/auth/me')).user.id
  }

  test('a lead that turns hot notifies its owner at once', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const ruleName = `تنبيه العميل الساخن ${stamp}`

    try {
      // The workspace's own rule: the moment a lead's temperature reaches hot, its owner is told.
      const rule = await apiPost(admin, '/api/v1/automation/rules', {
        name: ruleName, trigger: 'lead.score_changed', conditions: [{ field: 'temperature', operator: 'equals', value: 'hot' }],
        actions: [{ type: 'notify', audience: 'owner', message: 'عميل ساخن — اتصل الآن.' }], is_enabled: true,
      })
      expect(rule.ok(), await rule.text()).toBeTruthy()

      const lead = await createLead(admin, { assigned_to: await me(admin) })
      expect((await notices(admin)).some((n) => n.data.rule === ruleName)).toBe(false)   // not hot yet

      // The customer's answers say they are ready: the score climbs and the lead turns hot.
      const qualified = await admin.context.patch(`/api/v1/leads/${lead.id}/qualification`, {
        data: {
          purchase_purpose: 'residence', purchase_timeline: 'within_month', budget_max: 1_500_000,
          payment_method: 'financing', financing_status: 'approved', preferred_property_type: 'فيلا', appointment_interest: true,
        },
      })
      expect(qualified.ok(), await qualified.text()).toBeTruthy()
      expect((await apiGet<{ lead: { temperature: string } }>(admin, `/api/v1/leads/${lead.id}`)).lead.temperature).toBe('hot')

      const notice = await noticeFor(admin, (n) => n.type === 'automation' && n.data.lead_id === lead.id && n.data.rule === ruleName)
      expect(notice.data.body).toContain('عميل ساخن')

      const item = await inTheBell(page, ruleName)
      await expect(item).toHaveAttribute('data-type', 'automation')
    } finally {
      await admin.dispose()
    }
  })

  test('an SLA breach is escalated to the management', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const lead = await createLead(admin, {})
      // A lead its rep never called: owned, untouched, and eight hours old.
      artisan(['tinker', '--execute', `
        $rep = \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.rep1.email}')->firstOrFail();
        \\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '${lead.id}')->update([
          'assigned_to' => $rep->id, 'status' => 'new', 'first_contact_at' => null,
          'created_at' => now()->subHours(8), 'sla_reminded_at' => null, 'sla_escalated_at' => null,
        ]);
      `])
      artisan(['leadflow:sweep-sla'], { QUEUE_CONNECTION: 'sync' })

      const notice = await noticeFor(admin, (n) => n.type === 'sla' && n.data.lead_id === lead.id)
      expect(notice.data.stage).toBe('escalate')

      const item = await inTheBell(page, lead.name)
      await expect(item).toHaveAttribute('data-type', 'sla')
    } finally {
      await admin.dispose()
    }
  })

  test('an automation that fails tells the managers which rule and why', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const ruleName = `قاعدة تفشل ${stamp}`

    try {
      // "Notify the owner" of a lead nobody owns: it cannot work, and must not fail quietly.
      const rule = await apiPost(admin, '/api/v1/automation/rules', {
        name: ruleName, trigger: 'lead.created', conditions: [], actions: [{ type: 'notify', audience: 'owner', message: 'x' }], is_enabled: true,
      })
      expect(rule.ok(), await rule.text()).toBeTruthy()

      await createLead(admin, {})

      const notice = await noticeFor(admin, (n) => n.data.kind === 'automation_failed' && (n.data.body ?? '').includes(ruleName))
      expect(notice.data.severity).toBe('warning')

      const item = await inTheBell(page, ruleName)
      await expect(item).toHaveAttribute('data-kind', 'automation_failed')
    } finally {
      await admin.dispose()
    }
  })

  test('a customer asking for a visit is a critical alert', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const lead = await createLead(admin, {})
      const delivered = await postWhatsappWebhook(inboundWhatsappMessage(lead.phone, 'ابغى أزور المشروع، متى أقدر أحجز موعد؟', `wamid.e2e.visit.${lead.phone}.${Date.now()}`))
      expect(delivered.status).toBe(200)

      const notice = await noticeFor(admin, (n) => n.data.kind === 'appointment_requested' && n.data.lead_id === lead.id)
      expect(notice.data.severity).toBe('critical')

      const item = await inTheBell(page, lead.name, 'appointment_requested')
      await expect(item).toHaveAttribute('data-kind', 'appointment_requested')
    } finally {
      await admin.dispose()
    }
  })

  test('a high-value lead is flagged, and an ordinary one is not', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const big = await createLead(admin, { budget_max: 3_000_000 })
      const small = await createLead(admin, { budget_max: 80_000 })

      const notice = await noticeFor(admin, (n) => n.data.kind === 'high_value_lead' && n.data.lead_id === big.id)
      expect(notice.data.severity).toBe('warning')

      expect((await notices(admin)).some((n) => n.data.kind === 'high_value_lead' && n.data.lead_id === small.id)).toBe(false)

      const item = await inTheBell(page, big.name, 'high_value_lead')
      await expect(item).toHaveAttribute('data-kind', 'high_value_lead')
    } finally {
      await admin.dispose()
    }
  })

  test('the same event is not announced twice', async () => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const lead = await createLead(admin, { budget_max: 4_000_000 })
      await noticeFor(admin, (n) => n.data.kind === 'high_value_lead' && n.data.lead_id === lead.id)

      // Saving the lead again with the same budget is not a new high-value lead.
      const again = await admin.context.patch(`/api/v1/leads/${lead.id}`, { data: { budget_max: 4_000_000, city: 'الرياض' } })
      expect(again.ok(), await again.text()).toBeTruthy()

      const sent = (await notices(admin)).filter((n) => n.data.kind === 'high_value_lead' && n.data.lead_id === lead.id)
      expect(sent).toHaveLength(1)
    } finally {
      await admin.dispose()
    }
  })
})
