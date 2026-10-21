import { expect, test } from '@playwright/test'
import { apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** A report emailed to people, and the honest account of how it went — with no mailer connected. */
test.describe('Email delivery', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('a report is emailed; with no provider connected it is logged, never called sent', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const made = await admin.context.post('/api/v1/report-shares', { data: { name: 'تقرير للبريد', template: 'executive' } })
    expect(made.status()).toBeLessThan(300)

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/reports')
    await page.getByTestId('tab-share').click()
    await page.getByTestId('share-email').first().click()
    await expect(page.getByTestId('share-email-dialog')).toBeVisible()
    await expect(page.getByTestId('share-email-send')).toBeDisabled()
    await page.getByTestId('share-email-recipients').fill('owner@example.com, partner@example.com')
    await page.getByTestId('share-email-send').click()
    await expect(page.getByTestId('share-email-dialog')).toHaveCount(0)

    // The status panel: the log provider, nothing delivered, two rows that say "logged only".
    await page.goto('/settings')
    await page.getByTestId('settings-tab-delivery').click()
    await expect(page.getByTestId('mail-provider-state')).toContainText('لا مزوّد مربوط')
    await expect(page.getByTestId('mail-counts')).toContainText('سُجّل فقط · 2')
    const rows = page.getByTestId('deliveries').locator('li[data-status="logged"]')
    await expect(rows).toHaveCount(2)
    await expect(page.getByTestId('deliveries').locator('li[data-status="sent"]')).toHaveCount(0)

    // The attempt log opens.
    await rows.first().locator('[data-testid^="delivery-log-"]').click()
    await expect(page.getByTestId('deliveries').locator('[data-testid^="delivery-attempts-"]')).toBeVisible()
    await admin.dispose()
  })

  test('somebody who cannot share reports cannot read or send the email log', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/settings')
    await expect(page.getByTestId('settings-tab-delivery')).toHaveCount(0)
    const rep = await apiLogin(ALPHA.rep1.email)
    expect((await rep.context.get('/api/v1/email-deliveries')).status()).toBe(403)
    await rep.dispose()
  })
})
