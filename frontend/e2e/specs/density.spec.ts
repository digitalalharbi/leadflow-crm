import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

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
  '/visits',
  '/partners',
  '/tracking',
  '/campaigns',
  '/audit',
  '/agents',
]

test.describe('A laptop screen', () => {
  // Measured against the known fixtures: a spec that ran before this one may have left a handed-over lead on top of the queue.
  test.beforeAll(() => resetFixtures())

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
    for (const key of ['meta', 'tiktok', 'snapchat', 'google_ads', 'google_sheets', 'whatsapp', 'email', 'calls']) {
      await expect(page.getByTestId(`integration-tile-${key}`)).toBeVisible()
    }
  })

  /**
   * The lead list. A page of leads used to make this screen 1709px tall against
   * a 768px laptop: the filters and the pager both left the viewport, so
   * narrowing a search meant scrolling back up to the control that would do it.
   *
   * The table scrolls inside itself now. The page must not scroll at all, the
   * pager must stay on screen, and the header must stay put while the rows move
   * under it — a frozen header is the whole reason an inner scroller is better
   * than a longer page, and it is one CSS property away from silently not
   * working.
   */
  test('the lead list scrolls inside itself, under the picture of the list', async ({ page }) => {
    await page.goto('/leads')
    await page.waitForLoadState('networkidle')

    /*
     * The contract changed on purpose. The page used to be the table, so the
     * rule was "the page never scrolls". It now opens with a picture of the
     * leads — five numbers and three charts — and the table is what you reach
     * for to act on it, so the page may scroll to reach the table. What must
     * still hold: the picture is on the first screen, the table is bounded and
     * scrolls inside itself, and its header stays put while the rows move.
     */
    await expect(page.getByTestId('lk-total')).toBeInViewport()
    await expect(page.getByTestId('source-donut')).toBeInViewport()

    const scroller = page.getByTestId('leads-table-scroller')
    await expect(scroller).toBeVisible()

    const height = await scroller.evaluate((node) => node.clientHeight)
    expect(height, 'the table grew with its rows instead of scrolling inside itself').toBeLessThanOrEqual(560)

    // There are more rows than fit, so the inner scroller has something to do.
    const overflows = await scroller.evaluate((node) => node.scrollHeight > node.clientHeight + 1)
    expect(overflows, 'the table did not overflow, so this proves nothing').toBe(true)

    const headerTop = async () =>
      scroller.locator('thead th').first().evaluate((node) => Math.round(node.getBoundingClientRect().top))

    const before = await headerTop()
    await scroller.evaluate((node) => { node.scrollTop = 240 })
    expect(await headerTop()).toBe(before)
  })

  /**
   * An integration card with nothing in it still has to offer something to do.
   * It used to be one grey sentence naming a blocker, in a full-width panel.
   */
  test('the Google Sheets card offers a way in when it is empty', async ({ page }) => {
    await page.goto('/integrations')
    await page.waitForLoadState('networkidle')

    const empty = page.getByTestId('sheets-empty')
    await expect(empty).toBeVisible()

    await expect(page.getByTestId('empty-connect-sheet')).toBeVisible()
    await expect(page.getByTestId('empty-import-file')).toBeVisible()

    // The mapping example is the one that answers the question that stops
    // people connecting, so it opens without connecting anything first.
    await page.getByTestId('empty-see-mapping').click()
    await expect(page.getByTestId('mapping-example')).toBeVisible()
  })

  /**
   * Details open beside the list, not on top of it. The list keeps its place
   * underneath, which is the whole reason these stopped being dialogs.
   */
  test('a lead opens in a drawer against the inline edge', async ({ page }) => {
    await page.goto('/leads')
    await page.waitForLoadState('networkidle')

    await page.getByRole('button', { name: /إضافة عميل/ }).click()

    const drawer = page.getByTestId('lead-form-drawer')
    await expect(drawer).toBeVisible()

    // It slides in, so measuring it the moment it is visible measures it
    // partway through the slide — the first version of this test read x as
    // -211 and looked like a layout bug.
    await drawer.evaluate((node) => Promise.all(node.getAnimations().map((a) => a.finished)))

    const box = await drawer.boundingBox()
    const viewport = page.viewportSize()

    // Arabic is RTL, so the inline end is the left edge.
    expect(box?.x).toBe(0)
    expect(Math.round(box?.height ?? 0)).toBe(viewport?.height)
  })

  /**
   * A lead's page is long — the thread, the history, the files. The acts a rep reaches for stay in
   * view while it scrolls, so nobody scrolls back up to call.
   */
  test('a lead’s actions stay in view while the page scrolls', async ({ page }) => {
    await openLeadByName(page, LEADS.chat)

    const bar = page.getByTestId('lead-actions-bar')
    await expect(bar).toBeVisible()
    await page.mouse.wheel(0, 900)
    await page.waitForTimeout(300)

    const top = (await bar.boundingBox())!.y
    // Under the header (about 5rem), not scrolled off the top.
    expect(top).toBeGreaterThanOrEqual(0)
    expect(top).toBeLessThan(200)
    await expect(bar.getByTestId('action-whatsapp')).toBeVisible()
  })

  /** Arabic reads right to left, including the chrome the layout is built on. */
  test('the page is still right to left', async ({ page }) => {
    await page.goto('/work')

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  })
})
