import { expect, test, type Page } from '@playwright/test'
import { apiLogin } from '../../fixtures/api'
import { ALPHA } from '../../fixtures/data'
import { makeAdvancedLanding, type Made } from '../../fixtures/hub'
import { artisan, resetFixtures } from '../../fixtures/seed'
import { collectPageErrors, expectNoSidewaysScroll } from './helpers'

/**
 * The advanced form on the screens it is really opened on — Chromium, Firefox, WebKit, an iPhone and a Pixel — at 320 to 1366 px: two steps,
 * a row of two columns, a conditional question, a matrix and a repeater, a phone with a country, a header with a logo. What is asserted is
 * what was promised: no sideways scrolling, no clipped words, a matrix that is a card on a phone and a table where there is room, errors
 * beside their fields with the data kept, and a lead that arrives whole.
 */
const WIDTHS = [320, 360, 375, 390, 430, 768, 1366]

test.describe('Advanced form', () => {
  let made: Made

  test.beforeAll(async () => {
    resetFixtures()
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      made = await makeAdvancedLanding(admin)
    } finally {
      await admin.dispose()
    }
  })
  test.afterAll(() => resetFixtures())

  /** Words are not cut off: nothing that wraps is wider than its own box. */
  async function expectNoClipping(page: Page, where: string) {
    const clipped = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('label, legend, h1, h2, h3, p, button, span')]
      .filter((e) => e.offsetParent !== null && e.getBoundingClientRect().width > 4 && e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).overflowX !== 'visible' && getComputedStyle(e).textOverflow !== 'ellipsis' && !e.closest('select'))
      .slice(0, 5).map((e) => `${e.tagName}:${(e.textContent ?? '').trim().slice(0, 30)}`))

    expect(clipped, `${where}: words are clipped`).toEqual([])
  }

  async function stepOne(page: Page, name = 'عبدالله المتقدم', phone = '0555001122') {
    await page.getByTestId('input-name').fill(name)
    await page.getByTestId('input-mobile').fill(phone)
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '2')
  }

  for (const width of WIDTHS) {
    test(`${width} px: both steps fit, nothing is clipped, and the matrix is the right shape for the room`, async ({ page }) => {
      const errors = collectPageErrors(page)
      await page.setViewportSize({ width, height: width < 700 ? 800 : 900 })
      await page.goto(`/l/${made.token}`)

      await expect(page.getByTestId('landing-headline')).toBeVisible()
      await expect(page.getByTestId('form-logo')).toBeVisible()
      await expectNoSidewaysScroll(page, `${width} step 1`)
      await expectNoClipping(page, `${width} step 1`)
      await stepOne(page)

      await expectNoSidewaysScroll(page, `${width} step 2`)
      await expectNoClipping(page, `${width} step 2`)
      const row = page.getByTestId('matrix-row-priorities-location')
      await expect(row).toBeVisible()
      // A card for each row where the form is narrow; a table where there is room: judged by the form's own width, not the screen's.
      const display = await row.evaluate((e) => getComputedStyle(e).display)
      const formWidth = await page.getByTestId('form-root').evaluate((e) => e.clientWidth)
      expect(display, `${width} px (form ${formWidth})`).toBe(formWidth >= 448 ? 'grid' : 'block')

      // A repeater row, and the page still fits.
      await page.getByTestId('repeater-add-guests').click()
      await expect(page.getByTestId('repeater-row-guests-1')).toBeVisible()
      await expectNoSidewaysScroll(page, `${width} repeater`)
      expect(errors).toEqual([])
    })
  }

  test('the conditional question, the matrix, the repeater and the phone — and the lead arrives whole', async ({ page }) => {
    page.on('response', async (r) => { if (r.url().includes('/submit')) console.log('SUBMIT', r.status(), (await r.text()).slice(0, 400)) })
    await page.goto(`/l/${made.token}?utm_source=facebook&fbclid=V2FB`)
    await page.getByTestId('input-name').fill('ناصر الكامل')
    await page.getByTestId('country-mobile').selectOption('AE')
    await page.getByTestId('input-mobile').fill('050 123 4567')
    await page.getByLabel('المدينة').selectOption('جدة')
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '2')

    await expect(page.getByLabel(/العائد المتوقع/)).toHaveCount(0)
    await page.getByLabel('استثمار').check()
    await expect(page.getByLabel(/العائد المتوقع/)).toBeVisible()
    await page.getByLabel(/العائد المتوقع/).fill('8%')

    await page.getByLabel('الموقع: مهم جدًا').check()
    await page.getByLabel('السعر: مهم', { exact: true }).check()
    await expect(page.getByLabel('الموقع: مهم جدًا')).toBeChecked()

    const first = page.getByTestId('repeater-row-guests-0')
    await first.getByLabel(/^الاسم/).fill('نورة')
    await first.getByLabel(/^العلاقة/).selectOption('wife')
    await page.getByTestId('repeater-add-guests').click()
    await page.getByTestId('repeater-row-guests-1').getByLabel(/^الاسم/).fill('خالد')
    await page.getByTestId('repeater-remove-guests-1').click()
    await expect(page.getByTestId('repeater-row-guests-1')).toHaveCount(0)

    await page.getByTestId('landing-consent').check()
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('landing-thanks')).toBeVisible()
    await expect(page.getByTestId('thanks-action-whatsapp')).toHaveAttribute('href', 'https://wa.me/966551112222')

    const out = artisan(['tinker', '--execute', `
      $l = \\App\\Models\\Lead::withoutGlobalScopes()->where('full_name', 'ناصر الكامل')->firstOrFail();
      $e = \\App\\Models\\LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $l->id)->first();
      $custom = \\App\\Models\\CustomFieldValue::withoutGlobalScopes()->where('lead_id', $l->id)->get()->mapWithKeys(fn ($v) => [\\App\\Models\\CustomField::withoutGlobalScopes()->find($v->custom_field_id)->key => $v->value])->all();
      echo json_encode(['phone' => $l->phone_normalized, 'city' => $l->city, 'matrix' => $e->payload['answers']['priorities'] ?? null, 'guests' => $e->payload['answers']['guests'] ?? null, 'text' => $custom['priorities'] ?? null, 'typed' => $e->payload['typed']['mobile'] ?? null, 'customKeys' => array_keys($custom)], JSON_UNESCAPED_UNICODE);
    `])
    const lead = JSON.parse(out.trim().split('\n').pop()!) as { phone: string; city: string; matrix: Record<string, string>; guests: { name: string; relation: string }[]; text: string; typed: string; customKeys: string[] }
    expect(lead.phone).toBe('+971501234567')
    expect(lead.typed).toBe('050 123 4567')
    expect(lead.city).toBe('جدة')
    expect(lead.matrix).toEqual({ location: 'high', price: 'mid' })
    expect(lead.guests).toEqual([{ name: 'نورة', relation: 'wife' }])
    expect(lead.text, JSON.stringify(lead.customKeys)).toContain('الموقع: مهم جدًا')
  })

  test('validation: errors sit beside their fields, nothing typed is lost, and the manager\'s own words are used', async ({ page }) => {
    await page.goto(`/l/${made.token}`)
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('error-name')).toBeVisible()
    await expect(page.getByTestId('error-mobile')).toBeVisible()

    await page.getByTestId('input-name').fill('سامي المتحقق')
    await page.getByTestId('input-mobile').fill('12')
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('error-mobile')).toBeVisible()
    await expect(page.getByTestId('input-name')).toHaveValue('سامي المتحقق')

    await page.getByTestId('input-mobile').fill('0555003344')
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '2')

    // The matrix asks for one answer at least: say so, beside it, and keep the rest.
    await page.getByLabel('استثمار').check()
    await page.getByLabel(/العائد المتوقع/).fill('9%')
    await page.getByTestId('landing-consent').check()
    await page.getByTestId('landing-submit').click()
    await expect(page.getByTestId('error-priorities')).toBeVisible()
    await expect(page.getByLabel(/العائد المتوقع/)).toHaveValue('9%')
    await expect(page.getByTestId('landing-thanks')).toHaveCount(0)

    // Back keeps the first step's answers.
    await page.getByTestId('landing-back').click()
    await expect(page.getByTestId('input-name')).toHaveValue('سامي المتحقق')
  })

  test('the matrix can be answered from the keyboard alone', async ({ page, browserName }) => {
    test.skip(browserName === 'webkit', 'WebKit does not Tab to radio buttons by default')
    await page.goto(`/l/${made.token}`)
    await stepOne(page, 'كريم لوحة المفاتيح', '0555009911')

    const cell = page.getByTestId('matrix-cell-priorities-location-mid')
    await cell.focus()
    await page.keyboard.press('Space')
    await expect(cell).toBeChecked()
    await expect(page.getByRole('radiogroup', { name: 'الموقع' })).toBeVisible()
  })
})
