import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { apiGet, apiLogin } from '../fixtures/api'
import { signInAs, waitForLeadsTable } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The part of the dashboard that is about now.
 *
 * It exists so that nobody scrolls to learn what needs them: the attention
 * list, the lead to start with and the way into «اعمل الآن» are all on the
 * first screen, above the period figures.
 */
test.describe('Command center', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('says what needs attention and offers the way in', async ({ page }) => {
    await expect(page.getByTestId('command-center')).toBeVisible()
    await expect(page.getByTestId('attention-list')).toBeVisible()
    await expect(page.getByTestId('start-work')).toBeVisible()
  })

  test('recommends leads with a reason and opens the lead', async ({ page }) => {
    const first = page.getByTestId('recommended-item').first()

    await expect(first).toBeVisible()
    // A recommendation without a reason is an instruction nobody trusts.
    await expect(first).not.toHaveText(/^\s*$/)

    await first.click()
    await expect(page).toHaveURL(/\/leads\/[0-9a-f-]{36}/)
  })

  test('the way into the work queue is one press', async ({ page }) => {
    await page.getByTestId('start-work').click()
    await expect(page).toHaveURL(/\/work$/)
  })

  test('an attention row leads to the place that fixes it', async ({ page }) => {
    await page.getByTestId('attention-overdue_follow_ups').click()
    await expect(page).toHaveURL(/\/follow-ups$/)
  })

  test('the period figures are still on the first screen', async ({ page }) => {
    const box = await page.getByTestId('kpi-total').boundingBox()
    const viewport = page.viewportSize()

    expect(box).not.toBeNull()
    // Top of the figures inside the viewport — the command panel must not
    // push them below the fold.
    expect(box!.y).toBeLessThan(viewport!.height)
  })

  test('shows the product name and nothing else', async ({ page }) => {
    await expect(page.locator('body')).not.toContainText(/alpha|beta|leadflow|mahtam/i)
  })
})

test.describe('The journey strip', () => {
  test('draws the whole promise, in order, on the dashboard', async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/dashboard')

    const strip = page.getByTestId('journey-strip')
    await expect(strip).toBeVisible()

    for (const key of ['arrived', 'automated', 'reached', 'followed', 'waiting', 'closed']) {
      await expect(strip.getByTestId(`journey-${key}`)).toBeVisible()
    }

    // What the team is waiting on opens the work queue.
    await strip.getByTestId('journey-waiting').getByRole('link').click()
    await expect(page).toHaveURL(/\/work/)
  })

  test('a link from the command center filters the leads list', async ({ page }) => {
    const session = await apiLogin(ALPHA.admin.email)
    let hot: number
    try {
      hot = (await apiGet<unknown[]>(session, '/api/v1/leads?temperature[]=hot&per_page=100')).length
    } finally {
      await session.dispose()
    }

    await signInAs(page, ALPHA.admin.email)
    // The bare spelling the dashboard's own links have always used.
    await page.goto('/leads?temperature=hot')
    await waitForLeadsTable(page)

    await expect(page.locator('table tbody tr')).toHaveCount(Math.min(hot, 25))
  })
})
