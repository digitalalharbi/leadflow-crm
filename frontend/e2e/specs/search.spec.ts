import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * One box, everything findable.
 *
 * The case it exists for: a rep with a customer already on the phone has the
 * number and nothing else, and it will not be in the format we stored.
 */
test.describe('البحث السريع — command palette', () => {
  test.beforeAll(() => {
    // Read-only apart from the sign-in, so seeding once is enough.
    resetFixtures()
  })

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
  })

  test('⌘K opens it, focused and ready to type', async ({ page }) => {
    await page.keyboard.press('ControlOrMeta+k')

    const input = page.getByTestId('command-palette-input')
    await expect(input).toBeVisible()

    // A box you have to click into first is a slower version of the page you
    // were already on.
    await expect(input).toBeFocused()
  })

  test('a phone number finds the lead even in another format', async ({ page }) => {
    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill('0500000011')

    await expect(page.getByTestId('command-palette-results')).toContainText(LEADS.chat)
  })

  test('a name finds the lead', async ({ page }) => {
    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill(LEADS.search.split(' ')[0])

    await expect(page.getByTestId('command-palette-results')).toContainText(LEADS.search)
  })

  test('enter opens the highlighted result', async ({ page }) => {
    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill('0500000011')
    await expect(page.getByTestId('command-palette-results')).toBeVisible()

    await page.keyboard.press('Enter')

    await expect(page).toHaveURL(/\/leads\//)
  })

  test('one character searches nothing', async ({ page }) => {
    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill('ع')

    // Two characters matches half the database and tells nobody anything.
    await expect(page.getByTestId('command-palette-results')).toHaveCount(0)
  })

  test('a rep is not offered the team through it', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)

    await page.getByTestId('open-command-palette').click()
    await page.getByTestId('command-palette-input').fill('مدير')

    // An email list leaks one query at a time.
    const results = page.getByTestId('command-palette-results')
    if (await results.isVisible()) {
      await expect(results).not.toContainText(ALPHA.admin.name)
    }
  })
})
