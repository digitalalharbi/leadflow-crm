import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The rules screen.
 *
 * The assertion that matters most is that a rule built in this editor actually
 * saves. The API validates every field, operator and action against a closed
 * list, and a builder carrying its own copy of that vocabulary would eventually
 * offer something the server rejects — which a customer would meet as a
 * validation error on a rule they had already finished writing.
 */
test.describe('الأتمتة — rules', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/automation')
  })

  test('a template installs, and installs switched off', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()

    const rules = page.getByTestId('rules-list')
    await expect(rules).toContainText('عميل ساخن')

    // Off, deliberately: a rule that runs the moment somebody clicks a card
    // messages real customers before anyone has read what it does.
    await expect(rules).toContainText('متوقفة')
  })

  test('an installed template leaves the gallery', async ({ page }) => {
    await expect(page.getByTestId('install-lost_stop_chasing')).toBeVisible()

    await page.getByTestId('install-lost_stop_chasing').click()
    await expect(page.getByTestId('rules-list')).toContainText('خسارة')

    await expect(page.getByTestId('install-lost_stop_chasing')).toHaveCount(0)
  })

  test('a rule built in the editor is one the API accepts', async ({ page }) => {
    await page.getByRole('button', { name: 'قاعدة جديدة' }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByTestId('rule-name').fill('قاعدة من الاختبار')
    await dialog.getByTestId('add-condition').click()
    await dialog.getByTestId('save-rule').click()

    // Saved, not rejected: the editor only offers what the server allows.
    await expect(page.getByTestId('rules-list')).toContainText('قاعدة من الاختبار')
  })

  test('every action the editor offers is one the engine implements', async ({ page }) => {
    await page.getByRole('button', { name: 'قاعدة جديدة' }).click()

    const dialog = page.getByRole('dialog')
    const actions = dialog.getByRole('combobox').nth(1)
    const offered = await actions.locator('option').evaluateAll((options) =>
      options.map((option) => (option as HTMLOptionElement).value),
    )

    // The schema endpoint is the single source; this proves the editor reads it
    // rather than carrying a hard-coded list that drifts.
    const schema = await page.evaluate(async () => {
      const token = localStorage.getItem('leadflow.token')
      const response = await fetch('/api/v1/automation/schema', {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      })
      return (await response.json()).data.actions as string[]
    })

    expect(offered.sort()).toEqual(schema.sort())
  })

  test('preview says what a rule would match and changes nothing', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()
    await expect(page.getByTestId('rules-list')).toContainText('عميل ساخن')

    await page.getByRole('button', { name: 'معاينة' }).first().click()

    const result = page.getByTestId('preview-result')
    await expect(result).toBeVisible()
    await expect(result).toContainText('المعاينة لا تنفّذ أي إجراء')
  })

  test('a rule can be switched on and off', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()
    const rules = page.getByTestId('rules-list')
    await expect(rules).toContainText('متوقفة')

    await page.getByRole('button', { name: 'تشغيل' }).first().click()
    await expect(rules).toContainText('تعمل')
  })

  test('a sales rep is not offered the rules screen at all', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)

    await expect(page.locator('a[href="/automation"]')).toHaveCount(0)
  })

  test('counts in the list read as Arabic', async ({ page }) => {
    await page.getByTestId('install-lost_stop_chasing').click()

    // "بلا شروط · إجراءان", never "0 شرط · 2 إجراء".
    const rules = page.getByTestId('rules-list')
    await expect(rules).toContainText('بلا شروط')
    await expect(rules).not.toContainText('0 شرط')
  })
})
