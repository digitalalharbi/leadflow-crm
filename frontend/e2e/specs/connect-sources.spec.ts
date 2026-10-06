import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Giving a source its credentials, and asking it for leads.
 *
 * The page named the credentials each provider needs and offered no way to
 * supply them, so every pull-based source — TikTok, Snapchat, LinkedIn, Google
 * Sheets — could be "connected" and could never deliver a lead. The only route
 * in was an API call no customer was told about.
 */
test.describe('ربط المصادر — connecting a source', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
  })

  test('every source that needs credentials offers somewhere to put them', async ({ page }) => {
    for (const provider of ['meta', 'tiktok', 'snapchat', 'linkedin', 'google_ads']) {
      await expect(page.getByTestId(`credentials-${provider}`)).toBeVisible()
    }
  })

  test('the form asks for exactly the keys that provider needs', async ({ page }) => {
    await page.getByTestId('credentials-tiktok').click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()

    // TikTok's Business API needs both; asking for one is a connection that
    // fails later with a worse message.
    await expect(page.getByTestId('credential-access_token')).toBeVisible()
    await expect(page.getByTestId('credential-advertiser_id')).toBeVisible()
  })

  /**
   * A pulled source has no webhook URL to hand out, and a pushed source has
   * nothing to poll. Offering both on both would be two dead buttons.
   */
  test('only a polled source offers to sync, and only a pushed one offers a webhook', async ({ page }) => {
    await expect(page.getByTestId('sync-tiktok')).toHaveCount(0)

    await page.getByTestId('credentials-tiktok').click()
    await page.getByTestId('credential-access_token').fill('e2e-token')
    await page.getByTestId('credential-advertiser_id').fill('e2e-advertiser')
    await page.getByTestId('save-credentials').click()

    // Saving creates the integration, and the poll button appears with it.
    await expect(page.getByTestId('sync-tiktok')).toBeVisible()
    await expect(page.getByTestId('credentials-tiktok')).toHaveText(/تعديل/)
  })

  /**
   * Secrets are write-only. The server returns which keys are set and never
   * what they are, so re-opening the form shows "محفوظ" rather than the token.
   */
  test('a saved secret is never read back into the form', async ({ page }) => {
    await page.getByTestId('credentials-tiktok').click()
    await page.getByTestId('credential-access_token').fill('e2e-token')
    await page.getByTestId('credential-advertiser_id').fill('e2e-advertiser')
    await page.getByTestId('save-credentials').click()
    await expect(page.getByTestId('sync-tiktok')).toBeVisible()

    await page.getByTestId('credentials-tiktok').click()

    const field = page.getByTestId('credential-access_token')
    await expect(field).toHaveValue('')
    await expect(field).toHaveAttribute('placeholder', 'محفوظ')
  })
})
