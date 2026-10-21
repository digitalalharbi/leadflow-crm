import { expect, test } from '@playwright/test'
import { apiLogin, apiPost } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'

/** What a partner is owed and what has been paid — and the statement on the link only they hold. */
test.describe('Partner commissions', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('terms, a ledger, a recorded payment, and a statement link the partner opens without signing in', async ({ page, browser }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const created = await apiPost(admin, '/api/v1/partners', { name: 'وسيط العمولة', type: 'broker', commission_type: 'fixed', commission_fixed: 5000 })
    const partnerId = (await created.json()).data.partner.id as string
    await admin.dispose()

    // Two of the first project's leads came through them and were won.
    artisan(['tinker', '--execute', `
      $p = \\App\\Models\\Partner::withoutGlobalScopes()->where('uuid', '${partnerId}')->firstOrFail();
      $leads = \\App\\Models\\Lead::withoutGlobalScopes()->where('tenant_id', $p->tenant_id)->whereNull('deleted_at')->orderBy('id')->limit(2)->get();
      foreach ($leads as $l) { \\Illuminate\\Support\\Facades\\DB::table('leads')->where('id', $l->id)->update(['partner_id' => $p->id, 'status' => 'won', 'won_at' => now(), 'first_contact_at' => now(), 'last_contact_at' => now(), 'deal_value_minor' => 90000000]); }
    `])

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/partners')
    const card = page.locator('li[data-testid^="partner-"]').filter({ hasText: 'وسيط العمولة' })
    await expect(card).toBeVisible()

    // 2 won deals × a fixed 5,000.
    await expect(card.getByTestId('partner-ledger-due')).toContainText('10')
    await card.getByTestId('partner-statement-open').click()
    await expect(page.getByTestId('partner-statement')).toBeVisible()
    await expect(page.getByTestId('statement-terms')).toContainText('5')

    await page.getByTestId('payment-amount').fill('4000')
    await page.getByTestId('payment-reference').fill('TRF-77')
    await page.getByTestId('payment-save').click()
    await expect(page.getByTestId('payment-row')).toContainText('TRF-77')
    await expect(page.getByTestId('statement-paid')).toContainText('4')

    // The link: asking for it copies it; the partner opens it with no session and sees their own figures.
    await page.getByTestId('statement-link').click()
    const url = await page.getByTestId('statement-link-url').innerText()
    expect(url).toContain('/reports/share/')

    const stranger = await browser.newContext()
    const open = await stranger.newPage()
    await open.goto(`/reports/share/${url.split('/').pop()}`)
    await expect(open.getByTestId('shared-statement')).toBeVisible()
    await expect(open.getByTestId('shared-statement-due')).toContainText('10')
    await expect(open.getByTestId('shared-statement-balance')).toContainText('6')
    await expect(open.getByTestId('shared-statement-payments')).toContainText('TRF-77')
    // Not the company's money, not anyone else.
    for (const hidden of ['shared-team', 'shared-campaigns', 'shared-platforms', 'sk-spend', 'sk-revenue']) await expect(open.getByTestId(hidden)).toHaveCount(0)
    await stranger.close()
  })
})
