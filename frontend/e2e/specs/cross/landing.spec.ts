import { expect, test } from '@playwright/test'
import { apiLogin } from '../../fixtures/api'
import { ALPHA } from '../../fixtures/data'
import { makeLanding, type Made } from '../../fixtures/hub'
import { resetFixtures } from '../../fixtures/seed'
import { collectPageErrors, expectNoSidewaysScroll } from './helpers'

/**
 * The page a link opens, in every browser and on a phone: right to left, one column, no sideways scrolling, a form that says what is
 * wrong beside the field, never sends without the answers or the consent, and thanks the person who sent it.
 */
test.describe('Landing page', () => {
  let made: Made

  test.beforeAll(async () => {
    resetFixtures()
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      made = await makeLanding(admin)
    } finally {
      await admin.dispose()
    }
  })
  test.afterAll(() => resetFixtures())

  test('opens right to left, fits the screen and shows what the project offers — and nothing hidden', async ({ page }) => {
    const errors = collectPageErrors(page)
    await page.goto(`/l/${made.token}?utm_source=facebook`)

    await expect(page.getByTestId('landing-headline')).toHaveText('فرصتك في الياسمين')
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByTestId('landing-benefits')).toContainText('قرب من المدارس')
    await expect(page.getByTestId('field-source')).toHaveCount(0)
    await expect(page.getByTestId('field-note')).toHaveCount(0)
    await expectNoSidewaysScroll(page, 'landing')
    expect(errors).toEqual([])
  })

  test('fits at 375 px, with the form open and its errors showing', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 })
    await page.goto(`/l/${made.token}`)
    await page.getByTestId('landing-submit').click()

    await expect(page.getByRole('alert').first()).toBeVisible()
    await expectNoSidewaysScroll(page, 'landing 375')
  })

  test('a conditional question appears only after the answer that asks for it', async ({ page }) => {
    await page.goto(`/l/${made.token}`)

    await expect(page.getByTestId('field-note')).toHaveCount(0)
    await page.getByLabel('استثمار').check()
    await expect(page.getByTestId('field-note')).toBeVisible()
  })

  test('refuses an empty or unconsenting submission, and thanks a good one once', async ({ page }) => {
    await page.goto(`/l/${made.token}`)
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('landing-thanks')).toHaveCount(0)

    await page.getByTestId('input-name').fill('نورة المتابعة')
    await page.getByTestId('input-mobile').fill('0544001122')
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('landing-consent-error')).toBeVisible()

    await page.getByTestId('landing-consent').check()
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('landing-thanks')).toBeVisible()
    await expectNoSidewaysScroll(page, 'landing thanks')
  })

  test('a token that is not there says the page is not available, and nothing more', async ({ page }) => {
    await page.goto('/l/ZZZZZZZZ')

    await expect(page.getByTestId('landing-unavailable')).toBeVisible()
    await expect(page.getByTestId('landing-form')).toHaveCount(0)
  })
})
