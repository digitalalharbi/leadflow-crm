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
    expect(await page.title()).toBe('مهتم — حوّل الاهتمام إلى فرصة')

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
    await expect(page.getByTestId('page-deletion')).toContainText('info@mohtam.pro')
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

  test('the official facts stand where placeholders used to: name, mailbox, phone, city, site', async ({ page }) => {
    for (const path of ['/privacy', '/contact']) {
      await ready(page, path)
      const body = await page.locator('main').innerText()

      for (const fact of ['info@mohtam.pro', '+966 53 211 5582', 'الرياض، المملكة العربية السعودية', 'mohtam.pro']) expect(body, path).toContain(fact)
      expect(body, path).not.toMatch(/يُضاف قبل الإطلاق|example\.com/)
      await expect(page.getByTestId('doc-draft')).toHaveCount(0)
    }
  })

  test('the footer reaches the public pages, and a keyboard lands on the skip link first', async ({ page, isMobile, browserName }) => {
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

    const hrefs = await page.getByTestId('public-footer').getByRole('navigation').getByRole('link').evaluateAll((links) => links.map((a) => a.getAttribute('href')))
    expect(hrefs).toEqual(['/privacy', '/terms', '/data-deletion', '/security', '/contact', '/insights', '/about'])
  })

  test('anchor links glide unless the visitor asked for less motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await ready(page, '/')
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto')

    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await ready(page, '/')
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('smooth')
  })
})

test.describe('Marketing page', () => {
  for (const [width, height] of [[1366, 768], [1440, 900]] as const) {
    test(`the whole front door fits the first screen at ${width}×${height}: header, hero, picture and promises, nothing long below`, async ({ page, isMobile }) => {
      test.skip(isMobile, 'the first-screen rule is the laptop’s; a phone stacks the same blocks')
      await page.setViewportSize({ width, height })
      await ready(page, '/')

      for (const id of ['public-header', 'landing-headline', 'landing-trial', 'landing-login', 'product-preview', 'landing-values']) {
        await expect(page.getByTestId(id), id).toBeInViewport({ ratio: 0.95 })
      }
      await expect(page.getByTestId('feature-card')).toHaveCount(0)
      await expect(page.getByTestId('funnel-stage')).toHaveCount(0)
      // At most the slim footer is below the fold.
      expect(await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight)).toBeLessThanOrEqual(60)
    })
  }

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

  test('the header links open their own pages, each with one heading and the way in', async ({ page, isMobile }) => {
    test.skip(isMobile, 'the header links are the laptop’s; a phone has the menu')
    await page.setViewportSize({ width: 1366, height: 768 })
    await ready(page, '/')

    for (const [link, path, heading] of [['landing-nav-features', '/features', 'قيمة تراها من اليوم الأول'], ['landing-nav-journey', '/journey', 'رحلة العميل، من الاهتمام إلى النتيجة'], ['landing-nav-vision', '/vision', 'لماذا مهتم']] as const) {
      await page.getByTestId(link).click()
      await expect(page).toHaveURL(new RegExp(`${path}$`))
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)
      await expect(page.getByTestId(link)).toHaveAttribute('aria-current', 'page')
      await expect(page.getByTestId('marketing-cta-start')).toHaveAttribute('href', '/login')
    }
  })

  test('the journey is interactive: choosing a stage shows its value and moves the progress', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await ready(page, '/journey')

    const stages = page.getByTestId('journey-stage')
    await expect(stages).toHaveCount(6)
    await stages.nth(4).click()
    await expect(stages.nth(4)).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByTestId('journey-panel')).toContainText('قرار شراء')
  })

  for (const path of ['/', '/features', '/journey', '/vision']) {
    for (const [width, height] of [[390, 844], [768, 1024], [1366, 768], [1440, 900]] as const) {
      test(`${path} at ${width}px: nothing runs off the side, in Arabic and in English`, async ({ page }) => {
        await page.setViewportSize({ width, height })
        for (const [locale, dir] of [['ar', 'rtl'], ['en', 'ltr']] as const) {
          await page.addInitScript(([key, value]) => localStorage.setItem(key, value), ['leadflow.locale', locale] as const)
          await ready(page, path)
          await expect(page.locator('html')).toHaveAttribute('dir', dir)
          await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
          expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${path} ${dir}`).toBeLessThanOrEqual(1)
        }
      })
    }
  }

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

  test('on a laptop the brand panel stays as it was, and everything needed fits beside it without scrolling', async ({ page, isMobile }) => {
    test.skip(isMobile, 'the split layout is the laptop’s')
    await page.setViewportSize({ width: 1366, height: 768 })
    await ready(page, '/login')

    const panel = (await page.getByTestId('login-brand-panel').boundingBox())!
    const card = (await page.getByTestId('login-card').boundingBox())!
    // Arabic reads right to left: the panel is on the right, the card on the left.
    expect(panel.x).toBeGreaterThan(card.x)
    expect(card.width).toBeGreaterThanOrEqual(420)
    expect(card.width).toBeLessThanOrEqual(440)

    // The marketing side stays exactly what it was.
    await expect(page.getByTestId('login-badge')).toHaveText('منصة تشغيل التسويق والمبيعات العقارية')
    await expect(page.getByTestId('login-headline')).toHaveText('حوّل الاهتمام إلى فرصة')
    await expect(page.getByTestId('login-journey').locator('[data-step]')).toHaveText(['وصل', 'تواصل', 'تأهّل', 'موعد', 'صفقة'])
    await expect(page.getByTestId('login-pillars').getByRole('listitem')).toHaveCount(3)
    await expect(page.getByTestId('login-watermark')).toBeAttached()

    // The whole screen: no scrolling to find the form, and nothing sideways.
    for (const control of [page.getByLabel('البريد الإلكتروني'), page.getByLabel('كلمة المرور', { exact: true }), page.getByTestId('login-remember'), page.getByTestId('login-forgot'), page.getByRole('button', { name: 'تسجيل الدخول' }), page.getByRole('link', { name: 'الخصوصية' })]) {
      await expect(control).toBeInViewport()
    }
    expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBeLessThanOrEqual(769)
    await expectNoSidewaysScroll(page, 'login')
  })

  for (const width of [390, 430]) {
    test(`on a ${width}px phone: no split, the card, the journey, and everything large enough for a thumb`, async ({ page }) => {
      await page.setViewportSize({ width, height: 844 })
      await ready(page, '/login')

      await expect(page.getByTestId('login-brand-panel')).toBeHidden()
      await expect(page.getByTestId('login-mobile-intro')).toBeVisible()
      await expect(page.getByTestId('login-card')).toBeVisible()
      await expect(page.getByTestId('login-mobile-journey')).toBeVisible()
      await expectNoSidewaysScroll(page, 'login')

      for (const control of [page.getByLabel('البريد الإلكتروني'), page.getByLabel('كلمة المرور', { exact: true }), page.getByTestId('login-show-password'), page.getByTestId('login-forgot'), page.getByRole('button', { name: 'تسجيل الدخول' })]) {
        expect((await control.boundingBox())!.height).toBeGreaterThanOrEqual(44)
      }
      await expect(page.getByRole('button', { name: 'تسجيل الدخول' })).toBeInViewport()
    })
  }

  test('it offers only what works: email, password, remember me, forgot password, sign in — no Google, no sign-up, no organisation to type', async ({ page }) => {
    await ready(page, '/login')

    await expect(page.getByLabel('البريد الإلكتروني')).toBeVisible()
    await expect(page.getByLabel('كلمة المرور', { exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'تسجيل الدخول' })).toBeVisible()
    await expect(page.getByTestId('login-forgot')).toBeVisible()
    await expect(page.getByLabel('تذكرني على هذا الجهاز')).toBeChecked()
    await expect(page.getByText(/Google|Apple|إنشاء حساب|Sign up|رمز عبر البريد/)).toHaveCount(0)
    await expect(page.getByTestId('login-providers')).toHaveCount(0)
    await expect(page.getByLabel(/المؤسسة/)).toHaveCount(0)
  })

  test('Google comes after the form, and only when the server offers it', async ({ page }) => {
    await page.route('**/api/v1/auth/providers', (route) => route.fulfill({ json: { success: true, data: { providers: [{ key: 'google', label: 'Google', url: '/api/v1/auth/google/redirect' }] }, message: null, errors: null } }))
    await ready(page, '/login')

    const google = page.getByTestId('login-provider-google')
    await expect(google).toHaveText('المتابعة باستخدام Google')
    expect((await google.boundingBox())!.y).toBeGreaterThan((await page.getByRole('button', { name: 'تسجيل الدخول' }).boundingBox())!.y)
    expect((await google.boundingBox())!.height).toBeGreaterThanOrEqual(44)
    await expectNoSidewaysScroll(page, 'login')
  })

  test('a refusal from Google is said in words, and leaves the address clean', async ({ page }) => {
    await ready(page, '/login?google_error=no_account')

    await expect(page.getByRole('alert')).toContainText('لا يوجد حساب بهذا البريد')
    await expect(page).toHaveURL(/\/login$/)
  })

  test('the password can be shown and hidden', async ({ page }) => {
    await ready(page, '/login')
    const field = page.getByLabel('كلمة المرور', { exact: true })

    await expect(field).toHaveAttribute('type', 'password')
    await page.getByTestId('login-show-password').click()
    await expect(field).toHaveAttribute('type', 'text')
    await page.getByTestId('login-show-password').click()
    await expect(field).toHaveAttribute('type', 'password')
  })

  for (const remember of [true, false]) {
    test(`remember me ${remember ? 'on' : 'off'} is what the server is asked for, and the sign-in works`, async ({ page }) => {
      await ready(page, '/login')
      if (!remember) await page.getByLabel('تذكرني على هذا الجهاز').uncheck()

      await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
      await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
      const sent = page.waitForRequest((request) => request.url().includes('/auth/login') && request.method() === 'POST')
      await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

      expect((await sent).postDataJSON().remember).toBe(remember)
      await expect(page).not.toHaveURL(/\/login/)
    })
  }

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

  test('a workspace link still works, and asks for nothing more', async ({ page }) => {
    await ready(page, `/login?tenant=${ALPHA.slug}`)

    await expect(page.getByLabel(/المؤسسة/)).toHaveCount(0)
    await page.getByLabel('البريد الإلكتروني').fill(ALPHA.admin.email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD)
    const sent = page.waitForRequest((request) => request.url().includes('/auth/login') && request.method() === 'POST')
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click()

    expect((await sent).postDataJSON().tenant).toBe(ALPHA.slug)
    await expect(page).not.toHaveURL(/\/login/)
  })
})
