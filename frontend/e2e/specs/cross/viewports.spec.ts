import { expect, test } from '@playwright/test'
import { ALPHA } from '../../fixtures/data'
import { signInAs } from '../../fixtures/pages'
import { resetFixtures } from '../../fixtures/seed'
import { expectNoSidewaysScroll, firstLeadId, makeSharedReport } from './helpers'

/**
 * The sizes the product is used at: three phones, a tablet and the 1366×768 laptop most of its
 * customers have. On every one: no sideways scroll, the page's title drawn, and — below the laptop
 * breakpoint — a way to open the navigation.
 */
const SIZES = [
  { name: 'phone 375', width: 375, height: 812 },
  { name: 'phone 390', width: 390, height: 844 },
  { name: 'phone 430', width: 430, height: 932 },
  { name: 'tablet 768', width: 768, height: 1024 },
  { name: 'laptop 1366×768', width: 1366, height: 768 },
] as const

test.describe('Screen sizes', () => {
  test.skip(({ isMobile }) => isMobile, 'a phone project is already one of the sizes; this matrix resizes a desktop browser')
  test.beforeAll(() => resetFixtures())

  for (const size of SIZES) {
    test(`${size.name}: the working pages fit and draw`, async ({ page, browser }) => {
      await page.setViewportSize({ width: size.width, height: size.height })
      await signInAs(page, ALPHA.admin.email)
      const lead = await firstLeadId(ALPHA.admin.email)

      for (const path of ['/', '/work', '/leads', `/leads/${lead}`, '/campaigns', '/reports', '/settings', '/access']) {
        await page.goto(path)
        await expect(page.getByTestId('page-title').or(page.getByRole('heading', { level: 1 })).first(), `${size.name} ${path}`).toBeVisible()
        await expectNoSidewaysScroll(page, `${size.name} ${path}`)
      }

      // Below the laptop breakpoint the navigation is a drawer, and there is a button for it.
      await page.goto('/')
      if (size.width < 1024) await expect(page.getByTestId('app-header').getByRole('button').first()).toBeVisible()

      const { token } = await makeSharedReport(ALPHA.admin.email, `تقرير ${size.name}`)
      const stranger = await browser.newContext({ viewport: { width: size.width, height: size.height }, locale: 'ar-SA' })
      const open = await stranger.newPage()
      await open.goto(`/reports/share/${token}`)
      await expect(open.getByTestId('shared-title')).toBeVisible()
      await expectNoSidewaysScroll(open, `${size.name} shared report`)
      await stranger.close()
    })
  }
})
