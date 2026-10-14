import { expect, test } from '@playwright/test'
import { apiGet, apiLogin, inboundWhatsappMessage, postWhatsappWebhook } from '../fixtures/api'
import { ALPHA, LEADS, WHATSAPP } from '../fixtures/data'
import { authenticateAs, openLeadByName } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Messaging a lead and reading what they send back.
 *
 * The fixture workspace has a phone number and an app secret but no access
 * token, so the mock provider serves: the same table, the same statuses and the
 * same webhook path a real Meta account would use.
 */
test.describe('WhatsApp', () => {
  test.beforeAll(() => resetFixtures())

  test('the settings screen shows what is connected without showing any secret', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
    // The connection cards sit behind the page's detail toggle, closed by
    // default so the source grid owns the first viewport.
    await page.getByTestId('toggle-integration-detail').click()

    const card = page.getByTestId('whatsapp-settings')
    await expect(card).toBeVisible()

    // Configured enough to route, not enough to send for real.
    await expect(page.getByTestId('whatsapp-status')).toBeVisible()
    await expect(card.getByTestId('wa-phone-number-id')).toHaveValue(WHATSAPP.phoneNumberId)

    // The stored secrets are reported as present and never rendered.
    await expect(card.getByTestId('wa-app-secret')).toHaveValue('')
    await expect(page.locator('body')).not.toContainText(WHATSAPP.appSecret)
    await expect(page.locator('body')).not.toContainText(WHATSAPP.verifyToken)
  })

  test('an admin can save the connection', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
    await page.getByTestId('toggle-integration-detail').click()

    await page.getByTestId('wa-business-account-id').fill('900900900')
    await page.getByTestId('wa-save').click()

    await expect(page.getByText('تم الحفظ.')).toBeVisible()
  })

  test('a sales rep never sees the connection settings', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/integrations')

    /*
     * Opened first, deliberately.
     *
     * The card lives inside this section, and a closed section would make this
     * test pass whether or not the rule holds — the one failure mode a deny
     * test has. If the toggle is not there for a rep either, the rep cannot
     * reach the card by any route, which is the same guarantee.
     */
    const toggle = page.getByTestId('toggle-integration-detail')

    if (await toggle.count()) {
      await toggle.click()
      await expect(page.getByTestId('integration-detail')).toBeVisible()
    }

    // Credentials are admin-only; the rep still gets the page.
    await expect(page.getByTestId('whatsapp-settings')).toHaveCount(0)
  })

  test('a message sent from the lead screen appears in the conversation', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)

    const conversation = page.getByTestId('conversation')
    await expect(conversation).toBeVisible()

    const body = `رسالة اختبار ${Date.now()}`
    await conversation.getByTestId('message-input').fill(body)
    await conversation.getByTestId('send-message').click()

    await expect(conversation.getByText(body)).toBeVisible()
    await expect(conversation.getByTestId('message-outbound').last()).toContainText(body)
  })

  test('the seeded conversation shows both directions', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)

    const conversation = page.getByTestId('conversation')

    await expect(conversation.getByTestId('message-outbound').first()).toBeVisible()
    await expect(conversation.getByTestId('message-inbound').first()).toContainText('نعم مهتم')
  })

  test('a reply delivered by webhook shows up on the lead', async ({ page }) => {
    const text = `رد وارد ${Date.now()}`

    const response = await postWhatsappWebhook(
      inboundWhatsappMessage(WHATSAPP.chatLeadPhone, text, `wamid.e2e.in.${Date.now()}`),
    )

    expect(response.status).toBe(200)
    expect(response.body.data?.messages).toBe(1)

    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)

    await expect(page.getByTestId('conversation').getByText(text)).toBeVisible()
  })

  test('an unsigned delivery is refused', async ({ page }) => {
    const response = await postWhatsappWebhook(
      inboundWhatsappMessage(WHATSAPP.chatLeadPhone, 'بدون توقيع', `wamid.e2e.unsigned.${Date.now()}`),
      'the-wrong-secret-entirely',
    )

    expect(response.status).toBe(401)
  })

  test('a reply from a number nobody holds does not create a lead', async ({ page }) => {
    const response = await postWhatsappWebhook(
      inboundWhatsappMessage('966599888777', 'من أنتم؟', `wamid.e2e.unknown.${Date.now()}`),
    )

    expect(response.status).toBe(200)
    expect(response.body.data?.unmatched).toBe(1)

    // The claim is that no customer was invented, so ask the list that question
    // directly rather than matching on whichever empty-state wording renders.
    const session = await apiLogin(ALPHA.admin.email)

    try {
      const leads = await apiGet<Array<{ phone: string }>>(session, '/api/v1/leads?search=599888777')
      expect(leads).toHaveLength(0)
    } finally {
      await session.dispose()
    }

    await authenticateAs(page, ALPHA.admin.email)
  })

  test('a lead who opts out can no longer be messaged', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)

    const conversation = page.getByTestId('conversation')
    await conversation.getByTestId('toggle-opt-out').click()

    await expect(conversation.getByTestId('opted-out-badge')).toBeVisible()
    await expect(conversation.getByTestId('message-input')).toBeDisabled()

    // And back, so the fixture is left as it was found.
    await conversation.getByTestId('toggle-opt-out').click()
    await expect(conversation.getByTestId('opted-out-badge')).toHaveCount(0)
  })
})
