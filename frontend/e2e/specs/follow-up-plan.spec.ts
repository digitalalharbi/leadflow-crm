import { expect, test, type Page } from '@playwright/test'
import { apiLogin, apiPost, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The follow-up plan, inside the lead's workspace: what happens next, why, on which channel, when,
 * how far along — and the three buttons. Every situation a sales floor meets ends the plan or hands
 * it to a person, and the card says which.
 */
test.describe('Follow-up plan', () => {
  test.beforeAll(() => resetFixtures())

  let counter = 0

  /** A lead that has just arrived. `phone` is also how a WhatsApp reply is matched to it. */
  async function arrive(): Promise<{ id: string; phone: string }> {
    const phone = `96650${String(7000000 + Date.now() % 1000000 + counter++).slice(-7)}`
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: `عميل المتابعة ${counter}`, phone: `0${phone.slice(3)}`, email: `plan${counter}.${Date.now()}@example.com` } })
      expect(created.ok()).toBeTruthy()

      return { id: (await created.json()).data.lead.id as string, phone }
    } finally {
      await admin.dispose()
    }
  }

  async function open(page: Page, id: string) {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)
    const card = page.getByTestId('follow-up-plan')
    await expect(card).toBeVisible()

    return card
  }

  test('the lead workspace shows the next follow-up, its reason, channel, schedule and progress', async ({ page }) => {
    const { id } = await arrive()
    const card = await open(page, id)

    await expect(card.getByTestId('plan-status')).toHaveText(/نشطة|منتهية|اكتملت|متوقفة/)
    await expect(card.getByTestId('plan-reason')).toContainText(/\S/)
    await expect(card.getByTestId('plan-progress')).toContainText('/')
    for (const key of ['now', '15m', '4h', '24h', '3d', '7d']) {
      await expect(card.getByTestId(`plan-step-${key}`)).toBeVisible()
    }
    await expect(card.getByTestId('plan-reschedule')).toBeVisible()
    await expect(card.getByTestId('plan-stop')).toBeVisible()
    // Pause while it runs, Resume once it is held — one of the two is always there.
    await expect(card.getByTestId('plan-pause').or(card.getByTestId('plan-resume'))).toBeVisible()
  })

  test('Pause holds the plan and Resume lays it out again', async ({ page }) => {
    const { id } = await arrive()
    const card = await open(page, id)

    await card.getByTestId('plan-pause').click()
    await expect(card).toHaveAttribute('data-status', 'paused')
    await expect(card.getByTestId('plan-reason')).toHaveAttribute('data-reason', 'paused_by_user')
    await expect(card.getByTestId('plan-pause')).toHaveCount(0)

    await card.getByTestId('plan-resume').click()
    await expect(card).not.toHaveAttribute('data-status', 'paused')
    await expect(card.getByTestId('plan-resume')).toHaveCount(0)
  })

  test('Reschedule moves the next follow-up to the time chosen', async ({ page }) => {
    const { id } = await arrive()
    const card = await open(page, id)
    await expect(card.getByTestId('plan-next-at')).toBeVisible()
    const before = await card.getByTestId('plan-next-at').innerText()

    await card.getByTestId('plan-reschedule').click()
    // Three days ahead, at ten in the morning: far from anything the ladder would pick.
    const target = new Date(Date.now() + 3 * 24 * 3600 * 1000)
    target.setHours(10, 0, 0, 0)
    const pad = (n: number) => String(n).padStart(2, '0')
    await page.getByTestId('plan-reschedule-at').fill(`${target.getFullYear()}-${pad(target.getMonth() + 1)}-${pad(target.getDate())}T10:00`)
    await page.getByTestId('plan-reschedule-confirm').click()

    await expect(card.getByTestId('plan-next-at')).not.toHaveText(before)
    await expect(card.getByTestId('plan-reason')).toContainText(/\S/)
  })

  test('Stop ends the follow-up for good', async ({ page }) => {
    const { id } = await arrive()
    const card = await open(page, id)

    await card.getByTestId('plan-stop').click()

    await expect(card).toHaveAttribute('data-status', 'stopped')
    await expect(card.getByTestId('plan-reason')).toHaveAttribute('data-reason', 'stopped_by_user')
    await expect(card.getByTestId('plan-next-none')).toBeVisible()
    await expect(card.getByTestId('plan-stop')).toBeDisabled()
    await expect(card.getByTestId('plan-reschedule')).toBeDisabled()
    // Not undone by the next visit.
    await page.reload()
    await expect(page.getByTestId('follow-up-plan')).toHaveAttribute('data-status', 'stopped')
  })

  test('a reply turns the plan over to the rep', async ({ page }) => {
    const { id, phone } = await arrive()
    const delivered = await postWhatsappWebhook(inboundWhatsappMessage(phone, 'نعم مهتم', `wamid.e2e.plan.${phone}.${Date.now()}`))
    expect(delivered.status).toBe(200)

    const card = await open(page, id)

    await expect(card.getByTestId('plan-reason')).toHaveAttribute('data-reason', /replied|human_takeover/)
    await expect(card).toHaveAttribute('data-decision', 'escalate')
    // Nothing further is written to someone who has answered: the rest is the rep's own.
    await expect(card.getByTestId('plan-step-15m')).toHaveAttribute('data-state', /skipped|cancelled|done/)
  })

  test('a booked visit ends the automatic follow-up', async ({ page }) => {
    const { id } = await arrive()
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const booked = await apiPost(admin, '/api/v1/appointments', { lead_id: id, scheduled_at: new Date(Date.now() + 2 * 24 * 3600 * 1000).toISOString() })
      expect(booked.ok(), await booked.text()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    const card = await open(page, id)

    await expect(card).toHaveAttribute('data-status', 'stopped')
    await expect(card.getByTestId('plan-reason')).toHaveAttribute('data-reason', 'appointment')
  })

  test('a won lead is no longer followed up', async ({ page }) => {
    const { id } = await arrive()
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const won = await apiPost(admin, `/api/v1/leads/${id}/status`, { status: 'won' })
      expect(won.ok(), await won.text()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    const card = await open(page, id)

    await expect(card).toHaveAttribute('data-status', 'stopped')
    await expect(card.getByTestId('plan-reason')).toHaveAttribute('data-reason', 'won')
  })
})
