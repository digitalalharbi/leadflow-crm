import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The spend somebody typed in: whether it counts, and how long it may go without an entry — per workspace or per project. */
test.describe('Anomaly settings — entered spend', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('entered spend has a switch and a line in days, and a project may say otherwise', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/settings')
    await page.getByTestId('settings-tab-alerts').click()
    await expect(page.getByTestId('anomaly-list')).toBeVisible()

    // The switch: on by default, no line to move.
    await expect(page.getByTestId('alert-manual_spend_counts')).toHaveAttribute('data-enabled', 'true')
    await expect(page.getByTestId('alert-value-manual_spend_counts')).toHaveCount(0)

    // The line: three days, counted in days.
    await expect(page.getByTestId('alert-value-manual_spend_stale')).toHaveValue('3')
    await expect(page.getByTestId('alert-manual_spend_stale')).toContainText('يوم')
    await page.getByTestId('alert-value-manual_spend_stale').fill('7')
    await page.getByTestId('alert-value-manual_spend_stale').blur()
    await expect(page.getByTestId('alert-source-manual_spend_stale')).toContainText('من المساحة')

    // A project: entered spend does not count for it, the workspace is untouched.
    await page.getByTestId('anomaly-scope').selectOption({ label: ALPHA.projects[0] })
    await expect(page.getByTestId('alert-value-manual_spend_stale')).toHaveValue('7')
    await page.getByTestId('alert-enabled-manual_spend_counts').click()
    await expect(page.getByTestId('alert-manual_spend_counts')).toHaveAttribute('data-enabled', 'false')
    await page.getByTestId('anomaly-scope').selectOption({ label: 'كل المساحة' })
    await expect(page.getByTestId('alert-manual_spend_counts')).toHaveAttribute('data-enabled', 'true')

    // Tracking is judged per project too: the line is offered under the project, not greyed out.
    await page.getByTestId('anomaly-scope').selectOption({ label: ALPHA.projects[0] })
    await expect(page.getByTestId('alert-value-tracking_drop')).toBeEnabled()
  })
})
