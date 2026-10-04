import { expect, test } from '@playwright/test'
import { ALPHA, BETA, PASSWORD } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The colour the SPA is actually painting with, read off the live document. */
async function brandColour(page: import('@playwright/test').Page): Promise<string> {
  return page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--brand').trim(),
  )
}

test.describe('White label', () => {
  test.beforeAll(() => resetFixtures())

  test('each tenant gets its own identity on the login screen', async ({ page }) => {
    await page.goto(`/login?tenant=${ALPHA.slug}`)
    await expect(page.getByText(ALPHA.systemName).first()).toBeVisible()
    await expect(page.getByText(ALPHA.companyName)).toBeVisible()
    expect(await brandColour(page)).toBe(ALPHA.primaryColor)

    await page.goto(`/login?tenant=${BETA.slug}`)
    await expect(page.getByText(BETA.systemName).first()).toBeVisible()
    await expect(page.getByText(BETA.companyName)).toBeVisible()
    expect(await brandColour(page)).toBe(BETA.primaryColor)
  })

  test('the two tenants really are branded differently', async ({ page }) => {
    // Branding is fetched after first paint, so wait for it to land before
    // reading the colour — otherwise both reads return the default.
    await page.goto(`/login?tenant=${ALPHA.slug}`)
    await expect(page.getByText(ALPHA.companyName)).toBeVisible()
    const alphaColour = await brandColour(page)

    await page.goto(`/login?tenant=${BETA.slug}`)
    await expect(page.getByText(BETA.companyName)).toBeVisible()
    const betaColour = await brandColour(page)

    expect(alphaColour).not.toBe(betaColour)
    expect(ALPHA.systemName).not.toBe(BETA.systemName)
  })

  test('the tenant logo is used when one is configured', async ({ page }) => {
    await page.goto(`/login?tenant=${BETA.slug}`)

    const logo = page.locator('img').first()
    await expect(logo).toBeVisible()
    await expect(logo).toHaveAttribute('src', /b91c1c/)
  })

  test('the branding follows the user into the app', async ({ page }) => {
    await signInAs(page, BETA.admin.email)

    await expect(page.getByText(BETA.systemName).first()).toBeVisible()
    expect(await brandColour(page)).toBe(BETA.primaryColor)

    // The document title is the customer's product name, not ours.
    await expect(page).toHaveTitle(BETA.systemName)
  })

  test('the public branding endpoint exposes nothing operational', async ({ request }) => {
    const response = await request.get(
      `${process.env.E2E_API_URL ?? 'http://127.0.0.1:8000'}/api/v1/branding?tenant=${BETA.slug}`,
    )

    expect(response.ok()).toBeTruthy()
    const { data } = await response.json()

    expect(data.system_name).toBe(BETA.systemName)

    for (const forbidden of ['plan', 'subscription_status', 'users', 'leads', 'credentials']) {
      expect(data).not.toHaveProperty(forbidden)
    }
  })

  test('an unknown tenant falls back to the default identity rather than erroring', async ({ page }) => {
    await page.goto('/login?tenant=does-not-exist-at-all')

    await expect(page.getByRole('heading', { name: 'مرحباً بعودتك' })).toBeVisible()
    await expect(page.getByLabel('البريد الإلكتروني')).toBeVisible()
  })

  test('an admin can rebrand their workspace and it takes effect immediately', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/settings')

    // Settings is sections now, and identity is one of them.
    await page.getByTestId('settings-tab-branding').click()

    await page.getByLabel('اسم النظام').fill('ألفا بعد التغيير')
    // fill() drives the control the way a user does; assigning .value directly
    // is swallowed by React's value tracker.
    await page.getByLabel('اللون الأساسي').fill('#7c3aed')

    await page.getByRole('button', { name: 'حفظ' }).first().click()
    await expect(page.getByText('تم الحفظ.')).toBeVisible()

    // The sidebar picks up the new name without a reload…
    await expect(page.getByText('ألفا بعد التغيير').first()).toBeVisible()

    // …and the change is what a brand-new visitor sees on the login screen.
    const session = await apiLogin(ALPHA.admin.email, PASSWORD)
    try {
      const branding = await apiGet<{ system_name: string; primary_color: string }>(
        session,
        `/api/v1/branding?tenant=${ALPHA.slug}`,
      )
      expect(branding.system_name).toBe('ألفا بعد التغيير')
      expect(branding.primary_color.toLowerCase()).toBe('#7c3aed')
    } finally {
      await session.dispose()
    }
  })
})
