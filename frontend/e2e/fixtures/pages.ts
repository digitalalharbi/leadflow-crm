import { expect, type Page } from '@playwright/test'
import { apiLogin, apiPlatformLogin } from './api'
import { PASSWORD } from './data'

/**
 * Page helpers.
 *
 * Deliberately thin — they wrap the handful of interactions every spec repeats
 * (sign in, open a lead, wait for a list) rather than abstracting the whole UI
 * behind an object model that hides what a test is actually doing.
 */

/**
 * Puts a real session into the page without driving the login form.
 *
 * Signing in through the UI costs a bcrypt round trip per test and makes the
 * whole suite hostage to one screen. The login flow has its own spec; every
 * other test only needs to *be* signed in, so it gets a token from the API and
 * injects it before the app boots.
 */
export async function authenticateAs(page: Page, email: string, password: string = PASSWORD, platform = false) {
  const session = platform ? await apiPlatformLogin(email, password) : await apiLogin(email, password)

  try {
    await page.addInitScript((token) => {
      try {
        localStorage.setItem('leadflow.token', token)
      } catch {
        /* private mode — the test will surface it as a redirect to /login */
      }
    }, session.token)
  } finally {
    await session.dispose()
  }
}

/**
 * Signs in and lands on the app, without touching the login form.
 *
 * Deliberately does not wait for a particular heading: the dashboard greets
 * the user by name when it knows it, the platform owner gets the console
 * instead, and both of those are copy. What proves the session took is that
 * the app rendered a page rather than bouncing to the login screen.
 */
export async function signInAs(page: Page, email: string, password: string = PASSWORD) {
  await authenticateAs(page, email, password)
  await page.goto('/dashboard')
  await expect(page.getByTestId('page-title')).toBeVisible()
  await expect(page).not.toHaveURL(/\/login/)
}

/** The platform owner, signed in through the platform's own door. */
export function authenticateAsPlatform(page: Page, email: string, password: string = PASSWORD) {
  return authenticateAs(page, email, password, true)
}

/** Drives the platform's own login form. */
export async function signInPlatform(page: Page, email: string, password: string = PASSWORD) {
  await page.goto('/platform/login')
  await page.getByLabel('البريد الإلكتروني').fill(email)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
  await expect(page).toHaveURL(/\/platform$/)
  await expect(page.getByTestId('page-title')).toBeVisible()
}

/** Drives the real login form. Used by the authentication spec itself. */
export async function signIn(page: Page, email: string, password: string = PASSWORD, tenant?: string) {
  await page.goto(tenant ? `/login?tenant=${tenant}` : '/login')

  await page.getByLabel('البريد الإلكتروني').fill(email)
  await page.getByLabel('كلمة المرور', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

  // Where a session lands depends on who it belongs to: a tenant user gets the
  // dashboard, the platform owner gets the console. Either is a landing.
  await expect(page.getByTestId('page-title')).toBeVisible()
  await expect(page).not.toHaveURL(/\/login/)
}

export async function signOut(page: Page) {
  await page.getByTestId('account-menu').click()
  await page.getByTestId('sign-out').click()
  await expect(page).toHaveURL(/\/login/)
}

export async function gotoLeads(page: Page) {
  await page.goto('/leads')
  await expect(page.getByRole('heading', { name: 'العملاء المحتملون', level: 1 })).toBeVisible()
  await waitForLeadsTable(page)
}

/** Waits for the table to settle rather than for an arbitrary timeout. */
export async function waitForLeadsTable(page: Page) {
  await expect(page.locator('table tbody tr').first().or(page.getByText('لا يوجد عملاء محتملون حتى الآن'))).toBeVisible()
}

export async function openLeadByName(page: Page, name: string) {
  await gotoLeads(page)
  await page.getByTestId('lead-search').fill(name)
  await expect(page.getByRole('link', { name })).toBeVisible()
  await page.getByRole('link', { name }).click()
  await expect(page.getByRole('heading', { name })).toBeVisible()
}

/** The token the SPA persists — used to assert session behaviour. */
export async function storedToken(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    try {
      return localStorage.getItem('leadflow.token')
    } catch {
      return null
    }
  })
}

export async function setStoredToken(page: Page, token: string | null) {
  await page.evaluate((value) => {
    if (value === null) localStorage.removeItem('leadflow.token')
    else localStorage.setItem('leadflow.token', value)
  }, token)
}
