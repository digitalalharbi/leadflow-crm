import { expect, test } from '@playwright/test'
import { apiGetRaw, apiLogin } from '../fixtures/api'
import { ALPHA, BETA } from '../fixtures/data'
import { makeLanding, type Made } from '../fixtures/hub'
import { resetFixtures } from '../fixtures/seed'

/** One workspace's links, forms and acquisition are invisible to another, and to the seats that were never given them. */
test.describe('Tracking hub — isolation and permissions', () => {
  let made: Made

  test.beforeAll(async () => {
    resetFixtures()
    const alpha = await apiLogin(ALPHA.admin.email)
    try {
      made = await makeLanding(alpha, { name: 'رابط الريادة السري' })
    } finally {
      await alpha.dispose()
    }
  })
  test.afterAll(() => resetFixtures())

  test('another workspace cannot read, list or open anything of this one', async () => {
    const beta = await apiLogin(BETA.admin.email)

    try {
      expect((await apiGetRaw(beta, `/api/v1/tracking/links/${made.linkId}/analytics`)).status()).toBe(404)
      expect((await apiGetRaw(beta, `/api/v1/tracking/forms/${made.formId}`)).status()).toBe(404)
      expect((await apiGetRaw(beta, `/api/v1/projects/${made.projectId}/acquisition`)).status()).toBe(404)

      const links = await (await apiGetRaw(beta, '/api/v1/tracking/links')).text()
      const forms = await (await apiGetRaw(beta, '/api/v1/tracking/forms')).text()
      expect(links).not.toContain('رابط الريادة السري')
      expect(forms).not.toContain('نموذج الاهتمام')

      // Naming this workspace's project, form or link when making one of its own is the same "not found".
      const stolen = await beta.context.post('/api/v1/tracking/links', { data: { name: 'سرقة', channel: 'meta', project_id: made.projectId } })
      expect(stolen.status()).toBe(404)
      const form = await beta.context.post('/api/v1/tracking/links', { data: { name: 'سرقة', channel: 'meta', mode: 'landing', landing_form_id: made.formId } })
      expect(form.status()).toBe(404)
    } finally {
      await beta.dispose()
    }
  })

  test('a salesperson has neither the acquisition view nor the forms and destinations that shape it', async () => {
    const rep = await apiLogin(ALPHA.rep1.email)

    try {
      expect((await apiGetRaw(rep, `/api/v1/projects/${made.projectId}/acquisition`)).status()).toBe(403)
      expect((await apiGetRaw(rep, '/api/v1/tracking/destinations')).status()).toBe(403)
      expect((await rep.context.post('/api/v1/tracking/forms', { data: { name: 'x', fields: [] } })).status()).toBe(403)
    } finally {
      await rep.dispose()
    }
  })

  test('a draft or archived form is not a page: a stranger gets the same "not available" as for no page at all', async () => {
    const alpha = await apiLogin(ALPHA.admin.email)

    try {
      const patch = await alpha.context.patch(`/api/v1/tracking/forms/${made.formId}`, { data: { status: 'draft' } })
      expect(patch.ok()).toBe(true)
    } finally {
      await alpha.dispose()
    }

    const response = await (await import('@playwright/test')).request.newContext({ baseURL: process.env.E2E_API_URL ?? 'http://127.0.0.1:8000' })
    try {
      expect((await response.get(`/api/v1/public/landing/${made.token}`, { headers: { Accept: 'application/json' } })).status()).toBe(404)
      expect((await response.get('/api/v1/public/landing/ZZZZZZZZ', { headers: { Accept: 'application/json' } })).status()).toBe(404)
    } finally {
      await response.dispose()
    }
  })
})
