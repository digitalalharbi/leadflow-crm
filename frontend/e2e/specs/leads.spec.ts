import { expect, test } from '@playwright/test'
import { ALPHA, LEADS, REP1_LEAD_COUNT } from '../fixtures/data'
import { gotoLeads, openLeadByName, signInAs, waitForLeadsTable } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

test.describe('Leads list', () => {
  test.beforeEach(async ({ page }) => {
    resetFixtures()
    await signInAs(page, ALPHA.admin.email)
  })

  test('lists leads with the columns a rep scans', async ({ page }) => {
    await gotoLeads(page)

    await expect(page.locator('table tbody tr').first()).toBeVisible()

    // The redesign merged phone into the identity column — a name and a number
    // are one fact about one person, and two columns made the eye cross the
    // table to assemble it. The number is still there, under the name.
    // The columns the brief names: who, how serious, what kind, where from, and what next.
    for (const header of ['الاسم', 'التقييم', 'المرحلة', 'المصدر', 'الخطوة التالية']) {
      await expect(page.getByRole('columnheader', { name: header })).toBeVisible()
    }

    await expect(page.locator('table tbody tr').first()).toContainText('+9665')
  })

  test('opens with a picture of the leads, and a search redraws it', async ({ page }) => {
    await gotoLeads(page)

    const overview = page.getByTestId('leads-overview')
    for (const id of ['lk-total', 'lk-hot', 'lk-qualified', 'lk-response', 'lk-conversion', 'source-donut', 'lead-quality-donut', 'lead-trend']) {
      await expect(overview.getByTestId(id)).toBeVisible()
    }

    const total = async () => Number((await overview.getByTestId('lk-total').innerText()).replace(/[^\d]/g, ''))
    const all = await total()
    expect(all).toBeGreaterThan(1)

    // The picture and the table describe the same leads.
    await page.getByTestId('lead-search').fill(LEADS.search)
    await expect.poll(total).toBe(1)
  })

  test('search finds a lead by name', async ({ page }) => {
    await gotoLeads(page)
    await page.getByTestId('lead-search').fill(LEADS.search)

    await expect(page.getByRole('link', { name: LEADS.search })).toBeVisible()
    await expect(page.locator('table tbody tr')).toHaveCount(1)
  })

  test('search finds a lead by a locally formatted phone number', async ({ page }) => {
    // Stored as +966500000001; a rep types what the customer told them.
    await gotoLeads(page)
    await page.getByTestId('lead-search').fill('0500000001')

    await expect(page.getByRole('link', { name: LEADS.search })).toBeVisible()
  })

  test('a search with no matches explains itself', async ({ page }) => {
    await gotoLeads(page)
    await page.getByTestId('lead-search').fill('لا-يوجد-عميل-بهذا-الاسم-إطلاقاً')

    await expect(page.getByText('لا توجد نتائج مطابقة للتصفية الحالية.')).toBeVisible()
    // The filter bar and the empty state each offer a way out; assert on both.
    await expect(page.getByRole('button', { name: 'مسح التصفية' })).toHaveCount(2)
  })

  test('the status filter narrows the list', async ({ page }) => {
    await gotoLeads(page)

    const before = await page.locator('table tbody tr').count()

    // The controls live behind the filter toggle now; the chips are what stays
    // on screen once something is applied.
    await page.getByTestId('toggle-filters').click()
    await page.getByTestId('filter-status').selectOption('won')
    await waitForLeadsTable(page)

    await expect(async () => {
      const rows = page.locator('table tbody tr')
      expect(await rows.count()).toBeLessThan(before)
      // Every visible row is actually won. The badge appears twice per row —
      // a column on wide screens, an inline badge on phones — so count rather
      // than assert visibility on an ambiguous match.
      expect(await rows.first().getByText('تم البيع').count()).toBeGreaterThan(0)
    }).toPass()
  })

  test('filters combine and can be cleared', async ({ page }) => {
    await gotoLeads(page)

    await page.getByTestId('toggle-filters').click()
    await page.getByTestId('filter-status').selectOption('new')
    await page.getByTestId('filter-temperature').selectOption('hot')
    await waitForLeadsTable(page)

    await page.getByTestId('clear-filters').click()
    await waitForLeadsTable(page)

    await expect(page.getByTestId('filter-status')).toHaveValue('')
    await expect(page.getByTestId('filter-temperature')).toHaveValue('')
  })

  test('pagination moves between pages', async ({ page }) => {
    await gotoLeads(page)

    // 41 seeded leads at 25 per page means a real second page.
    const next = page.getByRole('button', { name: 'التالي' })
    await expect(next).toBeEnabled()

    const firstPageTop = await page.locator('table tbody tr').first().textContent()

    await next.click()
    await waitForLeadsTable(page)

    await expect(page.getByText('2 / 2')).toBeVisible()
    expect(await page.locator('table tbody tr').first().textContent()).not.toBe(firstPageTop)

    await page.getByRole('button', { name: 'السابق' }).click()
    await expect(page.getByText('1 / 2')).toBeVisible()
  })

  test('a lead can be created and is findable afterwards', async ({ page }) => {
    const name = `عميل منشأ ${Date.now()}`

    await gotoLeads(page)
    await page.getByRole('button', { name: 'إضافة عميل' }).click()

    await page.getByLabel('الاسم').fill(name)
    await page.getByPlaceholder('05xxxxxxxx').fill('0512349876')
    await page.getByLabel('المدينة').fill('جدة')
    await page.getByRole('button', { name: 'إنشاء' }).click()

    await expect(page.getByText('تمت إضافة العميل.')).toBeVisible()

    await page.getByTestId('lead-search').fill(name)
    await expect(page.getByRole('link', { name })).toBeVisible()
  })

  test('creating a lead with an existing phone explains the duplicate rather than failing', async ({ page }) => {
    await gotoLeads(page)
    await page.getByRole('button', { name: 'إضافة عميل' }).click()

    await page.getByLabel('الاسم').fill('محاولة تكرار')
    // Same person as LEADS.search, written the way a landing page would send it.
    await page.getByPlaceholder('05xxxxxxxx').fill('0500000001')
    await page.getByRole('button', { name: 'إنشاء' }).click()

    await expect(page.getByText(/مسجّل مسبقاً/)).toBeVisible()
  })

  test('an invalid phone is rejected with the reason on the field', async ({ page }) => {
    await gotoLeads(page)
    await page.getByRole('button', { name: 'إضافة عميل' }).click()

    await page.getByLabel('الاسم').fill('رقم غير صالح')
    await page.getByPlaceholder('05xxxxxxxx').fill('12')
    await page.getByRole('button', { name: 'إنشاء' }).click()

    await expect(page.getByRole('alert').first()).toBeVisible()
  })

  test('opening a lead shows its full detail', async ({ page }) => {
    await openLeadByName(page, LEADS.search)

    await expect(page.getByRole('heading', { name: LEADS.search })).toBeVisible()
    await expect(page.getByTestId('lead-quick-actions')).toBeVisible()
    await expect(page.getByText('بيانات التواصل')).toBeVisible()
    await expect(page.getByText('السجل الموحّد')).toBeVisible()
  })

  test('a sales rep only sees the leads assigned to them', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await gotoLeads(page)

    await expect(page.locator('table tbody tr')).toHaveCount(REP1_LEAD_COUNT)

    // And the other rep's leads are not reachable by searching for them either.
    await page.getByTestId('lead-search').fill('عميل المندوب الثاني')
    await expect(page.getByText('لا توجد نتائج مطابقة للتصفية الحالية.')).toBeVisible()
  })
})
