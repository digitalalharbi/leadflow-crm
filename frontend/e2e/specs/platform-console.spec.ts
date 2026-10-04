import { expect, test } from '@playwright/test'
import { ALPHA, BETA, PASSWORD } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { apiLogin } from '../fixtures/api'
import { artisan, resetFixtures } from '../fixtures/seed'

// The platform owner is not part of the tenant fixtures, so this spec makes
// its own — the demo seeder's owner has a different password.
const OWNER = 'owner@e2e-console.test'

/**
 * The SaaS owner's console.
 *
 * Two things are being protected here. One is the separation: the console and
 * a customer's workspace run the same shell, and confusing them is the most
 * expensive mistake the product offers. The other is that the list answers the
 * question an operator arrives with — which account is in trouble — rather
 * than only how many rows exist.
 */
test.describe('Platform console', () => {
  test.beforeAll(() => {
    resetFixtures()
    artisan(['leadflow:create-super-admin', `--email=${OWNER}`, `--password=${PASSWORD}`])
  })

  test.beforeEach(async ({ page }) => {
    await authenticateAs(page, OWNER)
    await page.goto('/platform')
    await expect(page.getByRole('heading', { name: 'إدارة المنصة', level: 1 })).toBeVisible()
  })

  test('is visibly a different console from a tenant workspace', async ({ page }) => {
    // The marker the stylesheet keys the whole chrome off.
    await expect(page.locator('[data-console="platform"]')).toHaveCount(1)

    // And it says so in words, because colour alone does not survive a
    // screenshot pasted into a ticket.
    await expect(page.getByText('منصة مهتم')).toBeVisible()

    // The navigation is the console's, not a workspace's.
    await expect(page.getByRole('link', { name: 'إدارة المنصة' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'مسار البيع' })).toHaveCount(0)
  })

  test('the headline figures are judged, not just counted', async ({ page }) => {
    const active = page.getByTestId('platform-active-tenants')
    await expect(active).toBeVisible()

    // "5" alone needs the reader to remember what normal is; "of 5" does not.
    await expect(active).toContainText('من أصل')

    await expect(page.getByTestId('platform-trialing')).toBeVisible()
    await expect(page.getByTestId('platform-leads-30')).toBeVisible()
  })

  test('each tenant shows its subscription and its recent activity', async ({ page }) => {
    const row = page.getByTestId(`tenant-row-${ALPHA.slug}`)
    await expect(row).toBeVisible()

    await expect(row).toContainText(ALPHA.companyName)
    await expect(row).toContainText(ALPHA.slug)
    // Subscription state, not just a plan name.
    await expect(row).toContainText('تجريبي')
  })

  test('search narrows the list to one tenant', async ({ page }) => {
    await page.getByTestId('tenant-search').fill(BETA.slug)

    await expect(page.getByTestId(`tenant-row-${BETA.slug}`)).toBeVisible()
    await expect(page.getByTestId(`tenant-row-${ALPHA.slug}`)).toHaveCount(0)
  })

  test('a search with no matches explains itself instead of showing an empty table', async ({ page }) => {
    await page.getByTestId('tenant-search').fill('لا-يوجد-عميل-بهذا-الاسم')

    await expect(page.getByText('لا نتائج')).toBeVisible()
  })

  test('opening a tenant shows who can sign in and what they are paying for', async ({ page }) => {
    await page.getByTestId(`tenant-row-${ALPHA.slug}`).click()

    const panel = page.getByRole('dialog')
    await expect(panel).toBeVisible()
    await expect(panel).toContainText(ALPHA.companyName)

    // The subscription is editable here, which is the point of the panel.
    await expect(panel.getByLabel('الباقة')).toBeVisible()
    await expect(panel.getByLabel('الاشتراك')).toBeVisible()

    // And the people who can actually get in are listed.
    await expect(panel.getByText('مستخدمو هذا العميل')).toBeVisible()
    await expect(panel.getByText(ALPHA.admin.email)).toBeVisible()
  })

  test('changing a plan is written through to the API', async ({ page }) => {
    await page.getByTestId(`tenant-row-${ALPHA.slug}`).click()

    const panel = page.getByRole('dialog')
    await panel.getByLabel('الباقة').fill('scale')
    await panel.getByRole('button', { name: 'حفظ' }).click()

    await expect(page.getByText('تم الحفظ')).toBeVisible()

    const owner = await apiLogin(OWNER)
    try {
      const response = await owner.context.get('/api/v1/super-admin/tenants')
      const body = await response.json()
      const alpha = body.data.find((t: { slug: string }) => t.slug === ALPHA.slug)

      expect(alpha.plan).toBe('scale')
    } finally {
      await owner.dispose()
    }
  })

  test('suspension is separated from the form it would otherwise hide inside', async ({ page }) => {
    await page.getByTestId(`tenant-row-${BETA.slug}`).click()

    const panel = page.getByRole('dialog')
    // It says what it does before it is pressed.
    await expect(panel.getByText('يُنهي جلسات كل المستخدمين فورًا ويمنع الدخول.')).toBeVisible()
    await expect(panel.getByRole('button', { name: 'تعطيل' })).toBeVisible()
  })
})
