import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, apiPost } from '../fixtures/api'
import { ALPHA, LEADS } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The phone layout, which is where a sales rep actually works.
 *
 * Only the screens a rep uses between visits are covered here; re-running the
 * whole suite at a second viewport would cost minutes to catch the same bugs.
 */
test.describe('Mobile layout', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('the dashboard fits the screen with no sideways scrolling', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'مهام اليوم' })).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('the sidebar is a drawer that opens and closes', async ({ page }) => {
    const nav = page.getByRole('link', { name: 'العملاء المحتملون' })
    await expect(nav).not.toBeInViewport()

    // "More" on the tab bar opens the full list, the way an app's last tab does.
    await page.getByTestId('bottom-nav-more').click()
    await expect(nav).toBeInViewport()

    await nav.click()
    await expect(page).toHaveURL(/\/leads/)
    // Choosing a destination closes the drawer rather than leaving it over the page.
    await expect(page.getByRole('heading', { name: 'العملاء المحتملون', level: 1 })).toBeVisible()
  })

  test('it has a tab bar at the thumb, like an app, and the header is a flat bar', async ({ page }) => {
    const bar = page.getByTestId('bottom-nav')
    await expect(bar).toBeVisible()

    // Four places and "More"; the current one is marked.
    await expect(bar.getByRole('link')).toHaveCount(4)
    await expect(bar.getByTestId('bottom-tab-home')).toHaveClass(/bottom-tab-active/)
    // A manager's day: dashboard, work now, projects, reports.
    await bar.getByTestId('bottom-tab-projects').click()
    await expect(page).toHaveURL(/\/projects/)
    await expect(bar.getByTestId('bottom-tab-projects')).toHaveClass(/bottom-tab-active/)
    await page.goto('/leads')

    // Pinned to the bottom edge, and tall enough for a thumb.
    const box = (await bar.boundingBox())!
    const viewport = page.viewportSize()!
    expect(Math.abs(box.y + box.height - viewport.height)).toBeLessThanOrEqual(1)
    expect(box.height).toBeGreaterThanOrEqual(56)

    // The header is a bar across the top, not a floating capsule, and has no menu button of its own.
    const header = (await page.getByTestId('app-header').boundingBox())!
    expect(header.x).toBeLessThanOrEqual(1)
    expect(Math.round(header.width)).toBe(viewport.width)
    expect(header.y).toBeLessThanOrEqual(1)

    // The last thing on a long page is not hidden behind the bar.
    await expect(page.locator('table tbody tr').first()).toBeVisible()
    await expect.poll(async () => {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))

      return page.evaluate(() => document.querySelector('main')!.getBoundingClientRect().bottom)
    }).toBeLessThanOrEqual(box.y + 1)
  })

  test('typing hides the tab bar so it does not sit on the keyboard', async ({ page }) => {
    await page.goto('/leads')
    await expect(page.getByTestId('bottom-nav')).toBeVisible()
    await page.getByTestId('lead-search').focus()
    await expect(page.getByTestId('bottom-nav')).toHaveCount(0)
    await page.getByTestId('lead-search').blur()
    await expect(page.getByTestId('bottom-nav')).toBeVisible()
  })

  test('the leads list shows stage and interest without horizontal scrolling', async ({ page }) => {
    await page.goto('/leads')
    await expect(page.locator('table tbody tr').first()).toBeVisible()

    // Status and temperature move under the name on a phone, so the two things
    // a rep scans for are visible without swiping the table.
    const firstRow = page.locator('table tbody tr').first()
    await expect(firstRow.getByText(/جديد|تم التواصل|مؤهل|موعد|تم البيع|فرصة خاسرة/).first()).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('a lead can be worked from the phone', async ({ page }) => {
    await page.goto('/leads')
    // Click only once the list has re-rendered for the search: a link replaced mid-click navigates nowhere.
    const searched = page.waitForResponse((r) => r.url().includes('/api/v1/leads') && r.url().includes('search=') && r.ok())
    await page.getByTestId('lead-search').fill(LEADS.activity)
    await searched
    // The page scrolls smoothly: bring the row fully into view and let the scroll finish before the tap, or the tap
    // is aimed at where the row was mid-glide.
    const row = page.getByRole('link', { name: LEADS.activity })
    await expect(row).toHaveCount(1)
    // Centred, not merely inside the viewport: a row at the very bottom edge sits behind the fixed tab bar, and the tap
    // lands on the bar (seen in CI at y=825 of 839) and opens nothing.
    await row.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }))
    await expect(row).toBeInViewport({ ratio: 1 })
    // The figures above the list are re-read for the search and arrive after the rows: when they land the row moves,
    // and a tap aimed at where it was opens nothing. Wait until it has stopped moving.
    await expect.poll(async () => {
      const first = await row.boundingBox()
      await page.waitForTimeout(250)
      const second = await row.boundingBox()

      return first !== null && first.y === second?.y
    }).toBe(true)
    await row.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }))
    await row.click()

    // The phone's own lead screen: the four ways to reach them at the top, the same four in a bar that
    // stays at the bottom — none of it needs scrolling into the page body.
    await expect(page.getByTestId('mobile-lead')).toBeVisible()
    await expect(page.getByTestId('quick-call')).toBeInViewport()
    await expect(page.getByTestId('quick-whatsapp')).toBeVisible()
    await expect(page.getByTestId('quick-message')).toBeVisible()
    await expect(page.getByTestId('sales-bar')).toBeInViewport()
    await expect(page.getByTestId('sales-outcome')).toBeVisible()
  })

  /**
   * The screens an admin opens, swept for sideways scroll.
   *
   * The rep's screens above are checked one by one because each has something
   * specific to assert. These have one thing in common and it is the one that
   * breaks: a grid item's automatic minimum size is its min-content width, so a
   * single card holding something that does not wrap — a webhook URL — sized
   * the column to 689px inside a 343px screen and pushed the whole integrations
   * page off to the side. The `truncate` on it could not help, because the
   * column had already been sized to fit it.
   *
   * Cheap: a handful of navigations, not a second run of the suite.
   */
  for (const path of ['/integrations', '/automation', '/reports', '/data-quality', '/settings', '/users', '/visits', '/work']) {
    test(`${path} fits the screen with no sideways scrolling`, async ({ page }) => {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow, `${path} scrolls sideways on a phone`).toBeLessThanOrEqual(1)
    })
  }

  test('the follow-ups page is usable on a phone', async ({ page }) => {
    await page.goto('/follow-ups?bucket=overdue')

    await expect(page.locator('ul > li').first()).toBeVisible()
    await expect(page.locator('ul > li').first().getByRole('button', { name: 'إتمام' })).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('More is a full screen, grouped for a thumb, with who you are and signing out', async ({ page }) => {
    await page.getByTestId('bottom-nav-more').click()
    const menu = page.getByTestId('more-menu')
    await expect(menu).toBeInViewport()

    const box = (await menu.boundingBox())!
    expect(Math.round(box.width)).toBe(page.viewportSize()!.width)
    await expect(page.getByTestId('more-identity')).toBeVisible()
    await expect(page.getByTestId('more-logout')).toBeAttached()

    for (const row of await menu.getByRole('navigation').getByRole('link').all()) {
      const height = (await row.boundingBox())?.height
      if (height) expect(height).toBeGreaterThanOrEqual(44)
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)
  })

  test('notifications open as a whole screen, and close again', async ({ page }) => {
    await page.getByTestId('notifications-toggle').click()
    const center = page.getByTestId('notification-center')
    await expect(center).toBeVisible()
    expect(Math.round((await center.boundingBox())!.width)).toBe(page.viewportSize()!.width)
    await page.getByTestId('notifications-close').click()
    await expect(center).toHaveCount(0)
  })

  test('a landing page is managed from a phone: status, link, text; the layout waits for a larger screen', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const projects = await apiGet<{ id: string }[] | { projects: { id: string }[] }>(admin, '/api/v1/projects')
    const projectId = (Array.isArray(projects) ? projects : projects.projects)[0].id
    const slug = `phone-${Date.now()}`
    const made = await apiPost(admin, '/api/v1/tracking/forms', {
      name: 'صفحة الهاتف', project_id: projectId, template: 'real_estate_interest', slug, consent_required: false, headline: 'عنوان',
      page: { template: 'real_estate_lead', tags: [], facts: [], cta: { form: { enabled: true }, whatsapp: { enabled: false }, phone: { enabled: false }, primary: 'form' } },
    })
    expect(made.ok(), await made.text()).toBeTruthy()
    const id = (await made.json()).data.form.id
    const published = await apiPost(admin, `/api/v1/tracking/forms/${id}/publish`)
    expect(published.ok(), await published.text()).toBeTruthy()

    await page.goto('/tracking')
    await page.getByTestId('tracking-tabs').getByRole('tab', { name: 'النماذج' }).click()
    await page.getByTestId(`form-${id}`).scrollIntoViewIfNeeded()
    await expect(page.getByTestId(`copy-form-link-${id}`)).toBeVisible()
    await expect(page.getByTestId(`form-leads-${id}`)).toBeVisible()
    await expect(page.getByTestId(`view-form-${id}`)).toHaveAttribute('href', `/l/${slug}`)

    await page.goto(`/tracking/forms/${id}`)
    await expect(page.getByTestId('builder-phone-note')).toBeVisible()
    await expect(page.getByTestId('add-section')).toBeHidden()
    await expect(page.getByTestId('form-settings-tabs')).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
  })

  test('fields are thumb-sized on a phone and do not make iOS zoom', async ({ page }) => {
    await page.goto('/leads')
    const field = page.locator('input.field').first()
    await expect(field).toBeVisible()
    const box = await field.evaluate((el) => { const s = getComputedStyle(el); return { height: parseFloat(s.height), font: parseFloat(s.fontSize) } })
    expect(box.height).toBeGreaterThanOrEqual(48)
    expect(box.font).toBeGreaterThanOrEqual(16)
  })
})
