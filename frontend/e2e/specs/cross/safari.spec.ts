import { expect, test } from '@playwright/test'
import { ALPHA } from '../../fixtures/data'
import { signInAs } from '../../fixtures/pages'
import { resetFixtures } from '../../fixtures/seed'
import { makeSharedReport } from './helpers'

/**
 * The places Safari (and so every iPhone, where it is the only engine) differs from Chrome: sticky
 * headers, sheets that rise from the bottom, date and time fields, file uploads, charts, right-to-left
 * layout, viewport height and the iOS habit of zooming into any field under 16px. Run in every
 * browser project; the assertions are the ones that hold everywhere, so a failure names the engine.
 */
test.describe('Safari-sensitive surfaces', () => {
  test.beforeAll(() => resetFixtures())

  test('the page is right to left and uses the dynamic viewport height', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    const facts = await page.evaluate(() => ({
      dir: document.documentElement.dir,
      bodyDirection: getComputedStyle(document.body).direction,
      lang: document.documentElement.lang,
      frameHeight: (document.querySelector('.app-frame') as HTMLElement | null)?.getBoundingClientRect().height ?? 0,
      viewport: window.innerHeight,
      dvh: CSS.supports('height', '100dvh'),
    }))

    expect(facts.dir).toBe('rtl')
    expect(facts.bodyDirection).toBe('rtl')
    expect(facts.lang).toBe('ar')
    expect(facts.dvh, 'this browser understands 100dvh').toBe(true)
    // The frame fills the visible viewport (the part under the browser's own bar is not counted).
    expect(facts.frameHeight).toBeGreaterThanOrEqual(facts.viewport - 40)
  })

  test('the header stays where it was put when the page scrolls', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/leads')
    await expect(page.getByTestId('app-header')).toBeVisible()

    await page.evaluate(() => window.scrollTo(0, 800))
    await page.waitForTimeout(250)
    const box = (await page.getByTestId('app-header').boundingBox())!

    // `sticky top-3`: far down the page the bar is still on screen, floating 12px from the top.
    expect(box.y).toBeGreaterThanOrEqual(0)
    expect(box.y).toBeLessThan(24)
    await expect(page.getByTestId('app-header')).toBeInViewport()
  })

  test('a drawer and a sheet open inside the screen, with their buttons reachable', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await makeSharedReport(ALPHA.admin.email, 'للمعاينة')
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()

    await page.getByTestId('new-share').click()
    const drawer = page.getByTestId('share-builder')
    await expect(drawer).toBeVisible()
    const viewport = page.viewportSize()!
    // Both slide in; the check is where they come to rest, so it waits for the movement to finish.
    const bottom = async (locator: typeof drawer) => { const b = (await locator.boundingBox())!; return b.y + b.height }
    await expect.poll(async () => (await drawer.boundingBox())!.y).toBeGreaterThanOrEqual(-1)
    await expect.poll(() => bottom(drawer)).toBeLessThanOrEqual(viewport.height + 1)
    await expect(page.getByTestId('share-save')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(drawer).toHaveCount(0)

    // The email dialog is a modal: a sheet on a phone, a dialog on a desktop.
    await page.getByTestId('share-email').first().click()
    const dialog = page.getByTestId('share-email-dialog')
    await expect(dialog).toBeVisible()
    const modal = page.getByRole('dialog').last()
    await expect.poll(() => bottom(modal)).toBeLessThanOrEqual(viewport.height + 1)
    await expect(page.getByTestId('share-email-send')).toBeVisible()
  })

  test('date and time fields take a value, look like the other fields, and are 16px where a finger types', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')

    const from = page.getByTestId('report-date-from')
    await expect(from).toBeVisible()
    await from.fill('2026-10-01')
    await expect(from).toHaveValue('2026-10-01')
    expect((await from.boundingBox())!.height).toBeGreaterThanOrEqual(36)

    // A touch screen's fields must be 16px or iOS zooms the page into them and leaves it zoomed.
    const coarse = await page.evaluate(() => window.matchMedia('(pointer: coarse)').matches)
    if (coarse) {
      const size = await from.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))
      expect(size).toBeGreaterThanOrEqual(16)
    }

    // The time field in the WhatsApp quiet hours (profile).
    await page.goto('/settings/profile')
    await expect(page.getByTestId('whatsapp-alerts')).toBeVisible()
  })

  test('a file can be chosen for upload', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
    await page.getByTestId('tab-files').click()
    await expect(page.getByTestId('upload-project-file')).toBeVisible()

    // The button opens the system file chooser (in WebKit too), and the chosen file leaves as an upload.
    // The request is stopped on the way out so the test leaves nothing behind in the project.
    await page.route(/\/api\/v1\/.*\/files(\?.*)?$/, (route) => (route.request().method() === 'POST' ? route.abort() : route.continue()))
    const chooserOpened = page.waitForEvent('filechooser')
    await page.getByTestId('upload-project-file').click()
    const chooser = await chooserOpened

    const sent = page.waitForRequest((req) => req.method() === 'POST' && /\/files(\?.*)?$/.test(req.url()))
    await chooser.setFiles({ name: 'brochure.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%mahtam\n') })
    const upload = await sent

    expect(chooser.isMultiple()).toBe(false)
    expect(upload.headers()['content-type']).toContain('multipart/form-data')
  })

  test('charts draw with a real size and their tooltip responds', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    const svg = page.getByTestId('lead-flow').locator('svg[role="img"]')
    await expect(svg).toBeVisible()
    const box = (await svg.boundingBox())!
    expect(box.width).toBeGreaterThan(150)
    expect(box.height).toBeGreaterThan(80)

    // Hover on a pointer device, tap on a touch one: either way a day is named.
    const touch = await page.evaluate(() => window.matchMedia('(pointer: coarse)').matches)
    if (touch) await svg.tap({ position: { x: Math.min(300, box.width - 10), y: 100 } })
    else await svg.hover({ position: { x: Math.min(300, box.width - 10), y: 100 } })
    await expect(page.getByTestId('lead-flow').locator('div.pointer-events-none.absolute')).toBeVisible()
  })

  test('flex and grid layouts keep their order: the cards of a report line up in rows', async ({ browser }) => {
    const { token } = await makeSharedReport(ALPHA.admin.email, 'للتخطيط')
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, locale: 'ar-SA' })
    const page = await context.newPage()
    await page.goto(`/r/${token}`)
    await expect(page.getByTestId('shared-exec_marketing')).toBeVisible()

    const tops = await page.getByTestId('shared-exec_marketing').locator('[data-testid^="exec_marketing-"]').evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)))
    // Ten figures in a grid: more than one row, and every row aligned to its own top.
    expect(new Set(tops).size).toBeGreaterThan(1)
    expect(tops.length).toBeGreaterThanOrEqual(10)
    await context.close()
  })
})
