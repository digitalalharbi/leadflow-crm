import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * What automation may not do to a person is set in one place and survives a
 * reload — a limit that quietly reverts is worse than none.
 */
test.describe('Automation guardrails', () => {
  test.beforeAll(() => resetFixtures())

  test('quiet hours, the daily cap and the cooldown are saved', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/settings')
    await page.getByTestId('settings-tab-scoring').click()

    await expect(page.getByTestId('guardrails')).toBeVisible()
    await page.getByTestId('quiet-hours-toggle').click()
    await page.getByTestId('quiet-hours-start').fill('22:00')
    await page.getByTestId('quiet-hours-end').fill('07:30')
    await page.getByTestId('max-contacts').fill('4')
    await page.getByTestId('contact-cooldown').fill('15')

    const saved = page.waitForResponse((r) => r.url().includes('/settings') && r.request().method() === 'PATCH')
    await page.getByTestId('guardrails').locator('xpath=ancestor::form').getByRole('button', { name: /حفظ|Save/ }).click()
    expect((await saved).ok()).toBeTruthy()

    await page.reload()
    await page.getByTestId('settings-tab-scoring').click()
    await expect(page.getByTestId('quiet-hours-toggle')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('quiet-hours-start')).toHaveValue('22:00')
    await expect(page.getByTestId('quiet-hours-end')).toHaveValue('07:30')
    await expect(page.getByTestId('max-contacts')).toHaveValue('4')
    await expect(page.getByTestId('contact-cooldown')).toHaveValue('15')
  })
})
