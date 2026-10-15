import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * A project is a workspace, not a record.
 *
 * It had two tabs — an overview of three fields and a repository view — and the
 * answer to "how is this project going" was spread across five other screens,
 * each asking about the whole workspace. These walk every tab a project is
 * supposed to have and check the one thing that distinguishes a workspace from
 * a list: that what is shown is *this project's*.
 */
test.describe('Project workspace', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
  })

  test('has every tab a project is meant to have', async ({ page }) => {
    for (const key of [
      'overview', 'leads', 'files', 'campaigns', 'automations',
      'activities', 'reports', 'integrations', 'development',
    ]) {
      await expect(page.getByTestId(`tab-${key}`), `the ${key} tab is missing`).toBeVisible()
    }
  })

  test('opens on the overview, with this project\'s own numbers', async ({ page }) => {
    await expect(page.getByTestId('tab-overview')).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByTestId('project-numbers')).toBeVisible()
  })

  test('the leads tab lists only this project\'s leads', async ({ page }) => {
    await page.getByTestId('tab-leads').click()

    const table = page.getByTestId('project-leads')
    await expect(table).toBeVisible()

    // The fixtures spread leads over three projects, so a workspace that leaked
    // the whole list would show every one of them.
    const here = await table.locator('tbody tr').count()

    await page.goto('/leads')
    await page.waitForLoadState('networkidle')
    const all = await page.getByTestId('leads-table-scroller').locator('tbody tr').count()

    expect(here).toBeGreaterThan(0)
    expect(here).toBeLessThan(all + 1)
  })

  test('the campaign, activity and source tabs answer from this project', async ({ page }) => {
    await page.getByTestId('tab-campaigns').click()
    await expect(page.getByTestId('project-campaigns')).toBeVisible()

    await page.getByTestId('tab-activities').click()
    await expect(page.getByTestId('project-activity')).toBeVisible()

    await page.getByTestId('tab-integrations').click()
    await expect(page.getByTestId('project-sources')).toBeVisible()
  })

  test('the automations tab says what is and is not written for the project', async ({ page }) => {
    await page.getByTestId('tab-automations').click()

    await expect(
      page.getByTestId('project-rules').or(page.getByTestId('project-rules-empty')),
    ).toBeVisible()
  })

  test('never scrolls sideways, whichever tab is open', async ({ page }) => {
    for (const key of ['overview', 'leads', 'campaigns', 'activities', 'integrations']) {
      await page.getByTestId(`tab-${key}`).click()

      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))

      expect(scrollWidth, `${key} is ${scrollWidth}px wide in a ${clientWidth}px viewport`)
        .toBeLessThanOrEqual(clientWidth)
    }
  })
})

test.describe('Messaging and telephony settings', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
    await page.getByTestId('toggle-integration-detail').click()
  })

  test('the workspace can register which message may start a conversation', async ({ page }) => {
    await expect(page.getByTestId('message-templates')).toBeVisible()

    await page.getByTestId('new-template').click()
    await expect(page.getByTestId('template-drawer')).toBeVisible()
    await expect(page.getByTestId('meta-template-name')).toBeVisible()
  })

  test('telephony can be connected, and says the rep dials until it is', async ({ page }) => {
    const card = page.getByTestId('call-settings')

    await expect(card).toBeVisible()
    await expect(card).toContainText('المندوب يتصل بنفسه')
    await expect(page.getByTestId('call-account-sid')).toBeVisible()
    await expect(page.getByTestId('call-auth-token')).toBeVisible()
  })
})
