import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA, LEADS } from '../fixtures/data'
import { authenticateAs, openLeadByName } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** Real estate as a first-class model: a project's units, and what a lead wants. */
test.describe('Real estate model', () => {
  let projectId = ''

  test.beforeAll(async () => {
    resetFixtures()
    const admin = await apiLogin(ALPHA.admin.email)
    try {
      projectId = (await apiGet<{ projects: { id: string }[] }>(admin, '/api/v1/projects')).projects[0].id
    } finally {
      await admin.dispose()
    }
  })

  test.afterAll(() => resetFixtures())

  test('a manager adds, reserves and removes a unit, and the ring and counts follow', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}?tab=units`)

    const units = page.getByTestId('project-units')
    await expect(units).toBeVisible()
    await expect(units.getByTestId('units-donut')).toBeVisible()

    await units.getByTestId('add-unit').click()
    const form = page.getByTestId('unit-form')
    await form.getByTestId('unit-code').fill('E2E-204')
    await form.getByTestId('unit-type').selectOption('villa')
    await form.getByTestId('unit-price').fill('2400000')
    await form.getByTestId('unit-bedrooms').fill('5')
    await form.getByTestId('unit-district').fill('النرجس')
    await page.getByTestId('save-unit').click()

    const card = units.getByTestId('unit-E2E-204')
    await expect(card).toBeVisible()
    await expect(card).toHaveAttribute('data-status', 'available')
    await expect(units.getByTestId('units-count-available')).toHaveText('1')

    // Reserve it: the card, the count and the filter all say so.
    await card.getByRole('button').click()
    await page.getByTestId('unit-status').selectOption('reserved')
    await page.getByTestId('save-unit').click()
    await expect(units.getByTestId('units-count-reserved')).toHaveText('1')
    await expect(units.getByTestId('units-count-available')).toHaveText('0')

    await units.getByTestId('units-filter-available').click()
    await expect(units.getByTestId('unit-E2E-204')).toHaveCount(0)
    await units.getByTestId('units-filter-reserved').click()
    await expect(units.getByTestId('unit-E2E-204')).toBeVisible()

    await units.getByTestId('unit-E2E-204').getByRole('button').click()
    await page.getByTestId('delete-unit').click()
    await expect(units.getByTestId('unit-E2E-204')).toHaveCount(0)
  })

  test('a duplicate unit code is refused in words, not lost', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}?tab=units`)
    const units = page.getByTestId('project-units')

    for (const attempt of [1, 2]) {
      await units.getByTestId('add-unit').click()
      await page.getByTestId('unit-code').fill('DUP-1')
      await page.getByTestId('save-unit').click()
      if (attempt === 1) await expect(units.getByTestId('unit-DUP-1')).toBeVisible()
    }

    await expect(page.getByTestId('unit-error')).toBeVisible()
  })

  test('a sales rep sees the units but is not offered the buttons to change them', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto(`/projects/${projectId}?tab=units`)

    await expect(page.getByTestId('project-units')).toBeVisible()
    await expect(page.getByTestId('add-unit')).toHaveCount(0)
  })

  test('what a lead wants is kept as facts and shown on the qualification card', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.qualify)
    await page.getByTestId('action-qualify').click()

    const form = page.getByTestId('qualification-form')
    await form.getByTestId('q-transaction').selectOption('buy')
    await form.getByTestId('q-district').fill('الياسمين')
    await form.getByTestId('q-bedrooms').fill('4')
    await form.getByTestId('q-area').fill('220')
    await form.getByTestId('q-channel').selectOption('whatsapp')
    await form.getByTestId('q-reservation').selectOption('1')
    await page.getByTestId('qualify-save').click()

    const card = page.getByTestId('qualification-card')
    await expect(card).toContainText('الياسمين')
    await expect(card).toContainText('220')
    await expect(card).toContainText('واتساب')
    await expect(card).toContainText('جاهز للحجز')
  })

  test('a manager adds the real estate questions to a project in one press, and once', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/projects/${projectId}?tab=questions`)

    await page.getByTestId('add-real-estate-questions').click()
    const list = page.getByTestId('question-list')
    await expect(list.locator('li')).toHaveCount(9)
    await expect(list).toContainText('شراء أم إيجار')
    await expect(list).toContainText('هل أنت جاهز للحجز')

    await page.getByTestId('add-real-estate-questions').click()
    await expect(list.locator('li')).toHaveCount(9)
  })

  test('what a customer volunteers becomes facts, fills the lead, and closes the gaps', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)
    let id = ''
    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: 'عميل يتكلم عقار', phone: '0509990077', project_id: projectId } })
      expect(created.ok()).toBeTruthy()
      id = (await created.json()).data.lead.id as string
    } finally {
      await admin.dispose()
    }

    const delivered = await postWhatsappWebhook(inboundWhatsappMessage('966509990077', 'ابغى اشتري فيلا 4 غرف في حي الياسمين كاش للسكن', `wamid.e2e.re.${Date.now()}`))
    expect(delivered.status).toBe(200)

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)

    const card = page.getByTestId('conversation-card')
    await expect(card.getByTestId('fact-district')).toContainText('الياسمين')
    await expect(card.getByTestId('fact-bedrooms')).toContainText('4')
    await expect(card.getByTestId('fact-payment')).toContainText('كاش')
    await expect(card.getByTestId('fact-transaction')).toContainText('شراء')

    // What was said is known now: it is not among the questions still to ask.
    const gaps = page.getByTestId('qualification-gaps')
    await expect(gaps).toBeVisible()
    for (const answered of ['district', 'bedrooms', 'payment', 'transaction']) {
      await expect(gaps.locator(`[data-key="${answered}"]`)).toHaveCount(0)
    }
    await expect(gaps.getByTestId('gaps-missing').locator('[data-key="budget"]')).toBeVisible()
    await expect(gaps.getByTestId('gaps-next')).toHaveText(/\S/)
  })
})
