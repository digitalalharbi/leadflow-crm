import { expect, test } from '@playwright/test'
import { ALPHA } from '../fixtures/data'
import { signInAs } from '../fixtures/pages'

/**
 * The development view, in a browser.
 *
 * One of these tests is the reason the feature exists: a deployment the
 * deployer called a success and nobody verified must never read as live. The
 * previous pipeline showed exactly that state as "deployed" while nothing had
 * shipped, and every screen agreed with it.
 */
test.describe('Development', () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/projects')
    await page.getByRole('link', { name: ALPHA.projects[0] }).first().click()
    // `tab-<key>`, which is what the shared Tabs primitive emits since the
    // project screen moved onto it.
    await page.getByTestId('tab-development').click()
  })

  test('it shows the repository and whether it is wired up', async ({ page }) => {
    await expect(page.getByText('acme/portal')).toBeVisible()
    await expect(page.getByTestId('repository-readiness')).toContainText('جاهز')
  })

  test('the build is shown per environment, not as one number', async ({ page }) => {
    await expect(page.getByTestId('run-بيئة الإنتاج')).toContainText('نجح')
    await expect(page.getByTestId('run-بيئة الاختبار')).toContainText('فشل')
  })

  /**
   * The one that matters. GitHub's success means the deploy job finished; it
   * does not mean anybody asked the application whether it answers.
   */
  test('a deployment nobody verified does not read as live', async ({ page }) => {
    const production = page.getByTestId('deployment-production')

    await expect(production).toContainText('لم يُتحقَّق منه')
    await expect(production).not.toContainText('ومُتحقَّق')
  })

  test('a failed deployment says so', async ({ page }) => {
    await expect(page.getByTestId('deployment-staging')).toContainText('فشل')
  })

  /** Approving a branch that does not build is a review of something that cannot ship. */
  test('a failing build outranks an approval on the pull request list', async ({ page }) => {
    await expect(page.getByTestId('pr-93-readiness')).toContainText('جاهز للدمج')
    await expect(page.getByTestId('pr-92-readiness')).toContainText('البناء فاشل')
  })

  test('the timeline reads newest first and names who did it', async ({ page }) => {
    const activity = page.getByTestId('development-activity')

    await expect(activity).toBeVisible()
    await expect(activity.locator('li').first()).toContainText('فشل البناء')
    await expect(activity).toContainText('sara')
  })

  test('nothing on the screen cost an API call to GitHub', async ({ page }) => {
    // Everything shown was written by a webhook or the sweep. A page that
    // called GitHub to render would be the first thing to exhaust the budget.
    const calls: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('api.github.com')) calls.push(request.url())
    })

    await page.reload()
    // `tab-<key>`, which is what the shared Tabs primitive emits since the
    // project screen moved onto it.
    await page.getByTestId('tab-development').click()
    await expect(page.getByText('acme/portal')).toBeVisible()

    expect(calls).toEqual([])
  })
})
