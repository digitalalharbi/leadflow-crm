import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

interface LeadRow {
  id: string
  full_name: string
  status: string
  temperature: string
  assignee: { id: string; name: string } | null
}

/** Reads a lead straight from the API, to prove the backend really changed. */
async function fetchLead(name: string): Promise<LeadRow> {
  const session = await apiLogin(ALPHA.admin.email)

  try {
    const leads = await apiGet<LeadRow[]>(session, `/api/v1/leads?search=${encodeURIComponent(name)}`)
    const match = leads.find((lead) => lead.full_name === name)

    if (!match) throw new Error(`Lead not found via API: ${name}`)

    return match
  } finally {
    await session.dispose()
  }
}

test.describe('Lead workflow', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
  })

  test('a lead moves New → Contacted → Qualified → Appointment → Won', async ({ page }) => {
    await openLeadByName(page, LEADS.workflow)

    const stages: Array<[string, string]> = [
      ['تم التواصل', 'contacted'],
      ['مؤهل', 'qualified'],
      ['موعد', 'appointment'],
      ['مكسوب', 'won'],
    ]

    for (const [label, value] of stages) {
      await page.getByTestId('stage-switcher').getByRole('button', { name: label, exact: true }).click()

      /*
       * Winning asks what the deal closed for, the way losing asks why — both
       * capture the one number the report is made of at the moment the rep
       * knows it. The value is optional, so confirming is enough here; a
       * separate spec covers closing with and without it.
       */
      if (value === 'won') {
        await page.getByTestId('confirm-won').click()
      }

      // The header badge reflects the new stage…
      await expect(page.getByTestId('lead-status-badge')).toHaveText(label)

      // …and so does the database.
      await expect(async () => {
        expect((await fetchLead(LEADS.workflow)).status).toBe(value)
      }).toPass({ timeout: 10_000 })
    }

    // The whole journey is on the timeline, which is what a manager audits.
    const timeline = page.getByTestId('activity-timeline')
    await expect(timeline.getByText('تغيّرت المرحلة').first()).toBeVisible()
    await expect(timeline.getByText('تم كسب الصفقة')).toBeVisible()
  })

  test('marking a lead lost requires a reason and records it', async ({ page }) => {
    await openLeadByName(page, LEADS.lost)

    await page.getByTestId('stage-switcher').getByRole('button', { name: 'مفقود', exact: true }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // Confirm stays disabled until a reason is chosen — the report depends on it.
    await expect(dialog.getByRole('button', { name: 'تأكيد' })).toBeDisabled()

    await dialog.getByLabel('سبب الفقدان').selectOption({ index: 1 })
    await dialog.getByLabel('ملاحظة').fill('الميزانية غير مناسبة');
    await dialog.getByRole('button', { name: 'تأكيد' }).click()

    await expect(page.getByTestId('lead-status-badge')).toHaveText('مفقود')

    await expect(async () => {
      expect((await fetchLead(LEADS.lost)).status).toBe('lost')
    }).toPass({ timeout: 10_000 })

    await expect(page.getByTestId('activity-timeline').getByText('تم فقدان العميل')).toBeVisible()
  })

  test('the temperature can be changed from the action bar', async ({ page }) => {
    await openLeadByName(page, LEADS.activity)

    await page.getByTestId('action-temperature').click()
    await page.getByRole('button', { name: 'منخفض' }).click()

    await expect(page.getByTestId('lead-temperature-badge')).toHaveText('منخفض')

    await expect(async () => {
      expect((await fetchLead(LEADS.activity)).temperature).toBe('cold')
    }).toPass({ timeout: 10_000 })
  })
})

test.describe('Lead assignment', () => {
  test.beforeEach(() => resetFixtures())

  test('a manager can assign an unassigned lead from the action bar', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.assign)

    await expect(page.getByText('غير مُسند').first()).toBeVisible()

    await page.getByTestId('action-assign').click()
    await page.getByRole('button', { name: ALPHA.rep1.name }).click()

    await expect(async () => {
      expect((await fetchLead(LEADS.assign)).assignee?.name).toBe(ALPHA.rep1.name)
    }).toPass({ timeout: 10_000 })

    await expect(page.getByTestId('activity-timeline').getByText('تم الإسناد').first()).toBeVisible()
  })

  test('the assigned rep then sees the lead and others do not', async ({ page }) => {
    // Assign it through the API so this test is independent of the one above.
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const lead = await fetchLead(LEADS.assign)
      const users = await apiGet<{ users: Array<{ id: string; name: string }> }>(
        admin,
        '/api/v1/users/assignable',
      )
      const rep1 = users.users.find((u) => u.name === ALPHA.rep1.name)!

      await admin.context.post(`/api/v1/leads/${lead.id}/assign`, { data: { assigned_to: rep1.id } })
    } finally {
      await admin.dispose()
    }

    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/leads')
    await page.getByTestId('lead-search').fill(LEADS.assign)
    await expect(page.getByRole('link', { name: LEADS.assign })).toBeVisible()

    await signInAs(page, ALPHA.rep2.email)
    await page.goto('/leads')
    await page.getByTestId('lead-search').fill(LEADS.assign)
    await expect(page.getByText('لا توجد نتائج مطابقة للتصفية الحالية.')).toBeVisible()
  })

  test('round robin shares inbound leads across the active reps', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      // Switch the tenant to round robin, then ingest three leads and check they
      // did not all land on the same person.
      await admin.context.patch('/api/v1/settings', { data: { assignment_mode: 'round_robin' } })

      const created: string[] = []

      for (let i = 0; i < 4; i++) {
        const name = `عميل التوزيع ${i}`
        const response = await admin.context.post('/api/v1/leads', {
          data: { full_name: name, phone: `05077700${i}0` },
        })
        expect(response.ok()).toBeTruthy()
        created.push(name)
      }

      const owners = new Set<string>()

      for (const name of created) {
        owners.add((await fetchLead(name)).assignee?.name ?? 'unassigned')
      }

      expect(owners.size).toBeGreaterThan(1)
      expect(owners.has('unassigned')).toBeFalsy()

      await admin.context.patch('/api/v1/settings', { data: { assignment_mode: 'manual' } })
    } finally {
      await admin.dispose()
    }

    await signInAs(page, ALPHA.admin.email)
  })
})
