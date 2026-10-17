import { expect, test } from '@playwright/test'
import { ALPHA, LEADS } from '../fixtures/data'
import { authenticateAs, openLeadByName } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * Email beside WhatsApp, on the same lead.
 *
 * The fixture workspace has no mail server of its own and the API runs on the
 * log transport, so every send here is the demo path — which is exactly what
 * these specs assert: it works, it is recorded, and the screen says plainly
 * that nothing reached a person.
 */
test.describe('Email', () => {
  test.beforeAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await openLeadByName(page, LEADS.chat)
    await page.getByTestId('tab-email').click()
  })

  test('says plainly that no mail server is connected', async ({ page }) => {
    await expect(page.getByTestId('email-panel')).toBeVisible()
    await expect(page.getByTestId('email-mock-notice')).toBeVisible()
  })

  test('an email can be written and sent, and appears on the lead', async ({ page }) => {
    const subject = `تفاصيل المشروع ${Date.now()}`

    await page.getByTestId('email-subject').fill(subject)
    await page.getByTestId('email-body').fill('مرحبًا، أرفقت لك التفاصيل.')
    await page.getByTestId('send-email').click()

    await expect(page.getByTestId('email-row').filter({ hasText: subject })).toBeVisible()
    await expect(page.getByTestId('email-status-sent').first()).toBeVisible()
  })

  test('a saved email fills the subject and the body', async ({ page }) => {
    const select = page.getByTestId('email-template-select')
    await expect(select).toBeVisible()

    await select.selectOption({ index: 1 })

    await expect(page.getByTestId('email-subject')).not.toHaveValue('')
    await expect(page.getByTestId('email-body')).not.toHaveValue('')
  })

  test('nothing can be sent without a subject', async ({ page }) => {
    await page.getByTestId('email-body').fill('نص بلا موضوع')
    await expect(page.getByTestId('send-email')).toBeDisabled()
  })

  test('unsubscribing a lead stops the composer', async ({ page }) => {
    await page.getByTestId('toggle-email-opt-out').click()

    await expect(page.getByTestId('email-opted-out-badge')).toBeVisible()
    await expect(page.getByTestId('email-subject')).toBeDisabled()
    await expect(page.getByTestId('send-email')).toBeDisabled()

    // And it is reversible by an admin.
    await page.getByTestId('toggle-email-opt-out').click()
    await expect(page.getByTestId('email-subject')).toBeEnabled()
  })

  test('WhatsApp is untouched by the new tab', async ({ page }) => {
    await page.getByTestId('tab-whatsapp').click()

    await expect(page.getByTestId('conversation')).toBeVisible()
    // Email never leaks into the WhatsApp thread.
    await expect(page.getByTestId('conversation')).not.toContainText('تفاصيل المشروع')
  })
})

test.describe('Email settings', () => {
  test.beforeAll(() => resetFixtures())

  test('an admin sees the mail card, with no password ever shown', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
    // The cards sit behind the page's detail toggle, closed by default.
    await page.getByTestId('toggle-integration-detail').click()

    const card = page.getByTestId('email-settings')
    await expect(card).toBeVisible()
    await expect(page.getByTestId('email-state')).toBeVisible()
    await expect(page.getByTestId('email-password')).toHaveValue('')
    // A test cannot be sent before there is a server to test.
    await expect(page.getByTestId('email-test')).toBeDisabled()
  })

  test('a saved password is stored and never read back', async ({ page }) => {
    await authenticateAs(page, ALPHA.admin.email)
    await page.goto('/integrations')
    await page.getByTestId('toggle-integration-detail').click()

    await page.getByTestId('email-host').fill('smtp.customer.test')
    await page.getByTestId('email-username').fill('apikey')
    await page.getByTestId('email-password').fill('a-very-secret-password')
    await page.getByTestId('email-from-address').fill('sales@customer.test')
    await page.getByTestId('email-save').click()

    await expect(page.getByText('تم الحفظ.')).toBeVisible()
    await expect(page.getByTestId('email-password')).toHaveValue('')
    await expect(page.locator('body')).not.toContainText('a-very-secret-password')
    // Entered, not proven: the word is the ladder's, never "connected".
    await expect(page.getByTestId('email-state')).not.toContainText('متصل')
  })

  test('a rep never sees the mail server settings', async ({ page }) => {
    await authenticateAs(page, ALPHA.rep1.email)
    await page.goto('/integrations')

    // Opened first when it can be: a closed section would pass this either way.
    const toggle = page.getByTestId('toggle-integration-detail')
    if (await toggle.count()) await toggle.click()

    await expect(page.getByTestId('email-settings')).toHaveCount(0)
  })
})
