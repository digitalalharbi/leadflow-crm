import { expect, test } from '@playwright/test'
import { apiGet, apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** How each person wants to hear about what matters: at once, in a summary, or not at all. */
test.describe('Alerts and summaries', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.manager.email)
    await page.goto('/settings/profile')
    await expect(page.getByTestId('notification-prefs')).toBeVisible()
  })

  test('every kind of alert is there with its four choices, and the defaults follow the role', async ({ page }) => {
    for (const category of ['hot_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'automation_failure', 'high_value', 'tracking_failure', 'budget_anomaly']) {
      const row = page.getByTestId(`alert-${category}`)
      await expect(row).toBeVisible()
      for (const mode of ['instant', 'daily', 'weekly', 'off']) await expect(row.getByTestId(`alert-${category}-${mode}`)).toBeVisible()
    }

    // A manager hears about breakage at once and reads value in the summary.
    await expect(page.getByTestId('alert-tracking_failure-instant')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('alert-high_value-daily')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('digest-weekly_marketing')).toBeChecked()
    await expect(page.getByTestId('digest-weekly_executive')).not.toBeChecked()
  })

  test('the screen says honestly that nothing leaves the server while mail is not set up', async ({ page }) => {
    await expect(page.getByTestId('alerts-mail-off')).toBeVisible()
  })

  test('a choice saves on the press and is still there after a reload', async ({ page }) => {
    await page.getByTestId('alert-hot_lead-weekly').click()
    await page.getByTestId('digest-weekly_executive').check()
    await expect(page.getByTestId('alert-hot_lead-weekly')).toHaveAttribute('aria-pressed', 'true')

    await page.reload()
    await expect(page.getByTestId('alert-hot_lead-weekly')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('digest-weekly_executive')).toBeChecked()

    const session = await apiLogin(ALPHA.manager.email)
    try {
      const prefs = await apiGet<{ alerts: Record<string, string>; digests: Record<string, boolean> }>(session, '/api/v1/auth/notification-preferences')
      expect(prefs.alerts.hot_lead).toBe('weekly')
      expect(prefs.digests.weekly_executive).toBe(true)
    } finally {
      await session.dispose()
    }
  })

  test('the digest command sends each summary to those who chose it', async () => {
    const out = artisan(['leadflow:send-digests', '--now', '--kind=daily', '--tenant=e2e-alpha'])
    expect(out).toMatch(/Digests: \d+ sent/)
    // A second run the same day sends nothing more.
    expect(artisan(['leadflow:send-digests', '--now', '--kind=daily', '--tenant=e2e-alpha'])).toContain('Digests: 0 sent')
  })
})
