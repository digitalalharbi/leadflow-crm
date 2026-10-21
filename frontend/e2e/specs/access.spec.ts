import { expect, test } from '@playwright/test'
import { apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The Role Builder: a workspace builds a role, switches single pieces of the interface off for it,
 * gives it to a person — and what is off is not on their screen, whatever they type in the address bar.
 */
test.describe('Dynamic access', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('an admin builds a role, hides a piece of the interface, and the person never sees it', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/access')

    // 1. A new role: sees leads and the dashboard, nothing else.
    await page.getByTestId('access-new-role').click()
    await page.getByTestId('access-role-name').fill('مندوب بلا أرقام مالية')
    for (const grant of ['dashboard-view', 'leads-view', 'leads-edit', 'leads-execute']) {
      await page.getByTestId(`grant-${grant}`).check()
    }
    await page.getByTestId('access-save-role').click()
    await expect(page.getByTestId('access-role-editor')).toContainText('مندوب بلا أرقام مالية')

    // 2. Hide the "response time" KPI from it (dashboard only exists for roles that may see reports, so use the funnel chart).
    await page.getByRole('tab', { name: 'ما يظهر في الواجهة' }).click()
    await page.getByTestId('interface-role').selectOption({ label: 'مندوب بلا أرقام مالية' })
    await page.getByTestId('element-dashboard.chart.funnel-hide').click()
    await page.getByTestId('interface-save').click()
    await expect(page.getByText('تم الحفظ').first()).toBeVisible()

    // 3. Give it to a person (through the API: the users form is covered elsewhere).
    const admin = await apiLogin(ALPHA.admin.email)
    const state = await (await admin.context.get('/api/v1/access')).json()
    const role = state.data.roles.find((r: { name: string }) => r.name === 'مندوب بلا أرقام مالية')
    expect(role.elements).toEqual([{ key: 'dashboard.chart.funnel', state: 'hide' }])
    const email = 'custom-role@e2e.test'
    const created = await apiPost(admin, '/api/v1/users', { name: 'موظف مخصص', email, password: 'Password123!', access_role_id: role.id })
    expect(created.status()).toBe(201)
    await admin.dispose()

    // 4. Their session: no money, no campaigns, and the funnel chart is not on the page.
    const person = await apiLogin(email)
    const me = (await (await person.context.get('/api/v1/auth/me')).json()).data
    expect(me.permissions).toContain('leads.edit')
    expect(me.permissions).not.toContain('spend.view')
    expect(me.hidden_elements).toContain('dashboard.chart.funnel')
    expect((await person.context.get('/api/v1/campaigns')).status()).toBe(403)
    await person.dispose()

    await authenticateAs(page, email)
    await page.goto('/')
    await expect(page.getByTestId('icon-rail')).toBeVisible()
    await expect(page.getByTestId('funnel-card')).toHaveCount(0)
    await page.goto('/campaigns')
    await expect(page).not.toHaveURL(/\/campaigns/)
  })

  test('a person who is not an admin is not offered the builder', async ({ page }) => {
    await authenticateAs(page, ALPHA.finance.email)
    await page.goto('/access')
    await expect(page).not.toHaveURL(/\/access/)
  })
})
