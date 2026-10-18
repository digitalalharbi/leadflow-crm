import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * The working screens say what needs attention before they show a row.
 *
 * Visual design is judged by eye and by the density spec; what is asserted here
 * is the behaviour the redesign added: a count is the length of the list behind
 * it, a row says what to do, a project says who is waiting, a source says how far
 * it has got, and the reports say what to read first.
 */
test.describe('Leads', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/leads')
  })

  test('the working lists are above the table, each with its count', async ({ page }) => {
    const bar = page.getByTestId('lead-segments')
    await expect(bar).toBeVisible()

    for (const key of ['all', 'priority', 'hot', 'awaiting_contact', 'overdue', 'replied']) {
      await expect(page.getByTestId(`segment-${key}`)).toBeVisible()
      await expect(page.getByTestId(`segment-${key}-count`)).toHaveText(/^[\d٠-٩,٬]+$/)
    }
  })

  test('choosing a list shows exactly as many leads as it counted', async ({ page }) => {
    const count = Number(
      ((await page.getByTestId('segment-hot-count').textContent()) ?? '').replace(/[^\d]/g, ''),
    )
    expect(count).toBeGreaterThan(0)

    await page.getByTestId('segment-hot').click()
    await expect(page.getByTestId('segment-hot')).toHaveAttribute('aria-pressed', 'true')

    // As many rows as the chip said (the page size is larger than this list).
    expect(count).toBeLessThanOrEqual(25)
    await expect(page.getByTestId('leads-table-scroller').locator('tbody tr')).toHaveCount(count)

    await page.getByTestId('segment-all').click()
    await expect(page.getByTestId('segment-all')).toHaveAttribute('aria-pressed', 'true')
  })

  test('a row says what to do with the lead', async ({ page }) => {
    // The first one *on screen*: the same chip is also rendered for phones.
    const chip = page.locator('[data-testid="next-action"]:visible').first()
    await expect(chip).toBeVisible()
    // The reason is the tooltip: the same sentence the work queue shows.
    await expect(chip).toHaveAttribute('title', /.+/)
  })
})

test.describe('Projects', () => {
  test('a card says who is waiting, or that nobody is', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/projects')

    await expect(page.getByTestId('project-card').first()).toBeVisible()

    const cards = page.getByTestId('project-card')
    const n = await cards.count()
    for (let i = 0; i < n; i++) {
      await expect(cards.nth(i).getByTestId('project-attention')).toBeVisible()
      await expect(cards.nth(i).getByTestId('mini-trend')).toBeVisible()
    }
  })
})

test.describe('Integrations', () => {
  test('the page shows how far every source has got, in the ladder’s words', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/integrations')

    await expect(page.getByTestId('ladder-ribbon')).toBeVisible()
    await expect(page.getByTestId('ladder-legend')).toBeVisible()
    // The word that overclaimed is not on the summary.
    await expect(page.getByTestId('integration-grid')).not.toContainText('متصل')
    await expect(page.getByTestId('ladder-legend')).not.toContainText('متصل')
  })
})

test.describe('Reports', () => {
  test('says what to read first', async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/reports')

    await expect(page.getByTestId('insight-strip')).toBeVisible()
    // Every sentence carries a number the tables below can confirm.
    const sentences = await page.getByTestId('insight-strip').locator('p').allTextContents()
    expect(sentences.length).toBeGreaterThan(0)
    for (const sentence of sentences) {
      expect(sentence).toMatch(/[\d٠-٩]/)
    }
  })
})
