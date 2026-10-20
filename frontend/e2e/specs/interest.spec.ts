import { expect, test } from '@playwright/test'
import { apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** How interested a lead is: from what they do, apart from how good a fit they are. */
test.describe('Interest engine', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  let counter = 0

  async function arrive(): Promise<{ id: string; phone: string }> {
    const tail = String(5000 + (Date.now() % 4000) + counter++).slice(-4)
    const phone = `96650888${tail}`
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: `عميل الاهتمام ${tail}`, phone: `0${phone.slice(3)}` } })
      expect(created.ok(), await created.text()).toBeTruthy()

      return { id: (await created.json()).data.lead.id as string, phone }
    } finally {
      await admin.dispose()
    }
  }

  async function say(phone: string, text: string) {
    const delivered = await postWhatsappWebhook(inboundWhatsappMessage(phone, text, `wamid.e2e.int.${phone}.${Date.now()}.${counter++}`))
    expect(delivered.status).toBe(200)
  }

  test('a new lead is cold, and what the customer then says and asks warms it, with the reasons', async ({ page }) => {
    const { id, phone } = await arrive()
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)
    await expect(page.getByTestId('interest-level')).toHaveAttribute('data-level', 'cold')

    await say(phone, 'كم السعر؟ وفيه تمويل؟')
    await page.reload()

    const card = page.getByTestId('interest-card')
    await expect(card.getByTestId('interest-signals')).toContainText('ردّ على الرسائل')
    await expect(card.getByTestId('interest-signals')).toContainText('سأل عن السعر')
    await expect(card.getByTestId('interest-signals')).toContainText('سأل عن التمويل')
    await expect(card.getByTestId('interest-score')).not.toHaveText('0')
    await expect(card.getByTestId('interest-ring')).toBeVisible()
    await expect(card.getByTestId('interest-ladder').locator('li[data-reached="true"]').first()).toBeVisible()
  })

  test('asking to reserve is the top level, and the list can be filtered to it', async ({ page }) => {
    const { id, phone } = await arrive()
    await say(phone, 'ابي احجز وحدة وادفع العربون')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)
    await expect(page.getByTestId('interest-level')).toHaveAttribute('data-level', 'reservation_ready')
    await expect(page.getByTestId('interest-level')).toContainText('جاهز للحجز')

    await page.goto('/leads?view=interested')
    await expect(page.getByTestId('segment-interested')).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByTestId('lead-interest').first()).toBeVisible()
  })

  test('a person overrules the numbers in one press, and takes it back', async ({ page }) => {
    const { id } = await arrive()
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)

    await page.getByTestId('interest-yes').click()
    await expect(page.getByTestId('interest-level')).toHaveAttribute('data-level', 'interested')

    await page.getByTestId('interest-clear').click()
    await expect(page.getByTestId('interest-level')).toHaveAttribute('data-level', 'cold')

    await page.getByTestId('interest-no').click()
    await expect(page.getByTestId('interest-level')).toHaveAttribute('data-level', 'cold')
    await expect(page.getByTestId('interest-clear')).toBeVisible()
  })

  test('the interested segment counts exactly the leads it lists', async ({ page }) => {
    const { phone } = await arrive()
    await say(phone, 'ابي احجز وحدة')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/leads')
    const chip = page.getByTestId('segment-interested')
    await expect(chip).toBeVisible()
    const read = async () => Number((await page.getByTestId('segment-interested-count').innerText()).replace(/\D/g, ''))
    await expect.poll(read).toBeGreaterThanOrEqual(1)
    const count = await read()

    await chip.click()
    await expect(page.getByTestId('segment-interested')).toHaveAttribute('aria-pressed', 'true')
    // The count and the rows behind it are one predicate (the first page holds up to its page size).
    await expect(page.locator('tbody tr').first()).toBeVisible()
    await expect.poll(async () => page.locator('tbody tr').count()).toBe(Math.min(count, 25))
  })
})
