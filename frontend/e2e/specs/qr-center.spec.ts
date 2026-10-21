import { expect, test } from '@playwright/test'
import { apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Every printed code in one place: filtered by what it hangs on, with what each brought, and ready to print or save. */
test.describe('QR center', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('codes are grouped by placement, show their numbers, and download as SVG or PNG', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const make = async (name: string, placement: string) => {
      const response = await apiPost(admin, '/api/v1/tracking/links', { name, kind: 'qr', channel: 'qr', placement })
      expect(response.status()).toBe(201)

      return (await response.json()).data.link as { token: string }
    }
    const billboard = await make('لوحة الدائري', 'billboard')
    await make('معرض العقار', 'event')
    await admin.dispose()

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'مركز QR' }).click()

    await expect(page.getByTestId('qr-center')).toBeVisible()
    await expect(page.getByTestId('qr-list').locator('li')).toHaveCount(2)
    await expect(page.getByTestId('qk-scans')).toBeVisible()

    // Filter: only what hangs on a billboard.
    await page.getByTestId('qr-placement-billboard').click()
    await expect(page.getByTestId('qr-list').locator('li')).toHaveCount(1)
    const card = page.getByTestId(`qr-${billboard.token}`)
    await expect(card).toContainText('لوحة الدائري')
    for (const stat of ['scans', 'leads', 'interested', 'visits', 'reservations']) {
      await expect(page.getByTestId(`qr-${billboard.token}-${stat}`)).toBeVisible()
    }
    await expect(page.getByTestId(`qr-thumb-${billboard.token}`).locator('img')).toHaveAttribute('src', /^data:image\/svg\+xml/)

    // Both file formats arrive as downloads.
    for (const format of ['svg', 'png']) {
      const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId(`qr-${format}-${billboard.token}`).click()])
      expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${format}$`))
    }

    // Print-ready: a size to pick, and a page to send to the printer.
    await page.getByTestId(`qr-print-${billboard.token}`).click()
    await expect(page.getByTestId('qr-print-dialog')).toBeVisible()
    await page.getByTestId('qr-size-poster').click()
    await expect(page.getByTestId('qr-size-poster')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('qr-print-go')).toBeEnabled()
  })

  test('nothing to show says what to do', async ({ page }) => {
    resetFixtures()
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'مركز QR' }).click()
    await expect(page.getByText('لا رموز QR بعد')).toBeVisible()
  })
})
