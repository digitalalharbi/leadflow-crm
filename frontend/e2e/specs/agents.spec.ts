import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'
import { resetFixtures } from '../fixtures/seed'

/**
 * The agents' conversation. This workspace has no AI key, which is the case
 * worth proving: the conversation still works, from the rules, and says so.
 */
test.describe('الوكلاء — talking to the agents', () => {
  test.beforeAll(() => resetFixtures())
  test.afterAll(() => resetFixtures())

  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/agents')
  })

  test('the AI state is stated, not hidden', async ({ page }) => {
    await expect(page.getByTestId('ai-status')).toHaveAttribute('data-state', 'blocked')
    await expect(page.getByTestId('ai-status')).toContainText('غير مفعّل')
  })

  test('a question is answered with the figures themselves, marked as from the rules', async ({ page }) => {
    await page.getByTestId('suggest-performance').click()

    const reply = page.getByTestId('msg-agent').last()
    await expect(reply).toHaveAttribute('data-mode', 'rules')
    await expect(reply.getByTestId('rules-mode')).toBeVisible()
    await expect(reply.getByTestId('block-kpis')).toBeVisible()
  })

  test('a chosen agent answers in its own role and the conversation continues', async ({ page }) => {
    await page.getByTestId('pick-creative').click()
    await page.getByTestId('chat-input').fill('راجع الإعلانات')
    await page.getByTestId('chat-send').click()
    await expect(page.getByTestId('msg-agent').last()).toContainText('وكيل الإبداع')

    await page.getByTestId('pick-analyst').click()
    await page.getByTestId('chat-input').fill('ما أفضل الحملات؟')
    await page.getByTestId('chat-input').press('Enter')
    await expect(page.getByTestId('msg-user')).toHaveCount(2)
    await expect(page.getByTestId('msg-agent').last()).toContainText('المحلل')
  })

  test('a sales rep is not offered the agents', async ({ page }) => {
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/dashboard')
    await expect(page.locator('a[href="/agents"]')).toHaveCount(0)
  })
})
