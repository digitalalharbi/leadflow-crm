import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { authenticateAs, openLeadByName } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Qualifying a lead in under a minute, and the score moving because of it.
 *
 * The seeded lead starts with nothing filled in, so the before/after is real
 * rather than an artefact of whatever the demo data happened to contain.
 */
test.describe('Qualification', () => {
  test.beforeAll(() => resetFixtures())

  test('an unqualified lead says so', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.qualify)

    await expect(page.getByTestId('qualification-card')).toBeVisible()
    await expect(page.getByTestId('qualification-status')).toHaveText('غير مؤهل')
  })

  test('filling the form moves the score, the temperature and the status', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.qualify)

    // From the quick actions, without scrolling to find the card.
    await page.getByTestId('action-qualify').click()

    const form = page.getByTestId('qualification-form')
    await expect(form).toBeVisible()

    await form.getByTestId('q-purpose').selectOption('residence')
    await form.getByTestId('q-timeline').selectOption('within_month')
    await form.getByTestId('q-budget-max').fill('1500000')
    await form.getByTestId('q-payment').selectOption('financing')
    await form.getByTestId('q-financing').selectOption('approved')
    await form.getByTestId('q-property-type').fill('فيلا')
    await form.getByTestId('q-appointment').selectOption('1')

    await page.getByTestId('qualify-save').click()

    await expect(page.getByTestId('qualification-status')).toHaveText('مؤهل')
    await expect(page.getByTestId('score-reasons')).toBeVisible()

    // High interest, because the answers say so — not because anything was
    // typed in.
    await expect(page.getByTestId('lead-quick-actions')).toBeVisible()
    await expect(page.getByText('مرتفع').first()).toBeVisible()
  })

  test('the score explains itself', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.qualify)

    const reasons = page.getByTestId('score-reasons')
    await expect(reasons).toBeVisible()

    // A number with no reasons behind it is a number nobody trusts.
    await expect(reasons).toContainText('ينوي الشراء خلال 3 أشهر')
    await expect(reasons).toContainText('يرغب بموعد زيارة')
    await expect(reasons).toContainText('+25')
  })

  test('the change is written to the timeline', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.qualify)

    await expect(page.getByText('تم تحديث التأهيل').first()).toBeVisible()
    await expect(page.getByText('تغيّر التقييم').first()).toBeVisible()
  })

  test('a rep cannot qualify a lead that is not theirs', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/leads')

    // rep1 does not own this lead, so it is not in their list at all.
    await page.getByTestId('lead-search').fill(LEADS.qualify)
    await expect(page.getByRole('link', { name: LEADS.qualify })).toHaveCount(0)
  })
})
