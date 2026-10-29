import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * https://mohtam.pro/ is the marketing page, /login is the login, and the work starts at /dashboard — for everybody, in every
 * state: a visitor, a signed-in person, a platform owner. Nobody is bounced, nothing loops, and a deep link survives a sign-in.
 */
test.describe('Marketing home and login routing', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('/ is the marketing page, not the login and not the dashboard', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/^[^?#]*\/$/)
    await expect(page.getByTestId('landing-headline')).toHaveText('حوّل الاهتمام إلى فرصة')
    await expect(page.getByTestId('landing-chain-steps')).toContainText('الحملات التسويقية')
    await expect(page.getByTestId('landing-chain-steps')).toContainText('الإيرادات')
    await expect(page.getByTestId('feature-card')).toHaveCount(14)
    await expect(page.getByTestId('funnel-stage')).toHaveCount(6)
    await expect(page.getByTestId('page-title')).toHaveCount(0)
  })

  test('the calls to action go to the login', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('landing-trial')).toHaveText('ابدأ مع مهتم')
    await expect(page.getByTestId('landing-trial')).toHaveAttribute('href', '/login')
    await expect(page.getByTestId('landing-login')).toHaveText('تسجيل الدخول')
    await expect(page.getByTestId('landing-login')).toHaveAttribute('href', '/login')
    await page.getByTestId('landing-login').click()
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByLabel('البريد الإلكتروني')).toBeVisible()
  })

  test('signing in lands on the dashboard, and the marketing page stays the marketing page for a signed-in person', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill('Password123!')
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByTestId('page-title')).toBeVisible()

    // Open the front door while signed in: it is still the marketing page, with a button to the dashboard.
    await page.goto('/')
    await expect(page).toHaveURL(/^[^?#]*\/$/)
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    await expect(page.getByTestId('landing-trial')).toHaveText('فتح لوحة التحكم')
    await expect(page.getByTestId('landing-login')).toHaveCount(0)
    await page.getByTestId('landing-trial').click()
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByTestId('page-title')).toBeVisible()
  })

  test('the login of a signed-in person goes to the dashboard without a loop', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/login')
    await expect(page).toHaveURL(/\/dashboard$/)
    await expect(page.getByTestId('page-title')).toBeVisible()
  })

  test('a deep link survives the sign-in, and an unknown address goes to the dashboard, never to a loop', async ({ page }) => {
    await page.goto('/leads')
    await expect(page).toHaveURL(/\/login/)
    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill('Password123!')
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()
    await expect(page).toHaveURL(/\/leads/)

    await page.goto('/no-such-page')
    await expect(page).toHaveURL(/\/dashboard$/)
  })

  test('the old /welcome address still works and lands on the front door', async ({ page }) => {
    await page.goto('/welcome')
    await expect(page).toHaveURL(/^[^?#]*\/$/)
    await expect(page.getByTestId('landing-headline')).toBeVisible()
  })

  test('the logo inside the app goes to the dashboard, not back to the marketing page', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await expect(page).toHaveURL(/\/dashboard$/)
    await page.goto('/leads')
    await page.locator('a[href="/dashboard"]').first().click()
    await expect(page).toHaveURL(/\/dashboard$/)
  })

  test('SEO: Arabic title, description, Open Graph and the canonical address on /, and the login is noindex', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    const content = (selector: string) => page.locator(selector).first().getAttribute('content')

    expect(await page.title()).toBe('مهتم — حوّل الاهتمام إلى فرصة')
    expect(await content('meta[name="description"]')).toContain('مهتم منصة تربط الحملات التسويقية')
    expect(await content('meta[name="robots"]')).toBe('index, follow')
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toBe('https://mohtam.pro/')
    expect(await content('meta[property="og:title"]')).toBe('مهتم — حوّل الاهتمام إلى فرصة')
    expect(await content('meta[property="og:description"]')).toContain('مهتم منصة تربط الحملات التسويقية')
    expect(await content('meta[property="og:url"]')).toBe('https://mohtam.pro/')
    expect(await content('meta[property="og:type"]')).toBe('website')
    expect(await content('meta[property="og:locale"]')).toBe('ar_SA')
    expect(await content('meta[property="og:image"]')).toBe('https://mohtam.pro/brand/social-preview.png')

    await page.goto('/login')
    await expect(page.getByLabel('البريد الإلكتروني')).toBeVisible()
    expect(await content('meta[name="robots"]')).toBe('noindex, nofollow')

    // Leaving /login for the front door makes the front door indexable again.
    await page.goto('/')
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    expect(await content('meta[name="robots"]')).toBe('index, follow')
  })

  test('robots.txt keeps the login and the app out and the front door in; the sitemap names the front door', async ({ request }) => {
    const robots = await (await request.get('/robots.txt')).text()
    expect(robots).toContain('Disallow: /login')
    expect(robots).toContain('Disallow: /dashboard')
    expect(robots).toContain('Allow: /$')
    expect(robots).toContain('Sitemap: https://mohtam.pro/sitemap.xml')
    expect(await (await request.get('/sitemap.xml')).text()).toContain('<loc>https://mohtam.pro/</loc>')
  })

  test('RTL: the page reads right to left, with the headline on the reading edge', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    const box = (await page.getByTestId('landing-headline').boundingBox())!
    expect(box.x + box.width / 2).toBeGreaterThan(page.viewportSize()!.width / 2)
  })

  test('390px: the whole page fits, the menu offers the way in, no sideways scroll', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/')
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    await page.getByTestId('feature-card').last().scrollIntoViewIfNeeded()
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)

    await page.getByTestId('public-menu-toggle').click()
    await expect(page.getByTestId('public-menu-entry')).toHaveAttribute('href', '/login')
  })

  test('the platform owner still has their own door, and a company login does not open it', async ({ page }) => {
    await page.goto('/platform/login')
    await expect(page.getByTestId('platform-login-form').or(page.getByLabel('البريد الإلكتروني')).first()).toBeVisible()
    await expect(page).toHaveURL(/\/platform\/login$/)
    expect(await page.locator('meta[name="robots"]').first().getAttribute('content')).toBe('noindex, nofollow')
  })
})
