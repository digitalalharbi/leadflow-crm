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
    await expect(rules).toContainText('اهتمام مرتفع')

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

    /*
     * Wait for the options before reading them.
     *
     * The editor fills this select from the schema endpoint, so for the first
     * moments after the dialog opens it is legitimately empty — and an empty
     * list compared against an empty list is the one case where this test
     * would pass while proving nothing. Reading it early instead produced a
     * flake that failed once in a full run and passed on its own.
     */
    await expect(actions.locator('option')).not.toHaveCount(0)

    const offered = await actions.locator('option').evaluateAll((options) =>
      options.map((option) => (option as HTMLOptionElement).value),
    )

    expect(offered.length).toBeGreaterThan(0)

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
    await expect(page.getByTestId('rules-list')).toContainText('اهتمام مرتفع')

    await page.getByRole('button', { name: 'معاينة' }).first().click()

    const result = page.getByTestId('preview-result')
    await expect(result).toBeVisible()
    await expect(result).toContainText('المعاينة لا تنفّذ أي إجراء')
  })

  test('the preview also simulates the last seven days, as numbers, without carrying anything out', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()
    await page.getByRole('button', { name: 'معاينة' }).first().click()

    const sim = page.getByTestId('simulation')
    await expect(sim).toBeVisible()
    await expect(sim).toContainText('بلا تنفيذ فعلي')
    for (const key of ['leads', 'whatsapp', 'email', 'files', 'stages', 'escalations', 'notifications']) {
      await expect(sim.getByTestId(`sim-${key}`), `${key} tile`).toBeVisible()
      await expect(sim.getByTestId(`sim-${key}`)).toContainText(/\d/)
    }
    // Estimated qualified is a number or an honest dash — never a guess dressed as one.
    await expect(sim.getByTestId('sim-qualified')).toBeVisible()
    await expect(sim.getByTestId('sim-by-day')).toBeVisible()
    await expect(sim.getByTestId('sim-basis')).toContainText(/\S/)
  })

  test('the simulation can look back seven or thirty days', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()
    await page.getByRole('button', { name: 'معاينة' }).first().click()

    const sim = page.getByTestId('simulation')
    await expect(sim.getByTestId('sim-period-7')).toHaveAttribute('aria-pressed', 'true')
    await sim.getByTestId('sim-period-30').click()
    await expect(sim.getByTestId('sim-period-30')).toHaveAttribute('aria-pressed', 'true')
    await expect(sim).toContainText('30')
    await expect(sim.getByTestId('sim-leads')).toContainText(/\d/)
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

    // Wait for the dashboard's own automation step to be drawn: before the numbers arrive there is no link
    // to find, which would make "no link" true of every role.
    await expect(page.getByTestId('journey-automated')).toBeVisible()
    await expect(page.locator('a[href="/automation"]')).toHaveCount(0)

    // And the route itself sends them back rather than showing the rules.
    await page.goto('/automation')
    await expect(page).not.toHaveURL(/\/automation/)
  })

  test('counts in the list read as Arabic', async ({ page }) => {
    await page.getByTestId('install-lost_stop_chasing').click()

    // "بلا شروط · إجراءان", never "0 شرط · 2 إجراء".
    const rules = page.getByTestId('rules-list')
    await expect(rules).toContainText('بلا شروط')
    await expect(rules).not.toContainText('0 شرط')
  })

  test('a rule reads as a path — trigger, condition, then its actions', async ({ page }) => {
    await page.getByTestId('install-hot_lead_priority').click()

    const flow = page.locator('[data-testid^="rule-flow-"]').first()
    await expect(flow).toBeVisible()
    await expect(flow.locator('li[data-kind="trigger"]')).toHaveCount(1)
    await expect(flow.locator('li[data-kind="condition"]')).toHaveCount(1)
    expect(await flow.locator('li[data-kind="action"]').count()).toBeGreaterThan(0)

    // Values are in the product's own words, never the stored key.
    await expect(flow).not.toContainText(/\bhot\b/)
  })

  test('the numbers on top say how much the automation is doing', async ({ page }) => {
    await expect(page.getByTestId('automation-kpis')).toBeVisible()
  })

  test('the summary opens with eight numbers, each against the period before', async ({ page }) => {
    for (const key of ['all_actions', 'auto_contacted', 'follow_ups', 'qualified', 'appointments', 'needs_human', 'failed', 'inactive_rules']) {
      await expect(page.getByTestId(`card-${key}`)).toBeVisible()
    }

    await expect(page.getByTestId('automation-funnel')).toBeVisible()
    await expect(page.getByTestId('automation-success')).toBeVisible()
  })

  test('a summary card opens the history behind it', async ({ page }) => {
    await page.getByTestId('card-failed').click()

    await expect(page.getByTestId('action-history')).toBeVisible()
    await expect(page.getByTestId('history-filter-failed')).toHaveAttribute('aria-selected', 'true')
  })

  test('routines say how often they run and whether they have', async ({ page }) => {
    await page.getByRole('tab', { name: 'الروتين' }).click()

    await expect(page.getByTestId('routine-sla_guardian')).toBeVisible()
    await expect(page.getByTestId('routine-no_reply_review')).toBeVisible()
    await expect(page.getByTestId('routine-lead_quality_review')).toBeVisible()
  })

  test('the builder saves a rule that recommends instead of acting', async ({ page }) => {
    await page.getByRole('button', { name: 'قاعدة جديدة' }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByTestId('rule-name').fill('توصية من الاختبار')
    await dialog.getByTestId('mode-recommend').click()
    await dialog.getByTestId('save-rule').click()

    const row = page.getByTestId('rules-list').locator('li', { hasText: 'توصية من الاختبار' })
    await expect(row).toContainText('توصية')
  })

  test('a project can be given its own scoring', async ({ page }) => {
    const card = page.getByTestId('scoring-card')
    await expect(card).toBeVisible()

    await card.getByTestId('scoring-whatsapp_reply').fill('25')
    await card.getByTestId('save-scoring').click()

    await expect(card).toContainText('مخصّص للمشروع')
  })
})
