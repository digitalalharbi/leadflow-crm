import { expect, test } from '@playwright/test'
import { ALPHA, PASSWORD } from '../fixtures/data'
import { setStoredToken, signIn, signOut, storedToken } from '../fixtures/pages'

test.describe('Authentication', () => {
  test('a valid sign-in lands on the dashboard with the tenant branding applied', async ({ page }) => {
    await page.goto(`/login?tenant=${ALPHA.slug}`)

    // The login screen must already be the customer's product, before anyone
    // has authenticated — that is the whole white-label promise.
    await expect(page.locator('body')).toContainText(ALPHA.systemName)

    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    await expect(page.getByTestId('page-title')).toBeVisible()
    await expect(page).toHaveURL(/\/dashboard$/)
    expect(await storedToken(page)).toBeTruthy()
  })

  test('a wrong password is rejected and nothing is stored', async ({ page }) => {
    await page.goto('/login')

    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill('definitely-not-the-password')
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    await expect(page.getByRole('alert').first()).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
    expect(await storedToken(page)).toBeNull()
  })

  test('an unknown email fails the same way a wrong password does', async ({ page }) => {
    // Identical feedback, so the form cannot be used to enumerate accounts.
    await page.goto('/login')
    await page.getByLabel('البريد الإلكتروني').fill('nobody@nowhere.test')
    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    const unknownMessage = await page.getByRole('alert').first().textContent()

    await page.goto('/login')
    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill('wrong-password-here')
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    expect(await page.getByRole('alert').first().textContent()).toBe(unknownMessage)
  })

  test('the session survives a reload', async ({ page }) => {
    await signIn(page, ALPHA.admin.email)

    await page.reload()

    await expect(page.getByTestId('page-title')).toBeVisible()
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('signing out clears the session and protects the app', async ({ page }) => {
    await signIn(page, ALPHA.admin.email)
    await signOut(page)

    expect(await storedToken(page)).toBeNull()

    // A direct URL must not get back in.
    await page.goto('/leads')
    await expect(page).toHaveURL(/\/login/)
  })

  test('a revoked token sends the user back to sign in', async ({ page }) => {
    await signIn(page, ALPHA.admin.email)

    await setStoredToken(page, 'clearly-not-a-valid-token')
    await page.goto('/leads')

    await expect(page).toHaveURL(/\/login/)
  })

  test('an anonymous visitor cannot reach a protected page', async ({ page }) => {
    for (const path of ['/dashboard', '/leads', '/pipeline', '/follow-ups', '/settings']) {
      await page.goto(path)
      await expect(page).toHaveURL(/\/login/)
    }
  })
})
