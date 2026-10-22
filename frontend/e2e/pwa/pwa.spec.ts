import { expect, test, type Page } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { previewServer, rebuild } from './build'

/**
 * The installable app, on a production build: the manifest, the icons, the service worker, what it
 * will and will not cache, the offline page, the offline notice with changes refused, the install
 * prompt, the iPhone instructions, and an update arriving while the app is open.
 */

/** A service worker is active and in control of the page. */
async function controlled(page: Page) {
  await page.waitForFunction(async () => {
    const registration = await navigator.serviceWorker.getRegistration()

    return Boolean(registration?.active) && Boolean(navigator.serviceWorker.controller)
  }, undefined, { timeout: 30_000 })
}

async function openLogin(page: Page) {
  await page.goto('/login')
  await page.evaluate(() => navigator.serviceWorker.ready)
  // The first load is not controlled until the worker claims it; a reload is the honest "second visit".
  await page.reload()
  await controlled(page)
}

test.describe('Installable app', () => {
  test('the manifest names the product, runs standalone and its icons exist', async ({ page }) => {
    const response = await page.request.get('/manifest.webmanifest')
    expect(response.status()).toBe(200)
    const manifest = await response.json()

    expect(manifest.name).toBe('مهتم')
    expect(manifest.short_name).toBe('مهتم')
    expect(manifest.display).toBe('standalone')
    expect(manifest.dir).toBe('rtl')
    expect(manifest.lang).toBe('ar')
    expect(manifest.scope).toBe('/')
    expect(manifest.start_url).toMatch(/^\/\?source=pwa$/)
    expect(manifest.theme_color).toBe('#12A594')
    expect(manifest.background_color).toBe('#ffffff')

    const icons = manifest.icons as { src: string; sizes: string; type: string; purpose?: string }[]
    expect(icons.map((i) => i.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']))
    expect(icons.some((i) => i.purpose === 'maskable')).toBe(true)

    for (const icon of icons.filter((i) => i.type === 'image/png')) {
      const res = await page.request.get(icon.src)
      expect(res.status(), icon.src).toBe(200)
      expect(res.headers()['content-type']).toContain('image/png')
    }

    // Shortcuts to the three screens a rep reaches for.
    expect((manifest.shortcuts as { url: string }[]).map((s) => s.url.split('?')[0])).toEqual(['/work', '/leads', '/reports'])
  })

  test('the page declares itself installable and has a launch screen for each iPhone', async ({ page }) => {
    await page.goto('/login')

    expect(await page.locator('link[rel="manifest"]').getAttribute('href')).toBe('/manifest.webmanifest')
    expect(await page.locator('meta[name="theme-color"]').getAttribute('content')).toMatch(/^#/)
    expect(await page.locator('meta[name="apple-mobile-web-app-capable"]').getAttribute('content')).toBe('yes')
    expect(await page.locator('meta[name="mobile-web-app-capable"]').getAttribute('content')).toBe('yes')
    expect(await page.locator('meta[name="viewport"]').getAttribute('content')).toContain('viewport-fit=cover')

    const touch = await page.locator('link[rel="apple-touch-icon"]').getAttribute('href')
    expect((await page.request.get(touch!)).status()).toBe(200)

    const splashes = await page.locator('link[rel="apple-touch-startup-image"]').evaluateAll((els) => els.map((el) => el.getAttribute('href')!))
    expect(splashes.length).toBeGreaterThanOrEqual(6)
    for (const href of splashes) expect((await page.request.get(href)).status(), href).toBe(200)
  })

  test('a service worker takes control, and nothing from the API is ever stored', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLogin(page)
    await page.goto('/')
    await expect(page.getByTestId('page-title')).toBeVisible()
    await page.goto('/leads')
    await expect(page.getByTestId('page-title')).toBeVisible()

    const stored = await page.evaluate(async () => {
      const out: string[] = []

      for (const key of await caches.keys()) {
        const cache = await caches.open(key)
        for (const request of await cache.keys()) out.push(new URL(request.url).pathname)
      }

      return out
    })

    expect(stored).toContain('/offline.html')
    expect(stored.filter((p) => p.startsWith('/api/')), 'private data must never be in a cache').toEqual([])
    // Hashed build files are cached; no page of the app (so no stale copy of it) is.
    expect(stored.filter((p) => p === '/' || p.startsWith('/leads'))).toEqual([])
  })

  test('with the server unreachable a page shows the offline page, never a stale copy of the app', async ({ page }) => {
    // Its own copy of the app on its own port, so it can really be switched off.
    const server = await previewServer(Number(process.env.PWA_PORT ?? 5303) + 1)

    try {
      await page.goto(`${server.url}/login`)
      await page.evaluate(() => navigator.serviceWorker.ready)
      await page.reload()
      await controlled(page)

      await server.stop()
      await page.goto(`${server.url}/leads`)
      await expect(page.getByTestId('offline-title')).toBeVisible()
      await expect(page.getByTestId('offline-title')).toContainText('لا يوجد اتصال')

      await server.start()
      await page.getByTestId('offline-retry').click()
      await expect(page.getByTestId('offline-title')).toHaveCount(0)
    } finally {
      await server.stop()
    }
  })

  test('offline inside the app: a notice shows, a change is refused, and it clears when the connection returns', async ({ page, context }) => {
    await authenticateAs(page, ALPHA.manager.email)
    await page.goto('/settings/profile')
    await expect(page.getByTestId('notification-prefs')).toBeVisible()
    const digest = page.getByTestId('digest-weekly_marketing')
    await expect(digest).toBeChecked()

    await context.setOffline(true)
    await expect(page.getByTestId('pwa-offline')).toBeVisible()
    await expect(page.getByTestId('pwa-offline')).toContainText('غير متصل')

    // A change made now is refused before it is sent — said out loud, not queued for later.
    await digest.uncheck()
    await expect(page.getByText('لا يوجد اتصال بالإنترنت، فلم يُنفَّذ التغيير')).toBeVisible()

    await context.setOffline(false)
    await expect(page.getByTestId('pwa-offline')).toHaveCount(0)

    // Nothing was saved behind the person's back.
    await page.reload()
    await expect(page.getByTestId('digest-weekly_marketing')).toBeChecked()
  })

  test('the installability check finds nothing wrong (Chromium engines: Chrome and Edge)', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'the installability report is a Chromium DevTools feature')

    await openLogin(page)
    const session = await page.context().newCDPSession(page)
    const { installabilityErrors } = (await session.send('Page.getInstallabilityErrors')) as { installabilityErrors: { errorId: string }[] }

    expect(installabilityErrors.map((e) => e.errorId)).toEqual([])
  })

  test('a signed-in workspace’s own manifest is just as installable: absolute addresses and PNG icons', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')
    await expect(page.getByTestId('page-title')).toBeVisible()
    await page.waitForFunction(() => document.querySelector<HTMLLinkElement>('link[rel="manifest"]')?.href.startsWith('blob:'), undefined, { timeout: 20_000 })

    const manifest = await page.evaluate(async () => {
      const href = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')!.href

      return (await (await fetch(href)).json()) as { name: string; start_url: string; scope: string; id: string; icons: { src: string; sizes: string; type: string; purpose?: string }[] }
    })

    expect(manifest.name.length).toBeGreaterThan(0)
    expect(manifest.start_url).toMatch(/^https?:\/\/[^/]+\/\?source=pwa$/)
    expect(manifest.scope).toMatch(/^https?:\/\/[^/]+\/$/)
    expect(manifest.id).toMatch(/^https?:\/\//)
    expect(manifest.icons.every((i) => /^https?:\/\//.test(i.src))).toBe(true)
    expect(manifest.icons.map((i) => i.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']))
    expect(manifest.icons.some((i) => i.purpose === 'maskable')).toBe(true)
  })

  test('the install prompt appears after a while, installs on a press, and is not asked again once put away', async ({ page, browserName, isMobile }) => {
    test.skip(browserName !== 'chromium', 'only Chromium engines offer an install event; iPhone has its own instructions below')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')
    await expect(page.getByTestId('page-title')).toBeVisible()

    const offer = () => page.evaluate(() => {
      const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }
      event.prompt = async () => { (window as unknown as { __prompted?: boolean }).__prompted = true }
      event.userChoice = Promise.resolve({ outcome: 'accepted' })
      window.dispatchEvent(event)
    })

    await offer()
    await expect(page.getByTestId('pwa-install')).toBeVisible({ timeout: 15_000 })

    // Put away: gone, and still gone after a reload and another offer.
    await page.getByTestId('pwa-install-dismiss').click()
    await expect(page.getByTestId('pwa-install')).toHaveCount(0)
    await page.reload()
    await expect(page.getByTestId('page-title')).toBeVisible()
    await offer()
    await page.waitForTimeout(1500)
    await expect(page.getByTestId('pwa-install')).toHaveCount(0)

    // Forgetting the choice (as two weeks would), it is offered again and pressing install shows the browser's own prompt.
    await page.evaluate(() => localStorage.removeItem('mahtam.pwa.dismissed'))
    await page.reload()
    await expect(page.getByTestId('page-title')).toBeVisible()
    await offer()
    await expect(page.getByTestId('pwa-install')).toBeVisible({ timeout: 15_000 })
    await page.getByTestId('pwa-install-accept').click()
    await expect.poll(() => page.evaluate(() => (window as unknown as { __prompted?: boolean }).__prompted)).toBe(true)
    await expect(page.getByTestId('pwa-install')).toHaveCount(0)
    expect(isMobile !== undefined).toBe(true)
  })

  test('on an iPhone the person is told how to add it to the home screen — once', async ({ page }) => {
    const iphone = await page.evaluate(() => /iPhone/.test(navigator.userAgent))
    test.skip(!iphone, 'the iPhone instructions are for Safari on iOS')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/')
    await expect(page.getByTestId('pwa-ios-hint')).toBeVisible({ timeout: 15_000 })
    await expect(page.getByTestId('pwa-ios-hint')).toContainText('إضافة إلى الشاشة الرئيسية')
    await page.getByTestId('pwa-ios-dismiss').click()
    await expect(page.getByTestId('pwa-ios-hint')).toHaveCount(0)

    await page.reload()
    await expect(page.getByTestId('page-title')).toBeVisible()
    await page.waitForTimeout(1500)
    await expect(page.getByTestId('pwa-ios-hint')).toHaveCount(0)
  })

  test('a new version arrives while the app is open: a notice offers it, one press and the page reloads into it', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'one engine is enough to prove the update flow; the worker protocol is the same')

    await openLogin(page)
    expect(await page.evaluate(() => (window as unknown as { __MAHTAM_BUILD__?: string }).__MAHTAM_BUILD__)).toBe('v1')
    const before = await (await page.request.get('/sw.js')).text()

    // Ship version 2 under the running app, and let the browser check.
    rebuild('v2')
    await page.evaluate(() => navigator.serviceWorker.getRegistration().then((r) => r!.update()))
    await expect(page.getByTestId('pwa-update')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('pwa-update')).toContainText('إصدار أحدث')

    const after = await (await page.request.get('/sw.js', { headers: { 'Cache-Control': 'no-cache' } })).text()
    expect(after).not.toBe(before)

    await Promise.all([page.waitForEvent('load'), page.getByTestId('pwa-update-apply').click()])
    await expect.poll(() => page.evaluate(() => (window as unknown as { __MAHTAM_BUILD__?: string }).__MAHTAM_BUILD__)).toBe('v2')
    await expect(page.getByTestId('pwa-update')).toHaveCount(0)
  })
})
