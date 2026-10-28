import { expect, test, type Page } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The official identity, everywhere a browser shows it: مهتم / Mohtam, حوّل الاهتمام إلى فرصة / Turn Interest Into Opportunity,
 * `<screen> — مهتم` in every tab, the official icon in every tab and on the home screen, the official card in every link preview.
 */
test.describe('Brand identity', () => {
  test.beforeAll(() => resetFixtures())

  const english = async (page: Page) => page.addInitScript(() => localStorage.setItem('leadflow.locale', 'en'))

  test('the marketing page and the login carry the official titles in Arabic', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveTitle('مهتم — حوّل الاهتمام إلى فرصة')
    await expect(page.getByTestId('landing-brand')).toHaveText('مهتم')
    await expect(page.getByTestId('landing-headline')).toHaveText('حوّل الاهتمام إلى فرصة')

    await page.goto('/login')
    await expect(page).toHaveTitle('تسجيل الدخول — مهتم')
    await expect(page.getByTestId('logo').first()).toHaveAttribute('aria-label', 'مهتم')
    await expect(page.locator('body')).toContainText('حوّل الاهتمام إلى فرصة')
  })

  test('and in English: Mohtam — Turn Interest Into Opportunity, Sign In — Mohtam', async ({ page }) => {
    await english(page)
    await page.goto('/')
    await expect(page).toHaveTitle('Mohtam — Turn Interest Into Opportunity')
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    await expect(page.getByTestId('landing-brand')).toHaveText('Mohtam')
    await expect(page.getByTestId('landing-headline')).toHaveText('Turn Interest Into Opportunity')
    await expect(page.getByTestId('landing-trial')).toHaveText('Get Started with Mohtam')
    await expect(page.getByTestId('landing-login')).toHaveText('Sign In')

    await page.goto('/login')
    await expect(page).toHaveTitle('Sign In — Mohtam')
    await expect(page.getByTestId('logo').first()).toHaveAttribute('aria-label', 'Mohtam')
  })

  test('every screen of the app names itself `<screen> — مهتم`, with a dash and never a pipe', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    const expected: Array<[string, string]> = [
      ['/dashboard', 'لوحة التحكم — مهتم'], ['/leads', 'العملاء المحتملون — مهتم'], ['/projects', 'المشاريع — مهتم'],
      ['/reports', 'التقارير — مهتم'], ['/campaigns', 'الحملات — مهتم'], ['/settings', 'الإعدادات — مهتم'],
    ]

    for (const [path, title] of expected) {
      await page.goto(path)
      await expect(page, path).toHaveTitle(title)
      expect(await page.title(), path).not.toContain('|')
    }
  })

  test('an English session names the screens in English', async ({ page }) => {
    await english(page)
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    await expect(page).toHaveTitle('Dashboard — Mohtam')
    await page.goto('/leads')
    await expect(page).toHaveTitle('Leads — Mohtam')
  })

  test('a project page is named after the project', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/projects')
    const first = page.locator('a[href^="/projects/"]').first()
    await first.click()
    await expect(page.getByTestId('page-title')).toBeVisible()
    const name = (await page.getByTestId('page-title').innerText()).trim()
    await expect(page).toHaveTitle(`${name} — مهتم`)
  })

  test('the favicon, the home-screen icons and the social card are the official ones and are actually served', async ({ page, request }) => {
    await page.goto('/')
    const icons = await page.locator("link[rel='icon']").evaluateAll((links) => links.map((l) => l.getAttribute('href')))
    expect(icons).toEqual(expect.arrayContaining(['/brand/favicon.ico?v=2', '/brand/favicon.svg?v=2', '/brand/favicon-32x32.png?v=2', '/brand/favicon-16x16.png?v=2']))
    expect(await page.locator("link[rel='apple-touch-icon']").getAttribute('href')).toBe('/brand/apple-touch-icon.png?v=2')

    for (const path of ['/brand/favicon.ico', '/brand/favicon.svg', '/brand/favicon-16x16.png', '/brand/favicon-32x32.png', '/brand/favicon-48x48.png', '/brand/apple-touch-icon.png', '/brand/pwa-192.png', '/brand/pwa-512.png', '/brand/pwa-maskable-512.png', '/brand/social-preview.png', '/brand/logo.svg', '/brand/logo-mark.svg', '/favicon.ico']) {
      const response = await request.get(path)
      expect(response.status(), path).toBe(200)
      expect(response.headers()['content-type'], path).toMatch(/image\//)
      expect((await response.body()).length, path).toBeGreaterThan(100)
    }

    const manifest = await (await request.get('/site.webmanifest')).json()
    expect(manifest.name).toBe('مهتم')
    expect(manifest.short_name).toBe('مهتم')
    expect(manifest.start_url).toBe('/dashboard?source=pwa')
    expect(manifest.icons.map((i: { src: string }) => i.src)).toContain('/brand/pwa-512.png?v=2')
  })

  test('link previews carry the official card, and the structured data names Mohtam at mohtam.pro', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('landing-headline')).toBeVisible()
    const content = (selector: string) => page.locator(selector).first().getAttribute('content')

    expect(await content('meta[property="og:title"]')).toBe('مهتم — حوّل الاهتمام إلى فرصة')
    expect(await content('meta[property="og:image"]')).toBe('https://mohtam.pro/brand/social-preview.png')
    expect(await content('meta[name="twitter:card"]')).toBe('summary_large_image')
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toBe('https://mohtam.pro/')

    const ld = JSON.parse((await page.locator('script[type="application/ld+json"]').first().textContent()) ?? '[]') as Array<{ '@type': string; name: string; url: string }>
    expect(ld.map((d) => d['@type']).sort()).toEqual(['Organization', 'SoftwareApplication', 'WebSite'])
    for (const entry of ld) {
      expect(entry.name).toBe('Mohtam')
      expect(entry.url).toBe('https://mohtam.pro')
    }
  })

  test('the legal pages are `<page> — مهتم`', async ({ page }) => {
    for (const path of ['/privacy', '/terms', '/security', '/contact', '/about', '/data-deletion']) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      expect(await page.title(), path).toMatch(/^.+ — مهتم$/)
    }
  })

  test('the platform door is named for the platform', async ({ page }) => {
    await page.goto('/platform/login')
    await expect(page).toHaveTitle('دخول إدارة المنصة — مهتم')
  })

  test('390px: marketing, login and an app screen show the identity without breaking the layout', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    for (const path of ['/', '/login']) {
      await page.goto(path)
      await expect(page.locator('[data-testid="logo"]:visible').first()).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), path).toBeLessThanOrEqual(1)
    }
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')
    await expect(page.getByTestId('page-title')).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1)
  })
})
