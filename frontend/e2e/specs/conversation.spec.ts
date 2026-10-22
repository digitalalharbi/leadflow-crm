import { expect, test } from '@playwright/test'
import { apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA, LEADS, WHATSAPP } from '../fixtures/data'
import { authenticateAs, openLeadByName } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * A customer writes; the lead screen shows what they said as facts, says which
 * engine read it, and what they said is never asked again.
 */
test.describe('Conversation intelligence', () => {
  test.beforeAll(() => resetFixtures())

  test('a reply becomes facts on the lead, read by the rules because no Claude key is set', async ({ page }) => {
    const response = await postWhatsappWebhook(
      inboundWhatsappMessage(WHATSAPP.chatLeadPhone, 'ابي فيلا وميزانيتي مليون ونصف، وابي أزور الموقع بس السعر غالي', `wamid.e2e.facts.${Date.now()}`),
    )
    expect(response.status).toBe(200)

    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)

    const card = page.getByTestId('conversation-card')
    await expect(card).toBeVisible()
    await expect(card.getByTestId('conversation-engine')).toHaveAttribute('data-engine', 'rules')
    // Said plainly, with the reason: the rules read it because Claude is not configured.
    await expect(card.getByTestId('conversation-note')).toContainText(/\S/)

    await expect(card.getByTestId('fact-service')).toContainText('فيلا')
    await expect(card.getByTestId('fact-budget')).toBeVisible()
    await expect(card.getByTestId('fact-appointment')).toBeVisible()
    await expect(card.getByTestId('conversation-objections')).toContainText('السعر')
  })

  test('the customer\'s budget is carried to the lead and the facts can be read again on demand', async ({ page }) => {
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      await postWhatsappWebhook(inboundWhatsappMessage(WHATSAPP.chatLeadPhone, 'خلال شهرين ان شاء الله', `wamid.e2e.facts2.${Date.now()}`))

      await authenticateAs(page, ALPHA.admin.email)
      await openLeadByName(page, LEADS.chat)

      const card = page.getByTestId('conversation-card')
      await expect(card.getByTestId('fact-urgency')).toBeVisible()

      const read = page.waitForResponse((r) => r.url().includes('/conversation/analyze') && r.request().method() === 'POST')
      await card.getByTestId('conversation-analyze').click()
      expect((await read).ok()).toBeTruthy()
      await expect(card.getByTestId('conversation-facts')).toBeVisible()
    } finally {
      await admin.dispose()
    }
  })
})
