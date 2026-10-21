import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** How sensitive each alert is: moved, switched off, overridden for one project, and given back. */
test.describe('Anomaly settings', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('a line is moved and shows where it comes from; an alert is switched off; a project has its own', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/settings')
    await page.getByTestId('settings-tab-alerts').click()
    await expect(page.getByTestId('anomaly-list')).toBeVisible()

    // The built-in line, as it always was.
    await expect(page.getByTestId('alert-value-lead_drop')).toHaveValue('60')
    await expect(page.getByTestId('alert-source-lead_drop')).toContainText('افتراضي')

    // Move it.
    await page.getByTestId('alert-value-lead_drop').fill('75')
    await page.getByTestId('alert-value-lead_drop').blur()
    await expect(page.getByTestId('alert-source-lead_drop')).toContainText('من المساحة')
    await expect(page.getByTestId('alert-reset-lead_drop')).toBeVisible()

    // Switch an alert off and back on.
    await page.getByTestId('alert-enabled-lead_spike').click()
    await expect(page.getByTestId('alert-lead_spike')).toHaveAttribute('data-enabled', 'false')
    await page.getByTestId('alert-enabled-lead_spike').click()
    await expect(page.getByTestId('alert-lead_spike')).toHaveAttribute('data-enabled', 'true')

    // A project of its own: starts from the workspace's line, changing it is the project's alone.
    const project = ALPHA.projects[0]
    await page.getByTestId('anomaly-scope').selectOption({ label: project })
    await expect(page.getByTestId('alert-value-lead_drop')).toHaveValue('75')
    await expect(page.getByTestId('alert-source-lead_drop')).toContainText('من المساحة')
    await page.getByTestId('alert-value-lead_drop').fill('90')
    await page.getByTestId('alert-value-lead_drop').blur()
    await expect(page.getByTestId('alert-source-lead_drop')).toContainText('للمشروع')

    // The workspace is untouched by it, and the reset arrow gives its own line back.
    await page.getByTestId('anomaly-scope').selectOption({ label: 'كل المساحة' })
    await expect(page.getByTestId('alert-value-lead_drop')).toHaveValue('75')
    await page.getByTestId('alert-reset-lead_drop').click()
    await expect(page.getByTestId('alert-value-lead_drop')).toHaveValue('60')
    await expect(page.getByTestId('alert-source-lead_drop')).toContainText('افتراضي')
  })

  test('somebody who does not run the workspace has no tab for it', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/settings')
    await expect(page.getByTestId('settings-tab-alerts')).toHaveCount(0)
    const rep = await apiLogin(ALPHA.rep1.email)
    expect((await rep.context.patch('/api/v1/anomaly-settings', { data: { alerts: { lead_drop: { value: 70 } } } })).status()).toBe(403)
    await rep.dispose()
  })
})
