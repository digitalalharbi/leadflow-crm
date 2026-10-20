import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Every line is the workspace's to set and the project's to override — and says which. */
test.describe('Configurable thresholds', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('the workspace sets the three lines that used to be constants, and they stay set', async ({ page }) => {
    await page.goto('/settings')
    await page.getByTestId('settings-tab-scoring').click()

    await page.getByTestId('threshold-priority').fill('75')
    await page.getByTestId('threshold-high-value').fill('900000')
    await page.getByTestId('threshold-stalled').fill('6')
    await page.getByTestId('guardrails').locator('xpath=ancestor::form').getByRole('button', { name: /حفظ|Save/ }).click()
    await expect(page.getByTestId('threshold-stalled')).toHaveValue('6')

    await page.reload()
    await page.getByTestId('settings-tab-scoring').click()
    await expect(page.getByTestId('threshold-priority')).toHaveValue('75')
    await expect(page.getByTestId('threshold-high-value')).toHaveValue('900000')
    await expect(page.getByTestId('threshold-stalled')).toHaveValue('6')
  })

  test('a project overrides one line, is told where each comes from, and can put the workspace value back', async ({ page }) => {
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
    await page.getByTestId('tab-automations').click()

    const panel = page.getByTestId('thresholds-panel')
    await expect(panel).toBeVisible()
    for (const key of ['hot_from', 'warm_from', 'priority_from', 'high_value_from', 'sla_minutes', 'stalled_after_days', 'max_contacts_per_day', 'cooldown_minutes']) {
      await expect(panel.getByTestId(`threshold-${key}`), key).toBeVisible()
    }

    const stalled = panel.getByTestId('threshold-stalled_after_days')
    await stalled.locator('input').fill('2')
    await panel.getByTestId('thresholds-save').click()

    await expect(stalled).toHaveAttribute('data-source', 'project')
    await expect(stalled.locator('input')).toHaveValue('2')

    await panel.getByTestId('threshold-reset-stalled_after_days').click()
    await expect(stalled).not.toHaveAttribute('data-source', 'project')
    await expect(stalled.locator('input')).not.toHaveValue('2')
  })
})
