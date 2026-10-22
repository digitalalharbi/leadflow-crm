import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** One screen to set a project's pace, target and quiet hours, and one button to switch it on. */
test.describe('Automation pack v2', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
    await page.getByTestId('tab-automations').click()
    await expect(page.getByTestId('automation-pack')).toBeVisible()
  })

  test('shows all eleven answers', async ({ page }) => {
    for (const key of ['service', 'language', 'brochure', 'whatsapp_template', 'email_template', 'qualification', 'sales_team', 'sla', 'scoring', 'cadence', 'quiet_hours']) {
      await expect(page.getByTestId(`pack-${key}`), `${key} is missing`).toBeVisible()
    }
  })

  test('the pace, the target and the quiet hours are saved and shown as answered', async ({ page }) => {
    await page.getByTestId('pack-cadence-intensive').click()
    await page.getByTestId('pack-sla-input').fill('12')
    await page.getByTestId('pack-quiet-toggle').click()
    await page.getByTestId('pack-save-settings').click()

    await expect(page.getByTestId('pack-sla')).toHaveAttribute('data-done', 'true')
    await expect(page.getByTestId('pack-cadence')).toHaveAttribute('data-done', 'true')
    await expect(page.getByTestId('pack-quiet_hours')).toHaveAttribute('data-done', 'true')

    await page.reload()
    await page.getByTestId('tab-automations').click()
    await expect(page.getByTestId('pack-cadence-intensive')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('pack-sla-input')).toHaveValue('12')
  })

  test('pausing stops the project and activating starts it again', async ({ page }) => {
    await expect(page.getByTestId('pack-active')).toHaveAttribute('data-active', 'true')

    await page.getByTestId('pause-automation').click()
    await expect(page.getByTestId('pack-active')).toHaveAttribute('data-active', 'false')

    await page.getByTestId('activate-automation').click()
    await expect(page.getByTestId('pack-active')).toHaveAttribute('data-active', 'true')
  })
})
