import { expect, test, type Page } from '@playwright/test'
import { apiGet, apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, linkFromEmail, resetFixtures } from '../fixtures/seed'

/**
 * Roles: what each is shown, and that the API refuses what the interface hides.
 *
 * The seeded workspace has one person per specialised role. The project manager and the client
 * were given the first project, the broker the second; the other two see every project.
 */
test.describe('Roles and permissions', () => {
  test.beforeAll(() => {
    resetFixtures()
    // The leads already carry campaigns; the hourly job turns them into rows.
    artisan(['leadflow:sync-campaigns', '--tenant=e2e-alpha'])
  })
  test.afterAll(() => resetFixtures())

  async function railLinks(page: Page): Promise<string[]> {
    await expect(page.getByTestId('icon-rail')).toBeVisible()

    return page.getByTestId('icon-rail').locator('a').evaluateAll((links) => links.map((a) => a.getAttribute('href') ?? ''))
  }

  async function asApi<T>(email: string, path: string) {
    const session = await apiLogin(email)

    try {
      return await apiGet<T>(session, path)
    } finally {
      await session.dispose()
    }
  }

  test('finance sees the money and nothing to work or configure', async ({ page }) => {
    await authenticateAs(page, ALPHA.finance.email)
    await page.goto('/dashboard')

    const links = await railLinks(page)
    for (const shown of ['/dashboard', '/leads', '/campaigns', '/reports']) expect(links).toContain(shown)
    for (const hidden of ['/work', '/follow-ups', '/automation', '/integrations', '/users', '/tracking', '/companies']) expect(links, hidden).not.toContain(hidden)

    // Typing the address of a screen it was not given goes home, not to a wall of errors.
    await page.goto('/users')
    await expect(page).not.toHaveURL(/\/users/)
    await page.goto('/integrations')
    await expect(page).not.toHaveURL(/\/integrations/)
    await page.goto('/work')
    await expect(page).not.toHaveURL(/\/work/)

    // And the revenue tab is theirs: spend and revenue are both granted.
    await page.goto('/campaigns')
    await page.getByRole('tab', { name: 'من الإنفاق إلى الإيراد' }).click()
    await expect(page.getByTestId('rk-spend')).toBeVisible()
    await expect(page.getByTestId('rk-revenue')).toBeVisible()
  })

  test('a client sees one project, no money and no way in to what is not theirs', async ({ page }) => {
    await authenticateAs(page, ALPHA.client.email)
    await page.goto('/leads')

    const list = await asApi<{ id: string; project: { name: string } | null }[]>(ALPHA.client.email, '/api/v1/leads')
    expect(list.length).toBeGreaterThan(0)
    expect(new Set(list.map((lead) => lead.project?.name))).toEqual(new Set([ALPHA.projects[0]]))

    const links = await railLinks(page)
    for (const hidden of ['/campaigns', '/tracking', '/automation', '/users', '/integrations', '/work']) expect(links, hidden).not.toContain(hidden)

    await page.goto('/campaigns')
    await expect(page).not.toHaveURL(/\/campaigns/)

    // The dashboard carries no revenue tile.
    await page.goto('/dashboard')
    await expect(page.getByTestId('kpi-revenue')).toHaveCount(0)
  })

  test('the API gives a client no phone, no email and no other project, and refuses what is not theirs', async () => {
    const session = await apiLogin(ALPHA.client.email)

    try {
      const body = JSON.stringify((await (await session.context.get('/api/v1/leads')).json()).data)
      expect(body).not.toMatch(/"phone"|"email"|"whatsapp"|"phone_normalized"/)

      for (const [method, path] of [['post', '/api/v1/leads'], ['get', '/api/v1/campaigns'], ['get', '/api/v1/users'], ['get', '/api/v1/integrations'], ['post', '/api/v1/work/outcome']] as const) {
        const response = method === 'get' ? await session.context.get(path) : await session.context.post(path, { data: {} })
        expect(response.status(), `${method} ${path}`).toBe(403)
      }

      // A lead in the project it was not given does not exist for it.
      const admin = await apiLogin(ALPHA.admin.email)
      try {
        const all = (await (await admin.context.get('/api/v1/leads?per_page=100')).json()).data as { id: string; project: { name: string } | null }[]
        const foreign = all.find((lead) => lead.project?.name === ALPHA.projects[1])!
        expect((await session.context.get(`/api/v1/leads/${foreign.id}`)).status()).toBe(404)
      } finally {
        await admin.dispose()
      }
    } finally {
      await session.dispose()
    }
  })

  test('a broker sees the second project and not the first — and only what its partner brought', async () => {
    // Linked to a partner who brought two leads of the second project.
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const partner = (await (await apiPost(admin, '/api/v1/partners', { name: 'وسيط الدور', type: 'broker' })).json()).data.partner.id as string
      artisan(['tinker', '--execute', `
        $p = \\App\\Models\\Partner::withoutGlobalScopes()->where('uuid', '${partner}')->firstOrFail();
        $project = \\App\\Models\\Project::withoutGlobalScopes()->where('name', '${ALPHA.projects[1]}')->firstOrFail();
        \\App\\Models\\Lead::withoutGlobalScopes()->where('project_id', $project->id)->orderBy('id')->limit(2)->update(['partner_id' => $p->id]);
        \\App\\Models\\User::withoutGlobalScopes()->where('email', '${ALPHA.broker.email}')->update(['partner_id' => $p->id]);
      `])
    } finally {
      await admin.dispose()
    }

    const list = await asApi<{ project: { name: string } | null }[]>(ALPHA.broker.email, '/api/v1/leads')

    expect(list).toHaveLength(2)
    expect(new Set(list.map((lead) => lead.project?.name))).toEqual(new Set([ALPHA.projects[1]]))
  })

  test('a project manager works its own project and keeps the phone number', async ({ page }) => {
    await authenticateAs(page, ALPHA.projectManager.email)
    await page.goto('/leads')

    const list = await asApi<{ id: string; project: { name: string } | null }[]>(ALPHA.projectManager.email, '/api/v1/leads')
    expect(new Set(list.map((lead) => lead.project?.name))).toEqual(new Set([ALPHA.projects[0]]))

    const session = await apiLogin(ALPHA.projectManager.email)
    try {
      const lead = (await (await session.context.get(`/api/v1/leads/${list[0].id}`)).json()).data.lead
      expect(lead.phone).toBeTruthy()
    } finally {
      await session.dispose()
    }

    const links = await railLinks(page)
    expect(links).toContain('/visits')
    expect(links).not.toContain('/companies')
    expect(links).not.toContain('/users')
  })

  test('marketing runs campaigns and shares reports but does not touch leads', async ({ page }) => {
    await authenticateAs(page, ALPHA.marketing.email)
    await page.goto('/dashboard')

    const links = await railLinks(page)
    for (const shown of ['/campaigns', '/tracking', '/reports']) expect(links).toContain(shown)
    for (const hidden of ['/work', '/users', '/companies']) expect(links, hidden).not.toContain(hidden)

    const session = await apiLogin(ALPHA.marketing.email)
    try {
      expect((await session.context.post('/api/v1/leads', { data: { full_name: 'x', phone: '0501234567' } })).status()).toBe(403)
      const shared = await session.context.post('/api/v1/report-shares', { data: { name: 'تسويق', template: 'marketing' } })
      expect(shared.status()).toBe(201)
    } finally {
      await session.dispose()
    }
  })

  test('a sales manager works the team and sees no ad spend', async ({ page }) => {
    await authenticateAs(page, ALPHA.salesManager.email)
    await page.goto('/dashboard')

    const links = await railLinks(page)
    for (const shown of ['/work', '/leads', '/visits', '/users']) expect(links).toContain(shown)
    for (const hidden of ['/campaigns', '/tracking', '/integrations']) expect(links, hidden).not.toContain(hidden)

    const session = await apiLogin(ALPHA.salesManager.email)
    try {
      expect((await session.context.get('/api/v1/campaigns')).status()).toBe(403)
      const lead = (await (await session.context.get('/api/v1/leads')).json()).data[0]
      expect(JSON.stringify((await (await session.context.get(`/api/v1/leads/${lead.id}`)).json()).data)).toMatch(/"phone"/)
    } finally {
      await session.dispose()
    }
  })

  test('the long-standing roles are exactly as they were', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    const rep = await railLinks(page)
    expect(rep).toContain('/work')
    expect(rep).not.toContain('/campaigns')
    expect(rep).not.toContain('/users')

    await authenticateAs(page, ALPHA.manager.email)
    await page.goto('/dashboard')
    const manager = await railLinks(page)
    for (const shown of ['/campaigns', '/reports', '/automation', '/users']) expect(manager).toContain(shown)
    expect(manager).not.toContain('/integrations')
  })

  test('an admin reads the matrix, gives a role and projects, and the person sees exactly that', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/users')

    await page.getByTestId('open-roles-matrix').click()
    const grid = page.getByTestId('roles-matrix-grid')
    await expect(grid).toBeVisible()
    for (const role of ['admin', 'manager', 'sales', 'marketing_manager', 'sales_manager', 'project_manager', 'finance', 'client_viewer', 'broker_viewer']) {
      await expect(page.getByTestId(`role-row-${role}`)).toBeVisible()
    }
    // Finance reads revenue but cannot edit a lead — the same table the server enforces.
    await expect(page.getByTestId('cell-finance-revenue')).toHaveAttribute('data-actions', 'view')
    await expect(page.getByTestId('cell-finance-leads')).toHaveAttribute('data-actions', 'view')
    await page.keyboard.press('Escape')

    // Make a client with the third project.
    await page.getByRole('button', { name: 'إضافة مستخدم' }).click()
    await page.getByLabel('الاسم', { exact: false }).first().fill('عميل جديد')
    await page.locator('input[type=email]').fill('newclient@e2e.test')
    await page.getByLabel('الصلاحية').selectOption('client_viewer')

    await expect(page.getByTestId('user-projects-none')).toBeVisible()
    await page.getByTestId('user-projects').getByRole('button', { name: ALPHA.projects[2] }).click()
    await expect(page.getByTestId('user-projects-none')).toHaveCount(0)
    // Nobody types a password for anyone: the client is invited, and chooses their own from the emailed link.
    await page.getByTestId('user-form-submit').click()
    await expect(page.getByTestId('invitation-newclient@e2e.test')).toBeVisible()

    const invite = linkFromEmail('newclient@e2e.test', 'invite')
    await page.goto(`/invite/${invite}`)
    await page.getByTestId('invite-name').fill('عميل جديد')
    await page.getByTestId('invite-password').fill('Password123!')
    await page.getByTestId('invite-confirm').fill('Password123!')
    await page.getByTestId('invite-accept').click()
    await expect(page).not.toHaveURL(/\/invite\//)

    await expect.poll(async () => (await asApi<{ project: { name: string } | null }[]>('newclient@e2e.test', '/api/v1/leads')).length).toBeGreaterThan(0)
    const seen = await asApi<{ project: { name: string } | null }[]>('newclient@e2e.test', '/api/v1/leads')
    expect(new Set(seen.map((lead) => lead.project?.name))).toEqual(new Set([ALPHA.projects[2]]))
  })
})
