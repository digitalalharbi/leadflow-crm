import { expect, test } from '@playwright/test'
import { apiGet, apiLogin } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { LOGO_PNG } from '../fixtures/hub'
import { authenticateAs } from '../fixtures/pages'
import { artisan, resetFixtures } from '../fixtures/seed'
import { expectNoSidewaysScroll } from './cross/helpers'

/**
 * The whole path, as an administrator and then a stranger live it: create a form from a template, give it a logo and a header, lay it out in
 * sections, rows and columns, add a select, a phone, a matrix and a conditional field, make it step by step, preview it on a phone, publish
 * it, make a link, open the page on a phone, fill it, send it — and find the lead, its credit, the automation and the numbers.
 */
test.describe('Form builder', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test('from an empty template to a lead with its credit — on a phone', async ({ page, browser }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')

    // 1 — name, project, template.
    await page.getByRole('tab', { name: 'النماذج' }).click()
    await page.getByTestId('new-form').click()
    await page.getByTestId('new-form-name').fill('نموذج الاهتمام المتقدم')
    await page.getByTestId('new-form-project').selectOption({ label: ALPHA.projects[0] })
    await page.getByTestId('template-general_contact').click()
    await page.getByTestId('new-form-create').click()
    await expect(page.getByTestId('form-builder')).toBeVisible()
    await expect(page).toHaveURL(/\/tracking\/forms\/[0-9a-f-]{36}$/)
    const formId = page.url().split('/').pop()!

    // 2 — the logo and the header.
    await page.getByRole('tab', { name: 'التصميم' }).click()
    await page.getByTestId('design-logo-file').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: LOGO_PNG })
    await expect(page.getByTestId('design-logo-preview')).toBeVisible()
    await page.getByTestId('header-layout-project_card').click()
    await page.getByTestId('design-location').fill('شمال الرياض')
    await page.getByTestId('design-primary-blue').click()

    // 3 — step by step.
    await page.getByRole('tab', { name: 'التتبّع' }).click()
    await page.getByTestId('settings-mode').selectOption('multi')

    // 4 — a row of two columns: the email beside the city that is about to be added.
    const emailRow = page.locator('[data-testid^="canvas-row-"]').filter({ has: page.getByTestId('canvas-field-email') })
    const rowId = (await emailRow.getAttribute('data-testid'))!.replace('canvas-row-', '')
    await page.getByTestId(`cols-${rowId}-2`).click()
    await expect(emailRow.locator('[data-testid^="canvas-col-"]')).toHaveCount(2)

    // A field is dragged from the library into the empty column.
    const before = await page.locator('[data-testid^="canvas-field-"]').count()
    // The drag grabs what is under the pointer, so the page must have stopped moving: under load it was still scrolling and took the divider instead.
    const libSelect = page.getByTestId('lib-select')
    await libSelect.scrollIntoViewIfNeeded()
    await expect.poll(async () => {
      const first = await libSelect.boundingBox()
      await page.waitForTimeout(150)
      const second = await libSelect.boundingBox()

      return first !== null && first.y === second?.y
    }).toBe(true)
    await libSelect.dragTo(emailRow.locator('[data-testid^="canvas-col-"]').nth(1))
    await expect(page.locator('[data-testid^="canvas-field-"]')).toHaveCount(before + 1)
    await expect(page.getByTestId('field-settings')).toBeVisible()
    await page.getByTestId('fs-label').fill('نوع العقار')
    await page.getByTestId('fs-mapping-group').selectOption('lead')
    await page.getByTestId('fs-mapping').selectOption('lead.preferred_property_type')
    // …and its choices, pasted in one go.
    await page.getByTestId('fs-options-bulk-toggle').click()
    await page.getByTestId('fs-options-bulk').fill('فيلا\nشقة\nتاون هاوس')
    await page.getByTestId('fs-options-bulk-apply').click()
    await expect(page.getByTestId('fs-options-item-4')).toBeVisible()

    // 5 — a second step, with a radio, a conditional text, and a matrix.
    await page.getByTestId('add-section').click()
    await page.getByTestId('lib-radio').click()
    await page.getByTestId('fs-label').fill('الغرض من الشراء')
    await page.getByTestId('fs-options-label-0').fill('سكن')
    await page.getByTestId('fs-options-label-1').fill('استثمار')

    await page.getByTestId('lib-text').click()
    await page.getByTestId('fs-label').fill('العائد المتوقع')
    await page.getByTestId('logic-show-add').click()
    await page.getByTestId('logic-show-field-0').selectOption({ label: 'الغرض من الشراء' })
    await page.getByTestId('logic-show-value-0').selectOption({ label: 'استثمار' })
    await expect(page.getByTestId('logic-show-cond-0')).toBeVisible()

    await page.getByTestId('lib-matrix').click()
    await page.getByTestId('fs-label').fill('ما أهمية هذه العوامل؟')
    await page.getByTestId('fs-matrix-rows-label-0').fill('الموقع')
    await page.getByTestId('fs-matrix-rows-label-1').fill('السعر')
    await page.getByTestId('fs-matrix-min').fill('1')

    // 6 — preview on a phone: the same renderer, nothing recorded.
    await page.getByRole('tab', { name: 'المعاينة' }).click()
    await page.getByTestId('device-mobile').click()
    await expect(page.getByTestId('preview-frame')).toHaveAttribute('data-device', 'mobile')
    await expect(page.getByTestId('preview-frame').getByTestId('landing-headline')).toBeVisible()
    await expect(page.getByTestId('preview-frame').getByTestId('landing-step')).toBeVisible()
    await page.getByRole('tab', { name: 'البناء' }).click()

    // 7 — publish, and a link that opens it.
    await page.getByTestId('publish-form').click()
    await expect(page.getByTestId('published-dialog')).toBeVisible()
    await page.getByTestId('published-new-link').click()
    await expect(page.getByTestId('wizard-step-source')).toBeVisible()
    await page.getByTestId('wizard-channel-meta').click()
    await page.getByTestId('wizard-name').fill('إعلان ميتا — المتقدم')
    await page.getByTestId('wizard-next').click()
    await expect(page.getByTestId('wizard-form')).not.toHaveValue('')
    await page.getByTestId('wizard-next').click()
    await page.getByTestId('wizard-next').click()
    await page.getByTestId('wizard-create').click()
    await expect(page.getByTestId('wizard-made')).toBeVisible()
    const token = ((await page.getByTestId('wizard-url').textContent()) ?? '').split('/t/')[1]
    expect(token).toMatch(/^[A-Za-z0-9]{6,24}$/)

    // 8 — a stranger, on a phone, from an ad.
    const phone = await browser.newContext({ viewport: { width: 375, height: 800 }, locale: 'ar-SA', isMobile: true, hasTouch: true })
    const landing = await phone.newPage()
    await landing.goto(`/l/${token}?utm_source=facebook&fbclid=E2EFB1`)
    await expect(landing.getByTestId('landing-headline')).toBeVisible()
    await expect(landing.getByTestId('form-logo')).toBeVisible()
    await expectNoSidewaysScroll(landing, 'step 1')

    await landing.getByTestId('input-name').fill('فهد المستثمر')
    await landing.getByTestId('country-mobile').selectOption('SA')
    await landing.getByTestId('input-mobile').fill('0555667788')
    await landing.getByLabel('نوع العقار').selectOption('فيلا')
    await landing.getByTestId('landing-submit').click()                        // → step 2
    await expect(landing.getByTestId('form-progress')).toHaveAttribute('aria-valuenow', '2')
    await expectNoSidewaysScroll(landing, 'step 2')

    await expect(landing.getByLabel('العائد المتوقع')).toHaveCount(0)
    await landing.getByLabel('استثمار').check()
    await expect(landing.getByLabel('العائد المتوقع')).toBeVisible()
    await landing.getByLabel('العائد المتوقع').fill('7%')
    await landing.getByLabel('الموقع: مهم جدًا').check()
    await landing.getByTestId('landing-consent').check()
    await landing.getByTestId('landing-submit').click()
    await expect(landing.getByTestId('landing-thanks')).toBeVisible()
    await phone.close()

    // 9 — the lead, its credit, what it said, the automation, the numbers.
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      const found = await admin.context.get(`/api/v1/leads?search=${encodeURIComponent('فهد المستثمر')}`)
      const body = (await found.json()) as { data: { id: string; full_name: string }[] | { leads: { id: string; full_name: string }[] } }
      const leads = Array.isArray(body.data) ? body.data : body.data.leads
      expect(leads.map((l) => l.full_name)).toContain('فهد المستثمر')

      const links = await apiGet<{ links: { id: string; token: string }[] }>(admin, '/api/v1/tracking/links')
      const link = links.links.find((l) => l.token === token)!
      const a = await apiGet<{ analytics: { counts: Record<string, number> } }>(admin, `/api/v1/tracking/links/${link.id}/analytics?days=7`)
      expect(a.analytics.counts.leads).toBe(1)
      expect(a.analytics.counts.form_submissions).toBe(1)

      const analytics = await apiGet<{ totals: { views: number; starts: number; submits: number; completion_rate: number | null }; steps: { entered: number; completed: number }[]; matrix: { rows: { row: string; counts: Record<string, number> }[] }[]; versions: { version: number; submits: number }[] }>(admin, `/api/v1/tracking/forms/${formId}/analytics?days=7`)
      expect(analytics.totals.submits).toBe(1)
      expect(analytics.totals.starts).toBeGreaterThanOrEqual(1)
      expect(analytics.totals.views).toBeGreaterThanOrEqual(1)
      expect(analytics.steps.length).toBe(2)
      expect(analytics.versions[0]).toMatchObject({ version: 1, submits: 1 })

      const detail = artisan(['tinker', '--execute', `
        $link = \\App\\Models\\TrackingLink::withoutGlobalScopes()->where('token', '${token}')->first();
        $l = \\App\\Models\\Lead::withoutGlobalScopes()->where('full_name', 'فهد المستثمر')->firstOrFail();
        $e = \\App\\Models\\LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $l->id)->first();
        echo json_encode(['phone' => $l->phone_normalized, 'link' => $l->first_touch_link_id === $link->id, 'version' => $e->form_version_id !== null, 'typed' => $e->payload['typed']['mobile'] ?? null, 'activities' => \\App\\Models\\LeadActivity::withoutGlobalScopes()->where('lead_id', $l->id)->count()], JSON_UNESCAPED_UNICODE);
      `])
      const result = JSON.parse(detail.trim().split('\n').pop()!) as { phone: string; link: boolean; version: boolean; typed: string; activities: number }
      expect(result.phone).toBe('+966555667788')
      expect(result.link, 'the lead is credited to the link that brought it').toBe(true)
      expect(result.version, 'and remembers which version of the form its customer saw').toBe(true)
      expect(result.typed).toBe('0555667788')
      expect(result.activities).toBeGreaterThan(0)
    } finally {
      await admin.dispose()
    }
  })

  test('a draft change reaches nobody until it is published, and the builder says so', async ({ page, browser }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const { makeAdvancedLanding } = await import('../fixtures/hub')
    const made = await makeAdvancedLanding(admin, { name: 'نموذج النسخ' })
    await admin.dispose()

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/tracking/forms/${made.formId}`)
    await expect(page.getByTestId('builder-status')).toContainText('الإصدار 1')

    await page.getByTestId('form-headline').fill('عنوان جديد لم يُنشر')
    await page.getByTestId('save-form').click()
    await expect(page.getByTestId('builder-status')).toContainText('تعديلات غير منشورة')

    const stranger = await browser.newContext()
    const live = await stranger.newPage()
    await live.goto(`/l/${made.token}`)
    await expect(live.getByTestId('landing-headline')).toHaveText('فرصتك في الياسمين')

    await page.getByTestId('publish-form').click()
    await expect(page.getByTestId('published-dialog')).toContainText('الإصدار 2')
    await page.getByTestId('published-close').click()
    await live.reload()
    await expect(live.getByTestId('landing-headline')).toHaveText('عنوان جديد لم يُنشر')
    await stranger.close()
  })

  test('a form that cannot reach the customer is refused beside the problem, and undo takes the last change back', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/tracking')
    await page.getByRole('tab', { name: 'النماذج' }).click()
    await page.getByTestId('new-form').click()
    await page.getByTestId('new-form-name').fill('نموذج ناقص')
    await page.getByTestId('template-general_contact').click()
    await page.getByTestId('new-form-create').click()

    await page.getByTestId('remove-mobile').click()
    await page.getByTestId('remove-email').click()
    await page.getByTestId('save-form').click()
    await expect(page.getByTestId('builder-problems')).toContainText('رقم جوال أو بريد')

    await page.getByTestId('undo').click()
    await page.getByTestId('undo').click()
    await expect(page.getByTestId('canvas-field-mobile')).toBeVisible()
    await expect(page.getByTestId('canvas-field-email')).toBeVisible()
  })

  test('the builder fits a tablet and a phone: nothing scrolls sideways and the panes are reachable', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    const { makeAdvancedLanding } = await import('../fixtures/hub')
    const made = await makeAdvancedLanding(admin, { name: 'نموذج الشاشات' })
    await admin.dispose()

    await authenticateAs(page, ALPHA.admin.email)
    for (const size of [{ width: 768, height: 1024 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(size)
      await page.goto(`/tracking/forms/${made.formId}`)
      if (size.width >= 768) {
        await expect(page.getByTestId('canvas')).toBeVisible()
        await expect(page.getByTestId('library')).toBeVisible()
      } else {
        // A phone edits text and settings; arranging the layout is not forced onto 390px.
        await expect(page.getByTestId('canvas')).toBeHidden()
        await expect(page.getByTestId('builder-phone-note')).toBeVisible()
        await expect(page.getByTestId('form-settings-tabs')).toBeVisible()
      }
      await expectNoSidewaysScroll(page, `builder ${size.width}`)
      await page.getByRole('tab', { name: 'المعاينة' }).click()
      await expect(page.getByTestId('preview-frame')).toBeVisible()
      await expectNoSidewaysScroll(page, `preview ${size.width}`)
    }
  })
})
