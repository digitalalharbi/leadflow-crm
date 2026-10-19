import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** The lists a rep works from, and the ones they keep for themselves. */
test.describe('Saved views', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/leads')
    await expect(page.getByTestId('lead-segments')).toBeVisible()
  })

  test('all eight working lists are there, each with a count', async ({ page }) => {
    for (const key of ['hot', 'needs_human', 'no_reply', 'overdue', 'high_value', 'new_today', 'qualified', 'stalled']) {
      await expect(page.getByTestId(`segment-${key}`), `${key} is missing`).toBeAttached()
      await expect(page.getByTestId(`segment-${key}-count`)).toHaveText(/\d/)
    }
  })

  test('a list shows exactly as many leads as its count says', async ({ page }) => {
    const count = Number((await page.getByTestId('segment-qualified-count').innerText()).replace(/\D/g, ''))
    await page.getByTestId('segment-qualified').click()
    await expect(page.getByTestId('segment-qualified')).toHaveAttribute('aria-pressed', 'true')

    const rows = page.locator('tbody tr')
    if (count === 0) {
      await expect(rows).toHaveCount(0)
    } else {
      await expect(rows).not.toHaveCount(0)
    }
  })

  test('a person can save the filters they set, use them, and delete them', async ({ page }) => {
    await expect(page.getByTestId('saved-view-save')).toBeDisabled()

    await page.getByTestId('segment-hot').click()
    await expect(page.getByTestId('saved-view-save')).toBeEnabled()
    await page.getByTestId('saved-view-save').click()
    await page.getByTestId('saved-view-name').fill('ساخنو الأسبوع')
    await page.getByTestId('saved-view-confirm').click()

    const saved = page.locator('[data-testid^="saved-view-"]').filter({ hasText: 'ساخنو الأسبوع' })
    await expect(saved).toBeVisible()

    await page.getByTestId('segment-all').click()
    await saved.getByRole('button').first().click()
    await expect(page.getByTestId('segment-hot')).toHaveAttribute('aria-pressed', 'true')

    await saved.locator('[data-testid^="saved-view-remove-"]').click()
    await expect(saved).toHaveCount(0)
  })
})
