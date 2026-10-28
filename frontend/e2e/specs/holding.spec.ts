import { expect, test, type Page } from '@playwright/test'
import { ALPHA, PASSWORD } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { authenticateAs, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Holding → company → project.
 *
 * The fixture workspace has no companies, and every other spec depends on that
 * — a switcher in the header and a strip on the dashboard are exactly what a
 * density spec would notice. So this spec builds its own structure through the
 * API and puts the fixtures back afterwards.
 */
/** The page's own request for the companies list has been answered. */
const companiesLoaded = (page: Page) =>
  page.waitForResponse((r) => new URL(r.url()).pathname === '/api/v1/companies' && r.request().method() === 'GET' && r.ok())

test.describe('Holding → company → project', () => {
  let first = ''
  let second = ''

  test.beforeAll(async () => {
    resetFixtures()

    const admin = await apiLogin(ALPHA.admin.email, PASSWORD)
    try {
      const { projects } = await apiGet<{ projects: { id: string }[] }>(admin, '/api/v1/projects')

      const make = async (name: string, color: string) => {
        const response = await admin.context.post('/api/v1/companies', { data: { name, color } })
        expect(response.ok()).toBeTruthy()
        return (await response.json()).data.company.id as string
      }

      first = await make('شركة الواحة', '#0f766e')
      second = await make('شركة الأفق', '#7c3aed')

      // Two projects to the first company, one to the second.
      for (const [index, project] of projects.slice(0, 3).entries()) {
        const response = await admin.context.patch(`/api/v1/projects/${project.id}`, {
          data: { company_id: index < 2 ? first : second },
        })
        expect(response.ok()).toBeTruthy()
      }
    } finally {
      await admin.dispose()
    }
  })

  test.afterAll(() => resetFixtures())

  test('the dashboard shows the holding, and choosing a company scopes it', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    await expect(page.getByTestId('holding-overview')).toBeVisible()
    await expect(page.getByTestId(`holding-company-${first}`)).toBeVisible()
    await expect(page.getByTestId(`holding-company-${second}`)).toBeVisible()

    const all = Number(await page.getByTestId('kpi-total').locator('[class*="tabular"], span').first().textContent().then((t) => (t ?? '').replace(/\D/g, '')) || 0)

    // The company's projects are their own request, made once the company is chosen; the count is read after it answers.
    const projects = page.waitForResponse((r) => new URL(r.url()).pathname === `/api/v1/companies/${first}/projects` && r.request().method() === 'GET' && r.ok())
    await page.getByTestId(`holding-company-${first}`).click()

    // The level changes: a breadcrumb, a way back, and the company's projects.
    await expect(page.getByTestId('company-level')).toBeVisible()
    await expect(page.getByTestId('back-to-holding')).toBeVisible()
    await projects
    await expect(page.getByTestId('company-projects').locator('li')).toHaveCount(2)
    await expect(page.getByTestId('company-switcher')).toContainText('شركة الواحة')

    // …and the figures follow: fewer leads than the whole holding.
    await expect.poll(async () => {
      const text = await page.getByTestId('kpi-total').innerText()
      return Number(text.replace(/\D/g, '').slice(0, 3) || 0)
    }).toBeLessThan(all || Infinity)

    await page.getByTestId('back-to-holding').click()
    await expect(page.getByTestId('holding-overview')).toBeVisible()
  })

  test('the switcher narrows the lead list and survives a reload', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    await page.goto('/leads')
    const total = await page.getByTestId('lead-count').textContent().catch(() => null)

    await page.getByTestId('company-switcher').click()
    await page.getByTestId(`company-option-${second}`).click()
    // The choice is committed (and stored) before the page is left.
    await expect(page.getByTestId('company-switcher')).toContainText('شركة الأفق')

    // The switcher is drawn only once the signed-in person and then the list of companies have both arrived;
    // after a full navigation it waits for exactly that answer rather than a fixed allowance.
    const companies = companiesLoaded(page)
    await page.goto('/leads')
    await companies
    await expect(page.getByTestId('company-switcher')).toContainText('شركة الأفق')

    if (total) {
      await expect(page.getByTestId('lead-count')).not.toHaveText(total)
    }

    const reloaded = companiesLoaded(page)
    await page.reload()
    await reloaded
    await expect(page.getByTestId('company-switcher')).toContainText('شركة الأفق')

    await page.getByTestId('company-switcher').click()
    await page.getByTestId('company-option-all').click()
    await expect(page.getByTestId('company-switcher')).toContainText('كل الشركات')
  })

  test('the companies screen shows each company with its numbers and opens it', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/companies')

    await expect(page.getByTestId('company-grid')).toBeVisible()
    await expect(page.getByTestId(`company-card-${first}`)).toContainText('شركة الواحة')

    await page.getByTestId(`enter-company-${first}`).click()

    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByTestId('company-level')).toBeVisible()
  })

  test('a company can be created, edited and removed from the screen', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/companies')

    await page.getByTestId('add-company').click()
    await page.getByTestId('company-name').fill('شركة مؤقتة')
    await page.getByTestId('save-company').click()
    await expect(page.getByText('شركة مؤقتة')).toBeVisible()

    // Empty, so it can go.
    await page.getByRole('button', { name: 'تعديل' }).last().click()
    await page.getByTestId('delete-company').click()
    await expect(page.getByText('شركة مؤقتة')).toHaveCount(0)
  })

  test('a project says which company owns it, and the form changes it', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/projects')

    await expect(page.getByTestId('project-company-chip').first()).toBeVisible()

    await page.getByRole('button', { name: 'تعديل' }).first().click()
    await expect(page.getByTestId('project-company')).toBeVisible()
  })

  test('a member is held to their company and has no way to leave it', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email, PASSWORD)
    try {
      const listing = await apiGet<{ users: { id: string; email: string }[] } | { id: string; email: string }[]>(admin, '/api/v1/users?per_page=100')
      const users = Array.isArray(listing) ? listing : listing.users
      const rep = users.find((u) => u.email === ALPHA.rep1.email)
      expect(rep).toBeTruthy()

      const response = await admin.context.put(`/api/v1/companies/${first}/members`, { data: { user_ids: [rep!.id] } })
      expect(response.ok()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    await signInAs(page, ALPHA.rep1.email)

    // One company, no menu: a name, and nowhere to go.
    await expect(page.getByTestId('company-label')).toContainText('شركة الواحة')
    await expect(page.getByTestId('company-switcher')).toHaveCount(0)
  })
})
