import { expect, test } from '@playwright/test'
import { apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA } from '../fixtures/data'
import { authenticateAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/** When a customer answers the journey the lead becomes a person's, with a brief. */
test.describe('Sales hand-off', () => {
  test.beforeAll(() => resetFixtures())

  /** A lead that has just arrived: the journey writes to it, and its first answer is a reply to that. */
  async function arrivedAndAnswered(phone: string, answer: string): Promise<string> {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const created = await admin.context.post('/api/v1/leads', { data: { full_name: `عميل الرد ${phone}`, phone: `0${phone.slice(3)}` } })
      expect(created.ok()).toBeTruthy()
      const id = (await created.json()).data.lead.id as string

      const delivered = await postWhatsappWebhook(inboundWhatsappMessage(phone, answer, `wamid.e2e.answer.${phone}.${Date.now()}`))
      expect(delivered.status).toBe(200)

      return id
    } finally {
      await admin.dispose()
    }
  }

  test('a reply hands the lead over and the rep opens it to a brief', async ({ page }) => {
    const id = await arrivedAndAnswered('966509990001', 'مهتم، ابي فيلا وابي أزور الموقع بس السعر غالي')

    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)

    const brief = page.getByTestId('handoff-brief')
    await expect(brief).toBeVisible()
    await expect(brief.getByTestId('handoff-reason')).toContainText(/\S/)
    await expect(brief.getByTestId('handoff-qualification')).toBeVisible()
    await expect(brief.getByTestId('handoff-objections')).toContainText('السعر')
    // The band answers the five questions without opening anything: why now, how qualified, how interested, who spoke last.
    await expect(brief.getByTestId('handoff-why')).toContainText(/\S/)
    await expect(brief.getByTestId('handoff-interest-badge')).toBeVisible()
    await expect(brief.getByTestId('handoff-last')).toContainText('العميل')
    await expect(brief.getByTestId('handoff-next')).toBeVisible()

    // The rest is one press away: the last of the conversation, short.
    await expect(brief.getByTestId('handoff-conversation')).toHaveCount(0)
    await brief.getByTestId('handoff-toggle').click()
    await expect(brief.getByTestId('handoff-conversation')).toContainText('فيلا')
  })

  test('on a laptop the brief is a compact band and the next step is on the first screen', async ({ page }) => {
    const id = await arrivedAndAnswered('966509990003', 'مهتم، ابي فيلا وابي أزور الموقع بس السعر غالي')

    await page.setViewportSize({ width: 1366, height: 768 })
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)

    const brief = page.getByTestId('handoff-brief')
    await expect(brief).toBeVisible()
    const box = (await brief.boundingBox())!
    expect(box.height, 'the collapsed brief takes no more than a band').toBeLessThanOrEqual(200)
    // The one step to take first is inside the first screen, not below the fold.
    const next = (await brief.getByTestId('handoff-next').boundingBox())!
    expect(next.y + next.height).toBeLessThanOrEqual(768)
  })

  test('the work queue opens the same brief on the lead it asks you to work', async ({ page }) => {
    const id = await arrivedAndAnswered('966509990002', 'نعم مهتم')

    await authenticateAs(page, ALPHA.admin.email)
    // Work the queue until it reaches the handed-over lead (skipping the others, as a rep would).
    await page.goto('/work')
    await expect(page.getByTestId('work-action')).toBeVisible()

    const brief = page.getByTestId('handoff-brief')
    for (let i = 0; i < 12 && (await brief.count()) === 0; i++) {
      await page.getByTestId('work-skip').click().catch(() => undefined)
      await page.waitForTimeout(400)
    }

    await expect(brief).toBeVisible()
    await expect(brief.getByTestId('handoff-reason')).toContainText(/\S/)
    // The compact form: the conversation is on the lead, not repeated on the queue card.
    await expect(brief.getByTestId('handoff-conversation')).toHaveCount(0)
    expect(id).toBeTruthy()
  })
})
