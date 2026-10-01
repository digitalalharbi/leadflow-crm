import { expect, test } from '@playwright/test'
import { ALPHA, BETA } from '../fixtures/data'
import { apiGet, apiGetRaw, apiLogin, apiPost } from '../fixtures/api'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

interface LeadRow { id: string; full_name: string }

/** Reads one tenant's ids so the other can be caught trying to use them. */
async function betaLeadId(): Promise<string> {
  const session = await apiLogin(BETA.admin.email)

  try {
    const leads = await apiGet<LeadRow[]>(session, '/api/v1/leads')
    const secret = leads.find((lead) => lead.full_name === BETA.secretLead)

    if (!secret) throw new Error('Beta fixture lead is missing')

    return secret.id
  } finally {
    await session.dispose()
  }
}

test.describe('Tenant isolation', () => {
  test.beforeAll(() => resetFixtures())

  test('one tenant never sees another tenant in any list', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    await page.goto('/leads')
    await page.getByPlaceholder('بحث…').fill(BETA.secretLead)
    await expect(page.getByText('لا توجد نتائج مطابقة للتصفية الحالية.')).toBeVisible()

    await page.goto('/users')
    await expect(page.getByText(BETA.admin.email)).toHaveCount(0)

    await page.goto('/pipeline')
    await expect(page.getByText(BETA.secretLead)).toHaveCount(0)
  })

  test('opening another tenant lead by its exact URL shows nothing', async ({ page }) => {
    const target = await betaLeadId()

    await signInAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${target}`)

    // The page must not render the record, and must not hint that it exists.
    await expect(page.getByText(BETA.secretLead)).toHaveCount(0)
    await expect(page.getByText('العنصر المطلوب غير موجود.')).toBeVisible()
  })

  test('the API answers 404 for another tenant uuid, exactly as for a made-up one', async () => {
    const target = await betaLeadId()
    const alpha = await apiLogin(ALPHA.admin.email)

    try {
      const foreign = await apiGetRaw(alpha, `/api/v1/leads/${target}`)
      const invented = await apiGetRaw(alpha, '/api/v1/leads/11111111-2222-3333-4444-555555555555')

      expect(foreign.status()).toBe(404)
      expect(invented.status()).toBe(404)

      // Indistinguishable, so a uuid cannot be probed for existence.
      expect(await foreign.json()).toEqual(await invented.json())
    } finally {
      await alpha.dispose()
    }
  })

  test('another tenant lead cannot be modified through the API', async () => {
    const target = await betaLeadId()
    const alpha = await apiLogin(ALPHA.admin.email)

    try {
      for (const [path, body] of [
        [`/api/v1/leads/${target}/status`, { status: 'won' }],
        [`/api/v1/leads/${target}/notes`, { body: 'injected' }],
        [`/api/v1/leads/${target}/assign`, { assigned_to: null }],
      ] as const) {
        const response = await apiPost(alpha, path, body)
        expect(response.status(), `${path} must refuse`).toBe(404)
      }

      const deleted = await alpha.context.delete(`/api/v1/leads/${target}`)
      expect(deleted.status()).toBe(404)
    } finally {
      await alpha.dispose()
    }

    // And the record is untouched.
    const beta = await apiLogin(BETA.admin.email)
    try {
      const leads = await apiGet<LeadRow[]>(beta, '/api/v1/leads')
      expect(leads.some((l) => l.full_name === BETA.secretLead)).toBeTruthy()
    } finally {
      await beta.dispose()
    }
  })

  test('reports never aggregate another tenant', async () => {
    const alpha = await apiLogin(ALPHA.admin.email)

    try {
      for (const type of ['platform', 'campaign', 'project', 'sales_rep', 'status', 'lost_reason']) {
        const report = await apiGet<{ rows: Array<{ label: string }> }>(
          alpha,
          `/api/v1/reports?type=${type}`,
        )
        const labels = report.rows.map((row) => row.label)

        expect(labels, `${type} report leaked a label`).not.toContain(BETA.companyName)
        expect(labels).not.toContain(BETA.admin.name)
        expect(labels).not.toContain(BETA.secretLead)
      }
    } finally {
      await alpha.dispose()
    }
  })

  test('a tenant user cannot reach the platform console', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    await page.goto('/platform')
    // The route is not part of a tenant workspace, so it bounces home.
    await expect(page).toHaveURL(/\/$/)

    const alpha = await apiLogin(ALPHA.admin.email)
    try {
      const response = await apiGetRaw(alpha, '/api/v1/super-admin/tenants')
      // 404, not 403: the console does not admit that it exists.
      expect(response.status()).toBe(404)
    } finally {
      await alpha.dispose()
    }
  })

  test('the same phone number may exist in both tenants independently', async () => {
    const alpha = await apiLogin(ALPHA.admin.email)
    const beta = await apiLogin(BETA.admin.email)

    try {
      const phone = '0555123123'

      const a = await apiPost(alpha, '/api/v1/leads', { full_name: 'مشترك ألفا', phone })
      const b = await apiPost(beta, '/api/v1/leads', { full_name: 'مشترك بيتا', phone })

      // Duplicate detection is per tenant: two agencies may chase the same buyer.
      expect(a.status()).toBe(201)
      expect(b.status()).toBe(201)
    } finally {
      await alpha.dispose()
      await beta.dispose()
    }
  })
})
