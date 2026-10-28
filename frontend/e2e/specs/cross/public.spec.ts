import { expect, test, type Page } from '@playwright/test'
import { ALPHA, PASSWORD } from '../../fixtures/data'
import { resetFixtures } from '../../fixtures/seed'
import { expectNoSidewaysScroll } from './helpers'

/**
 * The public face: the login, the marketing page and the six information pages — in every browser, on a
 * phone and a laptop. What is asserted is what was promised: the idea lands on one screen at 1366×768, the
 * pages fit every width, the login offers only controls that work, and nothing on a public page pretends to
 * an action the product has no backend for.
 */
const SIZES = [{ width: 375, height: 800 }, { width: 390, height: 844 }, { width: 430, height: 932 }, { width: 1366, height: 768 }]
const INFO = ['/privacy', '/terms', '/data-deletion', '/security', '/contact', '/about']

async function ready(page: Page, path: string) {
  await page.goto(path)
  await page.waitForLoadState('networkidle')
}

test.describe('Public pages', () => {
  for (const size of SIZES) {
    test(`${size.width}×${size.height}: the marketing page, the login and the six information pages fit without scrolling sideways`, async ({ page }) => {
      await page.setViewportSize(size)

      for (const path of ['/', '/login', ...INFO]) {
        await ready(page, path)
        await expect(page.getByRole('heading', { level: 1 }).first(), `${size.width} ${path}`).toBeVisible()
        await expectNoSidewaysScroll(page, `${size.width} ${path}`)
      }
    })
  }

  test('the information pages have one heading, the shared header and footer, a skip link, and the product’s title', async ({ page }) => {
    for (const path of INFO) {
      await ready(page, path)
      await expect(page.getByRole('heading', { level: 1 }), path).toHaveCount(1)
      await expect(page.getByTestId('public-header'), path).toBeVisible()
      await expect(page.getByTestId('public-footer'), path).toBeVisible()
      await expect(page.locator('main#main'), path).toHaveCount(1)
      expect(await page.title(), path).toContain('مهتم')
    }
  })

  test('the public pages are open to search engines; the app and the login are not', async ({ page }) => {
    const robots = () => page.locator('meta[name="robots"]').getAttribute('content')

    await ready(page, '/')
    expect(await robots()).toBe('index, follow')
    expect(await page.title()).toBe('مهتم | حوّل الاهتمام إلى فرصة')

    await ready(page, '/login')
    expect(await robots()).toBe('noindex, nofollow')
  })

  test('no information page has a form: a deletion, privacy or contact "form" would promise what the backend does not do', async ({ page }) => {
    for (const path of INFO) {
      await ready(page, path)
      await expect(page.locator('main form, main input, main textarea, main select'), path).toHaveCount(0)
    }
  })

  test('data deletion: four steps and a plain statement that there is no online form', async ({ page }) => {
    await ready(page, '/data-deletion')

    await expect(page.getByText(/لا يوجد نموذج إلكتروني/)).toBeVisible()
    await expect(page.locator('main li').filter({ hasText: /^[1-4]\. / })).toHaveCount(4)
    await expect(page.getByTestId('doc-draft')).toBeVisible()
  })

  test('security: names only what exists, and says plainly which certifications are not held', async ({ page }) => {
    await ready(page, '/security')
    const body = await page.getByTestId('page-security').innerText()

    for (const heading of ['عزل مساحات العمل', 'الصلاحيات حسب الدور', 'سجل التدقيق', 'تشفير بيانات الاعتماد', 'تحديد معدل الطلبات', 'حماية الجلسات']) expect(body).toContain(heading)
    // The certifications appear once, in the section that says they are not held.
    const claims = await page.locator('#claims').innerText()
    expect(claims).toContain('لا نحمل')
    expect(body.replace(claims, '')).not.toMatch(/SOC ?2|ISO ?27001|PCI|GDPR/)
  })

  test('where the deployment has not supplied the legal name or mailbox, the page says so in plain sight', async ({ page }) => {
    await ready(page, '/privacy')

    expect(await page.getByTestId('to-add').count()).toBeGreaterThan(0)
    await expect(page.getByTestId('doc-draft')).toBeVisible()
  })

  test('the footer reaches the six pages, and a keyboard lands on the skip link first', async ({ page, isMobile, browserName }) => {
    test.skip(isMobile, 'keyboard order is a desktop concern')
    await ready(page, '/')

    const skip = page.getByRole('link', { name: 'تخطَّ إلى المحتوى' })

    if (browserName === 'webkit') {
      // Safari does not Tab to links unless the user turns that on (Option+Tab otherwise): the link is still
      // focusable and shows itself when it is.
      await skip.focus()
    } else {
      await page.keyboard.press('Tab')
    }
    await expect(skip).toBeFocused()
    await expect(skip).toBeInViewport()

    const hrefs = await page.getByTestId('public-footer').getByRole('link').evaluateAll((links) => links.map((a) => a.getAttribute('href')))
    expect(hrefs).toEqual(['/privacy', '/terms', '/data-deletion', '/security', '/contact', '/about'])
  })

  test('anchor links glide unless the visitor asked for less motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await ready(page, '/')
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto')

    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await page.reload()
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('smooth')
  })
})

test.describe('Marketing page', () => {
  test('the first screen at 1366×768 has the header, the headline, the calls to action and the picture; the rest follows below', async ({ page, isMobile }) => {
    test.skip(isMobile, 'the first-screen rule is the laptop’s; a phone stacks the same blocks')
    await page.setViewportSize({ width: 1366, height: 768 })
    await ready(page, '/')

    for (const id of ['public-header', 'landing-headline', 'landing-trial', 'landing-login', 'product-preview']) {
      await expect(page.getByTestId(id), id).toBeInViewport({ ratio: 0.95 })
    }
    // What the product does, the whole path and the official funnel are on the page, below the fold.
    await expect(page.getByTestId('feature-card')).toHaveCount(14)
    await expect(page.getByTestId('funnel-stage')).toHaveCount(6)
    await expect(page.getByTestId('landing-chain')).toBeAttached()
    await expect(page.getByTestId('public-footer')).toBeAttached()
  })

  test('it says the official things, with the deal in copper, and labels the preview as sample data', async ({ page }) => {
    await ready(page, '/')

    await expect(page.getByTestId('landing-badge')).toHaveText('منصة تشغيل التسويق والمبيعات العقارية')
    await expect(page.getByTestId('landing-headline')).toHaveText('حوّل الاهتمام إلى فرصة')
    await expect(page.getByTestId('landing-journey-steps').locator('[data-step]')).toHaveText(['وصل', 'تواصل', 'تأهّل', 'موعد', 'صفقة'])
    await expect(page.getByTestId('preview-sample')).toHaveText('بيانات توضيحية')

    const copper = await page.getByTestId('landing-journey-steps').locator('[data-step="4"]').evaluate((el) => getComputedStyle(el).color)
    const ink = await page.getByTestId('landing-journey-steps').locator('[data-step="0"]').evaluate((el) => getComputedStyle(el).color)
    expect(copper).not.toBe(ink)
  })

  test('the calls to action lead somewhere real: start and sign in both go to the login — there is no sign-up', async ({ page }) => {
    await ready(page, '/')
    await expect(page.getByText(/إنشاء حساب|Sign up/)).toHaveCount(0)

    await page.getByTestId('landing-trial').click()
    await expect(page).toHaveURL(/\/login/)

    await page.goto('/')
    await page.getByTestId('landing-login').click()
    await expect(page).toHaveURL(/\/login/)
  })

  test('the header links reach the part of the page they name', async ({ page, isMobile }) => {
    test.skip(isMobile, 'the header links are the laptop’s; a phone has the menu')
    await page.setViewportSize({ width: 1366, height: 768 })
    await ready(page, '/')

    for (const [link, target] of [['landing-nav-features', '#features'], ['landing-nav-how', '#how'], ['landing-nav-reports', '#reports']] as const) {
      await page.getByTestId(link).click()
      await expect(page).toHaveURL(new RegExp(`${target}$`))
      await expect(page.locator(target)).toBeInViewport()
    }
  })

  test('the sample report opens as a dialog, says it is not real data, and closes with Escape', async ({ page }) => {
    await ready(page, '/')

    await page.getByTestId('sample-report-open').click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('أرقام توضيحية')
    await expect(dialog.getByTestId('sample-report')).toBeVisible()
    await expect(dialog.getByTestId('preview-sample')).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
  })

  test('a phone gets a menu with the same destinations and the way in', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'the menu is the phone’s')
    await ready(page, '/')

    await expect(page.getByTestId('public-menu-toggle')).toBeVisible()
    await page.getByTestId('public-menu-toggle').click()
    await expect(page.locator('#public-menu').getByRole('link', { name: 'ابدأ مع مهتم' })).toBeVisible()
    await expect(page.getByTestId('public-menu-toggle')).toHaveAttribute('aria-expanded', 'true')
  })
})

test.describe('Login', () => {
  test.beforeAll(() => resetFixtures())

  test('on a laptop: the brand panel at the start edge with the headline, the description, the journey and three points; the card beside it', async ({ page, isMobile }) => {
    test.skip(isMobile, 'the split layout is the laptop’s')
    await page.setViewportSize({ width: 1366, height: 768 })
    await ready(page, '/login')

    const panel = (await page.getByTestId('login-brand-panel').boundingBox())!
    const card = (await page.getByTestId('login-card').boundingBox())!
    // Arabic reads right to left: the panel is on the right, the card on the left.
    expect(panel.x).toBeGreaterThan(card.x)

    await expect(page.getByTestId('login-badge')).toHaveText('منصة تشغيل التسويق والمبيعات العقارية')
    await expect(page.getByTestId('login-headline')).toHaveText('حوّل الاهتمام إلى فرصة')
    await expect(page.getByTestId('login-journey').locator('[data-step]')).toHaveText(['وصل', 'تواصل', 'تأهّل', 'موعد', 'صفقة'])
    await expect(page.getByTestId('login-pillars').getByRole('listitem')).toHaveText(['جميع مصادر العملاء في مكان واحد', 'متابعة وأتمتة تلقائية خلال دقائق', 'كل صفقة مرتبطة بالمصدر والحملة التي جاءت بها'])
    await expect(page.getByTestId('login-watermark')).toBeAttached()

    // The whole screen: no scrolling to find the form.
    expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBeLessThanOrEqual(769)
  })

  test('on a phone there is no split: the logo, one line of headline, the card, the journey', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'the stacked layout is the phone’s')
    await ready(page, '/login')

    await expect(page.getByTestId('login-brand-panel')).toBeHidden()
    await expect(page.getByTestId('login-mobile-intro')).toBeVisible()
    await expect(page.getByTestId('login-card')).toBeVisible()
    await expect(page.getByTestId('login-mobile-journey')).toBeVisible()
    await expectNoSidewaysScroll(page, 'login')

    // The fields are large enough to type into with a thumb.
    for (const label of ['البريد الإلكتروني', 'كلمة المرور']) expect((await page.getByLabel(label, { exact: true }).boundingBox())!.height).toBeGreaterThanOrEqual(44)
  })

  test('it offers only what works: email, password, sign in, and where to go for a forgotten password — no Google, no sign-up, no remember-me', async ({ page }) => {
    await ready(page, '/login')

    await expect(page.getByLabel('البريد الإلكتروني')).toBeVisible()
    await expect(page.getByLabel('كلمة المرور', { exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'تسجيل الدخول' })).toBeVisible()
    await expect(page.getByText(/Google|إنشاء حساب|Sign up|تذكرني/)).toHaveCount(0)
    await expect(page.locator('input[type="checkbox"]')).toHaveCount(0)

    // The organisation field is there for the person who needs it.
    await expect(page.getByLabel('المؤسسة')).toHaveCount(0)
    await page.getByTestId('login-organization-toggle').click()
    await expect(page.getByLabel(/المؤسسة/)).toBeVisible()
  })

  test('a forgotten password leads to the self-service reset, which answers the same for any address', async ({ page }) => {
    await ready(page, '/login')

    await page.getByTestId('login-forgot').click()
    await expect(page).toHaveURL(/\/forgot-password$/)
    // The page is loaded on demand: fill it once it is there, and check the value held.
    await expect(page.getByTestId('forgot-password').getByTestId('page-title')).toBeVisible()
    await page.getByLabel('البريد الإلكتروني').fill('nobody-here@e2e.test')
    await expect(page.getByLabel('البريد الإلكتروني')).toHaveValue('nobody-here@e2e.test')
    await page.getByTestId('forgot-submit').click()
    await expect(page.getByTestId('forgot-sent')).toBeVisible()
  })

  test('the login still signs in, and the way to the marketing page is there', async ({ page }) => {
    await ready(page, '/login')
    await expect(page.getByTestId('login-about')).toHaveAttribute('href', '/')

    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    await expect(page.getByTestId('page-title')).toBeVisible()
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('a workspace link opens the organisation field by itself', async ({ page }) => {
    await ready(page, `/login?tenant=${ALPHA.slug}`)

    await expect(page.getByLabel(/المؤسسة/)).toHaveValue(ALPHA.slug)
  })
})
