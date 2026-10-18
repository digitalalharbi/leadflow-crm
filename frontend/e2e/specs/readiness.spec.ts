import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * The deployment's own account of itself.
 *
 * Run against a fixture workspace on a developer machine, which is exactly the
 * situation where the honest answer is "nothing is live" — and that is the
 * assertion. A readiness screen that showed anything green here would be
 * reading the wrong thing.
 */
test.describe('Production readiness', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/readiness')
  })

  test('lists the six things a deployment is waiting on', async ({ page }) => {
    for (const key of ['vps', 'domain', 'google', 'whatsapp', 'twilio', 'ads']) {
      await expect(page.getByTestId(`readiness-${key}`), `${key} is missing`).toBeVisible()
    }
  })

  test('claims nothing is live from a developer machine', async ({ page }) => {
    for (const key of ['vps', 'domain', 'google', 'whatsapp', 'twilio', 'ads']) {
      await expect(page.getByTestId(`readiness-${key}-status`)).not.toContainText('مُجرَّب فعليًا')
    }

    await expect(page.getByTestId('readiness-vps-status')).toContainText('بانتظار التحقق الخارجي')
  })

  test('every waiting row says what it is waiting for', async ({ page }) => {
    // The sentence under the title is the point of the screen. A badge with no
    // reason is the unproven state without the information to act on it.
    for (const key of ['vps', 'domain', 'google', 'whatsapp']) {
      const card = page.getByTestId(`readiness-${key}`)

      await expect(card).not.toContainText('لا شيء بانتظار أحد')
    }
  })

  test('the checks that need the other end offer to run, and the server ones do not', async ({ page }) => {
    for (const key of ['google', 'whatsapp', 'twilio', 'ads']) {
      await expect(page.getByTestId(`commission-${key}`)).toBeVisible()
    }

    await expect(page.getByTestId('commission-vps')).toHaveCount(0)
    await expect(page.getByTestId('commission-domain')).toHaveCount(0)
  })

  test('running a check on missing credentials says so rather than passing', async ({ page }) => {
    await page.getByTestId('commission-whatsapp').click()

    const last = page.getByTestId('readiness-whatsapp-last')
    await expect(last).toBeVisible()
    await expect(last).toContainText('فشل')
    // And the row did not become ready on the strength of having been asked.
    await expect(page.getByTestId('readiness-whatsapp-status')).toContainText('بانتظار التحقق الخارجي')
  })

  test('never scrolls sideways', async ({ page }) => {
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))

    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })

  const INFRA = ['database', 'redis', 'queues', 'horizon', 'backups', 'logs', 'error_tracking']

  test('lists the seven things the platform stands on', async ({ page }) => {
    await expect(page.getByTestId('infrastructure')).toBeVisible()

    for (const key of INFRA) {
      await expect(page.getByTestId(`infra-${key}`), `${key} is missing`).toBeVisible()
    }
  })

  test('every row says where it stands, when it was checked, the last error and what to do', async ({ page }) => {
    for (const key of INFRA) {
      await expect(page.getByTestId(`infra-${key}-state`)).toBeVisible()
      await expect(page.getByTestId(`infra-${key}-checked`)).not.toBeEmpty()
      await expect(page.getByTestId(`infra-${key}-error`)).not.toBeEmpty()
      await expect(page.getByTestId(`infra-${key}-action`)).not.toBeEmpty()
    }
  })

  test('the database is the one thing that has been observed working', async ({ page }) => {
    // The page itself is a query that answered.
    await expect(page.getByTestId('infra-database-state')).toContainText('مُتحقَّق فعليًا')
  })

  test('a developer machine claims nothing it cannot have seen', async ({ page }) => {
    // No error tracker, no recorded backup: nothing here is entitled to green.
    await expect(page.getByTestId('infra-error_tracking-state')).not.toContainText('مُتحقَّق فعليًا')
    await expect(page.getByTestId('infra-backups-state')).not.toContainText('مُتحقَّق فعليًا')
    await expect(page.getByTestId('infra-backups-action')).not.toContainText('لا إجراء مطلوب')
  })
})
