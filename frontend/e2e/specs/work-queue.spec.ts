import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The loop the whole product is sold on.
 *
 * A rep opens one screen, is told which lead and why, presses one thing, and
 * the next lead arrives. Every assertion here is a link in that chain: if any
 * one of them breaks, the screen still renders and still looks finished, and a
 * sales floor quietly goes back to working from a spreadsheet.
 */
test.describe('اعمل الآن — the work queue', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/work')
  })

  test('it names one lead and says why that one', async ({ page }) => {
    await expect(page.getByTestId('work-card')).toBeVisible()

    // The reason is the product. A card that says "call this one" and nothing
    // else is a card reps argue with.
    const reason = page.getByTestId('work-reason')
    await expect(reason).toBeVisible()
    await expect(reason).not.toBeEmpty()

    await expect(page.getByTestId('work-urgency')).toBeVisible()
    await expect(page.getByTestId('work-action')).not.toBeEmpty()
  })

  test('recording an outcome hands over a different lead', async ({ page }) => {
    const first = await page.getByTestId('work-lead-name').textContent()

    await page.getByTestId('outcome-no_answer').click()

    await expect(page.getByTestId('work-lead-name')).not.toHaveText(first ?? '', {
      timeout: 10_000,
    })
  })

  test('skip moves on rather than re-offering the same lead', async ({ page }) => {
    const first = await page.getByTestId('work-lead-name').textContent()

    await page.getByTestId('work-skip').click()

    await expect(page.getByTestId('work-lead-name')).not.toHaveText(first ?? '', {
      timeout: 10_000,
    })
  })

  test('the number keys record outcomes, so a rep never leaves the keyboard', async ({ page }) => {
    const first = await page.getByTestId('work-lead-name').textContent()

    await page.keyboard.press('3')

    await expect(page.getByTestId('work-lead-name')).not.toHaveText(first ?? '', {
      timeout: 10_000,
    })
  })

  test('a shortcut does not fire while the rep is typing a note', async ({ page }) => {
    const first = await page.getByTestId('work-lead-name').textContent()

    await page.getByRole('textbox').last().fill('5')

    // Still the same lead: typing "5" into the note must not record "won".
    await expect(page.getByTestId('work-lead-name')).toHaveText(first ?? '')
  })

  test('opening the lead is a real link, not a button that looks like one', async ({ page }) => {
    const open = page.getByTestId('work-open-lead')
    await expect(open).toHaveAttribute('href', /\/leads\//)

    await open.click()
    await expect(page).toHaveURL(/\/leads\//)
  })

  test('the count reads as Arabic, not as a translated string', async ({ page }) => {
    // "33 عميل" is what an English plural table produces. Arabic counts six
    // ways, and 11–99 takes the accusative singular.
    const subtitle = page.getByTestId('page-subtitle')

    if (await subtitle.isVisible()) {
      const text = (await subtitle.textContent()) ?? ''
      const count = Number(text.match(/\d+/)?.[0] ?? 0)

      if (count >= 11 && count <= 99) {
        expect(text).toContain('عميلًا')
      }
    }
  })
})
