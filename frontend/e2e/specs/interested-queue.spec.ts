import { expect, test } from '@playwright/test'
import { apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/**
 * The rep's main screen for people who want to buy: one lead, how long they may still wait, what
 * they want, and every next step one press away.
 */
test.describe('Interested queue — اعمل الآن', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  /** An interested lead that is the only open one, so the queue has exactly one thing to say. */
  async function reservationRequest(minutesAgo: number): Promise<{ id: string; name: string }> {
    const tail = String(1000 + (Date.now() % 8000)).slice(-4)
    const phone = `96650777${tail}`
    const name = `طالب حجز ${tail}`
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: name, phone: `0${phone.slice(3)}`, budget_max: 900000, preferred_district: 'النرجس' } })
      expect(created.ok(), await created.text()).toBeTruthy()
      const id = (await created.json()).data.lead.id as string

      const delivered = await postWhatsappWebhook(inboundWhatsappMessage(phone, 'ابي احجز وحدة وادفع العربون', `wamid.e2e.iq.${phone}.${Date.now()}`))
      expect(delivered.status).toBe(200)

      artisan(['tinker', '--execute', `
        \\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '${id}')->update([
          'status' => 'contacted', 'assigned_to' => \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.admin.email}')->value('id'),
          'interest_level_changed_at' => now()->subMinutes(${minutesAgo}), 'last_customer_message_at' => now()->subMinutes(${minutesAgo}), 'last_contact_at' => null,
        ]);
        \\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '<>', '${id}')->update(['status' => 'lost']);
      `])

      return { id, name }
    } finally {
      await admin.dispose()
    }
  }

  test('a reservation request that has waited too long is the card, with its clock, its facts and why', async ({ page }) => {
    const { name } = await reservationRequest(45)
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/work')

    await expect(page.getByTestId('work-lead-name')).toHaveText(name)
    await expect(page.getByTestId('work-urgency')).toBeVisible()

    // The level, and the clock: 5 minutes was the window, 45 have passed.
    await expect(page.getByTestId('work-interest-level')).toHaveAttribute('data-level', 'reservation_ready')
    await expect(page.getByTestId('work-sla-text')).toHaveAttribute('data-state', 'breached')
    await expect(page.getByTestId('work-sla-ring')).toBeVisible()

    // The facts the lead gave are on the card; the ones it has not given are not.
    await expect(page.getByTestId('work-fact-budget')).toContainText('900')
    await expect(page.getByTestId('work-fact-intent')).toContainText('النرجس')
    await expect(page.getByTestId('work-fact-waiting')).toBeVisible()
    await expect(page.getByTestId('work-fact-why')).toBeVisible()
  })

  test('one press reserves a unit and the lead moves on', async ({ page }) => {
    const { id } = await reservationRequest(2)
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/work')

    await expect(page.getByTestId('work-sla-text')).toHaveAttribute('data-state', /ok|due_soon/)
    await page.getByTestId('outcome-reservation').click()

    // Reserved: the queue has nothing else, and the lead is now holding a unit.
    await expect(page.getByTestId('work-empty')).toBeVisible()

    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const lead = await admin.context.get(`/api/v1/leads/${id}`)
      expect((await lead.json()).data.lead.interest_level).toBe('reservation_ready')
      expect((await lead.json()).data.lead.status).toBe('negotiation')
    } finally {
      await admin.dispose()
    }
  })

  test('not interested stops the follow-ups and takes the lead out of the queue', async ({ page }) => {
    await reservationRequest(10)
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/work')

    await page.getByTestId('outcome-not_interested').click()
    await expect(page.getByTestId('work-empty')).toBeVisible()
  })

  test('the manager sees how many interested leads are waiting past their window', async ({ page }) => {
    await reservationRequest(60)
    artisan(['leadflow:sweep-sla'], { QUEUE_CONNECTION: 'sync' })

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')

    const row = page.getByTestId('manager-interest')
    await expect(row).toBeVisible()
    await expect(page.getByTestId('manager-interest-late')).not.toHaveText('0')
    await expect(page.getByTestId('manager-interest-ring')).toBeVisible()
  })
})
