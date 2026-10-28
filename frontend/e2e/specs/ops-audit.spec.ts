import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The operations home, the history of who changed what, and the box that finds and does. */
test.describe('Operations home, history and the command palette', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('the home answers the nine questions and shows four lights, each a link to what it counts', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    for (const key of ['leads_today', 'interested', 'hot', 'needs_human', 'visits_today', 'reservations_pending', 'won_today', 'revenue_today', 'sla_late']) {
      await expect(page.getByTestId(`ops-${key}`)).toBeVisible()
    }
    for (const key of ['automation', 'tracking', 'campaigns', 'backlog']) {
      await expect(page.getByTestId(`health-${key}`)).toBeVisible()
    }

    // Who automation handed over and nobody has picked up opens exactly that list.
    await page.getByTestId('ops-needs_human').click()
    await expect(page).toHaveURL(/\/leads\?view=needs_human/)
  })

  test('a rep sees their own nine answers and no health lights', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    await expect(page.getByTestId('ops-answers')).toBeVisible()
    await expect(page.getByTestId('ops-health')).toHaveCount(0)
  })

  test('history lists who did what, from what to what, and whether a person or the machine did it', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      // Something a person does.
      const partner = await admin.context.post('/api/v1/partners', { data: { name: 'وسيط السجل', type: 'broker' } })
      expect(partner.ok()).toBeTruthy()
    } finally {
      await admin.dispose()
    }

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/audit')

    await expect(page.getByTestId('audit-list')).toBeVisible()
    const row = page.getByTestId('audit-partner.created').first()
    await expect(row).toBeVisible()
    await expect(row).toHaveAttribute('data-category', 'campaigns')
    await expect(row).toHaveAttribute('data-source', 'user')

    // Filters narrow it.
    await page.getByTestId('audit-cat-automation').click()
    await expect(page.getByTestId('audit-cat-automation')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('audit-partner.created')).toHaveCount(0)
  })

  test('only managers have a history', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/audit')
    await expect(page).not.toHaveURL(/\/audit/)
  })

  test('the palette finds units, campaigns and partners — and offers what to do, only what the role may', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    await page.getByTestId('open-command-palette').click()

    // Nothing typed: the things to do.
    const results = page.getByTestId('command-palette-results')
    await expect(results).toBeVisible()
    for (const action of ['إنشاء عميل', 'حجز زيارة', 'إنشاء تقرير', 'فتح الأتمتة', 'فتح مشروع']) {
      await expect(results).toContainText(action)
    }

    await results.getByText('إنشاء عميل').click()
    await expect(page).toHaveURL(/\/leads\?new=1/)
    await expect(page.getByRole('dialog')).toBeVisible()
  })

  test('typing finds a partner and opens the page that lists them', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      await admin.context.post('/api/v1/partners', { data: { name: 'وسيط البحث السريع', type: 'broker' } })
    } finally {
      await admin.dispose()
    }

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill('البحث السريع')
    const results = page.getByTestId('command-palette-results')
    await expect(results).toContainText('وسيط البحث السريع')
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/partners/)
  })

  test('a rep is not offered reports or automation in the palette', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    await page.getByTestId('open-command-palette').click()
    const results = page.getByTestId('command-palette-results')
    await expect(results).toContainText('إنشاء عميل')
    await expect(results).not.toContainText('إنشاء تقرير')
    await expect(results).not.toContainText('فتح الأتمتة')
  })
})
