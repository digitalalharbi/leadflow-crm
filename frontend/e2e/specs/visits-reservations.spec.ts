import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** From "I want to see it" to a held unit and the sale. */
test.describe('Visits and reservations', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  let counter = 0

  async function leadWhoAsksToVisit(): Promise<{ id: string; name: string }> {
    const tail = String(2000 + (Date.now() % 7000) + counter++).slice(-4)
    const name = `طالب زيارة ${tail}`
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: name, phone: `05066${tail}00`.slice(0, 10) } })
      expect(created.ok(), await created.text()).toBeTruthy()
      const id = (await created.json()).data.lead.id as string
      // What the customer said: they want to come and see it.
      const asked = await admin.context.patch(`/api/v1/leads/${id}/qualification`, { data: { appointment_interest: true } })
      expect(asked.ok(), await asked.text()).toBeTruthy()
      // Owned by the admin so the lists (which show a rep their own) include it.
      artisan(['tinker', '--execute', `\\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '${id}')->update(['assigned_to' => \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.admin.email}')->value('id')]); \\App\\Models\\Appointment::withoutGlobalScopes()->where('lead_id', \\App\\Models\\Lead::withoutGlobalScopes()->where('uuid', '${id}')->value('id'))->update(['assigned_to' => \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.admin.email}')->value('id')]);`])

      return { id, name }
    } finally {
      await admin.dispose()
    }
  }

  test('a visit request waits for a time, is confirmed, done, and given a result', async ({ page }) => {
    const { name } = await leadWhoAsksToVisit()
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/visits')

    // It is in the list of requests, with no time.
    const card = page.locator('[data-testid^="visit-"][data-status="requested"]').filter({ hasText: name })
    await expect(card).toBeVisible()
    await expect(card.getByTestId('visit-when')).toContainText('بلا موعد')

    // Confirm a time.
    await card.getByTestId('visit-confirm').click()
    const tomorrow = new Date(Date.now() + 26 * 3600_000)
    const local = new Date(tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
    await page.getByTestId('visit-time').fill(local)
    await page.getByTestId('visit-time-save').click()

    await page.getByTestId('visit-bucket-upcoming').click()
    const booked = page.locator('[data-testid^="visit-"][data-status="confirmed"]').filter({ hasText: name })
    await expect(booked).toBeVisible()

    // Done, and what came of it.
    await booked.getByTestId('visit-result').click()
    await page.getByTestId('visit-outcome-interested').click()

    await page.getByTestId('visit-bucket-needs_result').click()
    await expect(page.locator('[data-testid^="visit-"]').filter({ hasText: name })).toHaveCount(0)
  })

  test('a visit that is missed is listed and can be moved to a new time', async ({ page }) => {
    const { name } = await leadWhoAsksToVisit()
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/visits')

    const card = page.locator('[data-testid^="visit-"][data-status="requested"]').filter({ hasText: name })
    await card.getByTestId('visit-confirm').click()
    const later = new Date(Date.now() + 30 * 3600_000)
    await page.getByTestId('visit-time').fill(new Date(later.getTime() - later.getTimezoneOffset() * 60_000).toISOString().slice(0, 16))
    await page.getByTestId('visit-time-save').click()

    await page.getByTestId('visit-bucket-upcoming').click()
    await page.locator('[data-testid^="visit-"][data-status="confirmed"]').filter({ hasText: name }).getByTestId('visit-no-show').click()

    await page.getByTestId('visit-bucket-no_show').click()
    const missed = page.locator('[data-testid^="visit-"][data-status="no_show"]').filter({ hasText: name })
    await expect(missed).toBeVisible()
    await expect(missed.getByTestId('visit-reschedule')).toBeVisible()
  })

  test('a result of reservation holds a unit that can then be confirmed and sold', async ({ page }) => {
    const { id, name } = await leadWhoAsksToVisit()
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const when = new Date(Date.now() + 5 * 60_000).toISOString()
      const visit = await admin.context.post('/api/v1/appointments', { data: { lead_id: id, scheduled_at: when } })
      expect(visit.ok(), await visit.text()).toBeTruthy()
      const visitId = (await visit.json()).data.appointment.id as string
      const outcome = await admin.context.post(`/api/v1/appointments/${visitId}/outcome`, { data: { outcome: 'reservation' } })
      expect(outcome.ok(), await outcome.text()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/visits')
    await page.getByRole('tab', { name: 'الحجوزات' }).click()

    const reservation = page.locator('[data-testid^="reservation-"][data-status="pending"]').filter({ hasText: name })
    await expect(reservation).toBeVisible()
    await expect(reservation.getByTestId('reservation-expiry')).toBeVisible()

    await reservation.getByTestId('reservation-confirm').click()
    const confirmed = page.locator('[data-testid^="reservation-"][data-status="confirmed"]').filter({ hasText: name })
    await expect(confirmed).toBeVisible()

    await confirmed.getByTestId('reservation-convert').click()
    // Sold: it leaves the open list.
    await expect(page.locator('[data-testid^="reservation-"]').filter({ hasText: name })).toHaveCount(0)
  })

  test('the manager funnel runs from lead to won', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')

    const funnel = page.getByTestId('manager-re-funnel')
    await expect(funnel).toBeVisible()
    for (const label of ['عملاء', 'مؤهلون', 'مهتمون', 'زاروا', 'حجزوا', 'اشتروا']) {
      await expect(funnel).toContainText(label)
    }
  })
})
