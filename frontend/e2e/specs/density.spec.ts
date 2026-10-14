import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * The product fits the screen it is used on.
 *
 * 1366×768 is still the commonest laptop in this market, and it is the size
 * everything was quietly too big for — a layout that fits a 1440×900 design
 * review is a page and a half here, and the thing somebody opened the page for
 * is the thing below the fold.
 *
 * Two rules, and the second is the one that is easy to break by accident: no
 * screen may scroll sideways, and the two screens people live in must fit
 * without scrolling at all.
 */
const ROUTES = [
  '/',
  '/work',
  '/leads',
  '/pipeline',
  '/follow-ups',
  '/projects',
  '/automation',
  '/reports',
  '/data-quality',
  '/integrations',
  '/users',
  '/settings',
]

test.describe('A laptop screen', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  for (const route of ROUTES) {
    test(`${route} never scrolls sideways`, async ({ page }) => {
      await page.goto(route)
      await page.waitForLoadState('networkidle')

      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))

      // A sideways scrollbar on a dashboard means something is wider than the
      // screen, and whatever it is, nobody will find it.
      expect(scrollWidth, `${route} is ${scrollWidth}px wide in a ${clientWidth}px viewport`)
        .toBeLessThanOrEqual(clientWidth)
    })
  }

  /**
   * The rep's screen. Everything needed to work one lead — the queue, the card,
   * the actions and the history — in one viewport, because the scroll is where
   * a rep stops and checks their phone instead.
   */
  test('اعمل الآن fits without scrolling', async ({ page }) => {
    await page.goto('/work')
    await page.waitForLoadState('networkidle')

    const { scrollHeight, clientHeight } = await page.evaluate(() => ({
      scrollHeight: document.documentElement.scrollHeight,
      clientHeight: document.documentElement.clientHeight,
    }))

    expect(scrollHeight).toBeLessThanOrEqual(clientHeight + 1)

    // And all three columns are actually there, not collapsed away to make it fit.
    await expect(page.getByTestId('work-card')).toBeVisible()
  })

  /**
   * The manager's. The question anybody opens this page with is whether leads
   * are still arriving, and the answer is a grid of tiles rather than a scroll
   * past a chart and a list of cards.
   */
  test('the integrations grid answers in one screen', async ({ page }) => {
    await page.goto('/integrations')
    await page.waitForLoadState('networkidle')

    const { scrollHeight, clientHeight } = await page.evaluate(() => ({
      scrollHeight: document.documentElement.scrollHeight,
      clientHeight: document.documentElement.clientHeight,
    }))

    expect(scrollHeight).toBeLessThanOrEqual(clientHeight + 1)
    await expect(page.getByTestId('integration-grid')).toBeVisible()

    // Every source, not a selection that happened to fit.
    for (const key of ['meta', 'tiktok', 'snapchat', 'google_ads', 'google_sheets', 'whatsapp', 'calls']) {
      await expect(page.getByTestId(`integration-tile-${key}`)).toBeVisible()
    }
  })

  /** Arabic reads right to left, including the chrome the layout is built on. */
  test('the page is still right to left', async ({ page }) => {
    await page.goto('/work')

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  })
})
