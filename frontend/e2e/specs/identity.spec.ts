import { expect, test } from '@playwright/test'
import { ALPHA, BETA, PLATFORM_TITLE } from '../fixtures/data'
import { authenticateAs, signInAs } from '../fixtures/pages'

/** What the browser chrome itself says about whose product this is. */
async function identity(page: import('@playwright/test').Page) {
  return page.evaluate(() => ({
    title: document.title,
    icon: document.querySelector<HTMLLinkElement>("link[rel='icon']")?.getAttribute('href') ?? null,
    theme:
      document.querySelector<HTMLMetaElement>("meta[name='theme-color']")?.getAttribute('content') ?? null,
    brand: getComputedStyle(document.documentElement).getPropertyValue('--brand').trim(),
  }))
}

test.describe('Product identity', () => {
  test('the platform identity is what an unbranded session gets', async ({ page }) => {
    await page.goto('/login')

    const before = await identity(page)

    expect(before.title).toBe(PLATFORM_TITLE)
    expect(before.icon).toBe('/favicon.svg')
  })

  test('a tenant owns the tab, not just the page', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    const applied = await identity(page)

    // This workspace has not renamed the product, so the tab carries the platform's own title.
    expect(applied.title).toBe(PLATFORM_TITLE)
    expect(applied.brand.toLowerCase()).toBe(ALPHA.primaryColor)
    // The phone's browser chrome follows the customer too.
    expect(applied.theme?.toLowerCase()).toBe(ALPHA.primaryColor)
  })

  test('two tenants do not share an identity', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    const alpha = await identity(page)

    await page.context().clearCookies()
    await page.evaluate(() => localStorage.clear())

    await authenticateAs(page, BETA.admin.email)
    await page.goto('/')
    await expect(page.getByTestId('page-title')).toBeVisible()
    const beta = await identity(page)

    expect(beta.title).toBe(BETA.systemName)
    expect(beta.title).not.toBe(alpha.title)
    expect(beta.brand).not.toBe(alpha.brand)
  })

  /*
   * The leak this guards: a tenant with its own favicon leaving it behind for
   * the next session, so an unbranded customer sees someone else's mark.
   */
  test('the platform icon comes back for a tenant with none of its own', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    const { icon } = await identity(page)

    // Alpha supplies no favicon, so the platform's is what must be showing —
    // not whatever the previous session resolved to.
    expect(icon).toBe('/favicon.svg')
  })

  test('the theme can be switched and survives a reload', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)

    // Appearance lives in the account menu now, beside language.
    await page.getByTestId('account-menu').click()
    await page.getByTestId('appearance-dark').click()

    await expect(page.locator('html')).toHaveClass(/dark/)

    await page.reload()
    await expect(page.getByTestId('page-title')).toBeVisible()
    await expect(page.locator('html')).toHaveClass(/dark/)
  })
})
