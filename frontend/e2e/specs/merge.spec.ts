import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { apiLogin } from '../fixtures/api'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Two records that are one person: the manager is shown the evidence, merges,
 * and the second record is gone from the lists.
 */
test.describe('Smart merge', () => {
  test.beforeAll(() => resetFixtures())

  test('a manager reviews the suggestion, merges, and the duplicate disappears', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const stamp = Date.now()
    const email = `same.person.${stamp}@example.com`

    try {
      const first = await admin.context.post('/api/v1/leads', { data: { full_name: `ماجد الأول ${stamp}`, phone: '0508881111', email } })
      const second = await admin.context.post('/api/v1/leads', { data: { full_name: `ماجد الثاني ${stamp}`, phone: '0508882222', email } })
      expect(first.ok() && second.ok()).toBeTruthy()
      const keepId = (await first.json()).data.lead.id as string
      const dupName = `ماجد الثاني ${stamp}`

      await signInAs(page, ALPHA.admin.email)
      await page.goto(`/leads/${keepId}`)

      await expect(page.getByTestId('duplicates-banner')).toBeVisible()
      await page.getByTestId('duplicates-review').click()
      const candidate = page.getByTestId('duplicate-candidate').first()
      await expect(candidate).toContainText(dupName)
      await expect(candidate.getByTestId('duplicate-reason')).toHaveCount(1)

      await candidate.getByTestId('duplicate-merge').click()
      await page.getByTestId('duplicate-confirm').click()

      await expect(page.getByTestId('duplicates-banner')).toHaveCount(0)
      // The merge is in the history, and the duplicate is off the list.
      await expect(page.getByTestId('activity-timeline').getByText(/دُمج/).first()).toBeVisible()

      await page.goto('/leads')
      await page.getByPlaceholder(/ابحث/).fill(dupName)
      await expect(page.locator('tbody tr')).toHaveCount(0)
    } finally {
      await admin.dispose()
    }
  })

  test('a rep is never shown the banner', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/leads')
    await page.locator('a[href^="/leads/"]').first().click()
    await expect(page.getByTestId('activity-timeline')).toBeVisible()
    await expect(page.getByTestId('duplicates-banner')).toHaveCount(0)
  })
})
