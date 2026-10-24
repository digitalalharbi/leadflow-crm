import { expect, test } from '@playwright/test'
import { ALPHA } from '../../fixtures/data'
import { signIn, signInAs } from '../../fixtures/pages'
import { resetFixtures } from '../../fixtures/seed'
import { collectPageErrors, expectNoSidewaysScroll, firstLeadId, makeSharedReport } from './helpers'

/**
 * The paths that must work in every browser a customer might use — Chrome, Edge (the same engine),
 * Firefox, Safari — and on a phone: sign in, the dashboard, «اعمل الآن», a lead, campaigns, reports,
 * sharing a report, access, settings. Each is driven the way a person would and checked for the one
 * thing that differs between browsers more than any other: that it renders, and does not spill sideways.
 */
test.describe('Critical paths, in every browser', () => {
  test.beforeAll(() => resetFixtures())

  test('sign in with the real form lands on the dashboard', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signIn(page, ALPHA.admin.email)
    await expect(page).toHaveURL(/\/$/)
    await expectNoSidewaysScroll(page, 'dashboard after sign-in')
    expect(errors).toEqual([])
  })

  test('the dashboard draws the operations numbers and the lead flow chart', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.admin.email)
    await expect(page.getByTestId('ops-pulse')).toBeVisible()
    await expect(page.getByTestId('lead-flow').locator('svg[role="img"]')).toBeVisible()
    const box = await page.getByTestId('lead-flow').locator('svg[role="img"]').boundingBox()
    expect(box!.width).toBeGreaterThan(100)
    expect(box!.height).toBeGreaterThan(60)
    await expectNoSidewaysScroll(page, 'dashboard')
    expect(errors).toEqual([])
  })

  test('«اعمل الآن» opens as the rep’s own screen', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.rep1.email)
    await page.goto('/work')
    await expect(page.getByTestId('page-title')).toBeVisible()
    await expect(page).toHaveURL(/\/work/)
    await expectNoSidewaysScroll(page, 'work')
    expect(errors).toEqual([])
  })

  test('a lead workspace opens from its address', async ({ page }) => {
    const errors = collectPageErrors(page)
    const id = await firstLeadId(ALPHA.admin.email)
    await signInAs(page, ALPHA.admin.email)
    await page.goto(`/leads/${id}`)
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
    await expectNoSidewaysScroll(page, 'lead workspace')
    expect(errors).toEqual([])
  })

  test('campaigns: the operations tab opens', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/campaigns')
    await page.getByRole('tab', { name: 'التشغيل' }).click()
    await expect(page.getByTestId('campaign-operations')).toBeVisible()
    await expectNoSidewaysScroll(page, 'campaigns')
    expect(errors).toEqual([])
  })

  test('reports: the live-sharing tab and the builder open', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.admin.email)
    // The insight strip arrives on its own and pushes the tabs down; press the tab once it has landed.
    await page.goto('/reports')
    await expect(page.getByTestId('insight-strip')).toBeVisible()
    await page.getByTestId('tab-share').click()
    await expect(page.getByTestId('share-tab')).toBeVisible()
    await page.getByTestId('new-share').click()
    await expect(page.getByTestId('share-builder')).toBeVisible()
    await expectNoSidewaysScroll(page, 'reports')
    expect(errors).toEqual([])
  })

  test('a shared report opens for a stranger, with its charts, and fits the screen', async ({ page, browser }) => {
    const { token } = await makeSharedReport(ALPHA.admin.email)
    const stranger = await browser.newContext({ ...(test.info().project.use as object), storageState: undefined })
    const open = await stranger.newPage()
    const errors = collectPageErrors(open)

    await open.goto(`/reports/share/${token}`)
    await expect(open.getByTestId('shared-title')).toBeVisible()
    await expect(open.getByTestId('shared-exec_marketing')).toBeVisible()
    await expect(open.getByTestId('shared-exec_sales')).toBeVisible()
    await expectNoSidewaysScroll(open, 'shared report')
    expect(errors).toEqual([])
    await stranger.close()
  })

  test('access: the role builder opens for the admin', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/access')
    await expect(page.getByTestId('page-title')).toBeVisible()
    await expectNoSidewaysScroll(page, 'access')
    expect(errors).toEqual([])
  })

  test('settings: the alert and delivery tabs open', async ({ page }) => {
    const errors = collectPageErrors(page)
    await signInAs(page, ALPHA.admin.email)
    await page.goto('/settings')
    await page.getByTestId('settings-tab-alerts').click()
    await expect(page.getByTestId('anomaly-list')).toBeVisible()
    await page.getByTestId('settings-tab-delivery').click()
    await expect(page.getByTestId('email-delivery')).toBeVisible()
    await expectNoSidewaysScroll(page, 'settings')
    expect(errors).toEqual([])
  })
})
