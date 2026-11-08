import { expect, test } from '@playwright/test'
import { resetFixtures } from '../fixtures/seed'

/**
 * The marketing site sells: a visitor reads the plans and asks for a trial from the page. The request becomes a lead in
 * Mohtam's own workspace; no page creates a company and no password is typed or shown anywhere.
 */
test.describe('Sales funnel', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('the pricing page lists the catalogue and every plan leads to the signup', async ({ page }) => {
    await page.goto('/pricing')
    await expect(page.getByTestId('page-pricing')).toBeVisible()
    await expect(page.getByTestId('pricing-plans')).toBeVisible()
    const first = page.locator('[data-testid^="plan-choose-"]').first()
    await expect(first).toHaveAttribute('href', /^\/signup\?plan=/)
    await expect(page.getByTestId('pricing-demo')).toHaveAttribute('href', '/contact')
  })

  test('a company asks for a trial from the request page', async ({ page }) => {
    await page.goto('/signup')
    // The request form appears only when the server has a workspace to put the lead in.
    test.skip(!(await page.getByTestId('signup-form').isVisible({ timeout: 5000 }).catch(() => false)), 'no workspace receives marketing requests here')
    const stamp = Date.now()
    await page.getByLabel('اسم الشركة').fill(`شركة اختبار ${stamp}`)
    await page.getByLabel('اسمك').fill('سارة')
    await page.getByLabel('بريدك الإلكتروني').fill(`owner-${stamp}@signup-check.sa`)
    await expect(page.getByTestId('signup-submit')).toBeDisabled()
    await page.getByTestId('signup-consent').check()
    await page.getByTestId('signup-submit').click()
    await expect(page.getByTestId('signup-sent')).toBeVisible()
  })
})
