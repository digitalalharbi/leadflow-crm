import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { openLeadByName, signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

test.describe('Lead screen — the place a rep continues from', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.workflow)
  })

  test.afterAll(() => resetFixtures())

  test('shows the lead as a path, with every action one press away', async ({ page }) => {
    const track = page.getByTestId('stage-switcher')
    await expect(track.getByRole('button')).toHaveCount(8)
    await expect(track.locator('[aria-current="step"]')).toHaveCount(1)

    for (const id of ['action-call', 'action-whatsapp', 'action-email', 'action-file', 'action-appointment', 'action-follow-up', 'action-note', 'action-qualify']) {
      await expect(page.getByTestId(id)).toBeVisible()
    }

    await expect(page.getByTestId('lead-classification')).toBeVisible()
    await expect(page.getByTestId('calls-panel')).toBeVisible()
    await expect(page.getByTestId('appointments-panel')).toBeVisible()
    await expect(page.getByTestId('source-chain')).toBeVisible()
  })

  test('the email button opens the email channel without leaving the lead', async ({ page }) => {
    await page.getByTestId('action-email').click()
    await expect(page.getByTestId('email-card')).toBeVisible()
  })

  test('an appointment can be booked from the lead and moves it to the appointment stage', async ({ page }) => {
    await page.getByTestId('action-appointment').click()

    const tomorrow = new Date(Date.now() + 24 * 3600 * 1000)
    tomorrow.setMinutes(tomorrow.getMinutes() - tomorrow.getTimezoneOffset())
    await page.getByTestId('appointment-at').fill(tomorrow.toISOString().slice(0, 16))
    await page.getByTestId('confirm-appointment').click()

    await expect(page.getByTestId('lead-status-badge')).toHaveText('موعد')
    await expect(page.getByTestId('appointments-panel').locator('li')).toHaveCount(1)
  })

  test('the stage track moves the lead in one press', async ({ page }) => {
    await page.getByTestId('stage-switcher').getByRole('button', { name: 'مؤهل' }).click()
    await expect(page.getByTestId('lead-status-badge')).toHaveText('مؤهل')
  })
})
