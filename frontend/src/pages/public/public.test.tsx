import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { LandingPage } from './Landing'
import { FeaturesPage, JourneyPage, VisionPage } from './Marketing'
import { DocumentPage } from './DocumentPage'
import { LoginPage } from '@/pages/Login'
import { LEGAL, type LegalKey } from '@/content/legal'
import { useAuth } from '@/stores/auth'
import { renderWithProviders } from '@/test/utils'

const PAGES: Array<[LegalKey, string]> = [['privacy', 'سياسة الخصوصية'], ['terms', 'الشروط والأحكام'], ['deletion', 'طلب حذف البيانات'], ['security', 'الأمان وحماية البيانات'], ['contact', 'التواصل'], ['about', 'عن مهتم']]

describe('public information pages', () => {
  it.each(PAGES)('%s has one heading, the shared header and footer, and a way into the product', (key, title) => {
    renderWithProviders(<DocumentPage page={key} />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByTestId('doc-title')).toHaveTextContent(title)
    expect(screen.getByTestId('public-header')).toBeInTheDocument()
    expect(screen.getByTestId('public-footer')).toBeInTheDocument()
    expect(screen.getByTestId('public-login')).toHaveAttribute('href', '/login')
  })

  it.each(PAGES)('%s offers no form: nothing on it promises an action the backend does not have', (key) => {
    const { container } = renderWithProviders(<DocumentPage page={key} />)

    expect(container.querySelector('form, input, textarea, select')).toBeNull()
  })

  it('carries the official facts and no placeholder marker', () => {
    const { container } = renderWithProviders(<DocumentPage page="privacy" />)

    expect(container.textContent).toContain('info@mohtam.pro')
    expect(container.textContent).toContain('+966 53 211 5582')
    expect(container.textContent).toContain('الرياض، المملكة العربية السعودية')
    expect(container.textContent).not.toMatch(/يُضاف قبل الإطلاق|to be added before launch|example\.com/)
    expect(screen.queryByTestId('doc-draft')).toBeNull()
  })

  it('every section has an id and a table-of-contents entry that leads to it', () => {
    const { container } = renderWithProviders(<DocumentPage page="privacy" />)
    const toc = screen.getByTestId('doc-toc')

    for (const section of LEGAL.privacy.ar.sections) {
      expect(container.querySelector(`#${section.id}`)).not.toBeNull()
      expect(within(toc).getByRole('link', { name: section.heading })).toHaveAttribute('href', `#${section.id}`)
    }
  })

  it('data deletion is four numbered steps and says plainly that there is no online form', () => {
    renderWithProviders(<DocumentPage page="deletion" />)

    expect(screen.getByText(/لا يوجد نموذج إلكتروني/)).toBeInTheDocument()
    expect(screen.getAllByText(/^[1-4]\. /)).toHaveLength(4)
  })

  it('the contact page tells a person who forgot their password who resets it, and is the target of the login link', () => {
    renderWithProviders(<DocumentPage page="contact" />)

    expect(document.getElementById('password')).not.toBeNull()
    expect(screen.getByText(/يصلك رابط صالح لساعة واحدة/)).toBeInTheDocument()
  })
})

describe('landing page', () => {
  it('says the official things: the name, the headline, the description, the journey with the deal last', () => {
    renderWithProviders(<LandingPage />)

    expect(screen.getByTestId('landing-headline')).toHaveTextContent('حوّل الاهتمام إلى فرصة')
    expect(screen.getByTestId('landing-sub')).toHaveTextContent('أول من يصل إليه، وآخر من يفرّط فيه')
    const steps = within(screen.getByTestId('landing-journey-steps')).getAllByRole('listitem').map((li) => li.textContent?.replace(/[←→]/g, '').trim())

    expect(steps).toEqual(['وصل', 'تواصل', 'تأهّل', 'موعد', 'صفقة'])
  })

  it('has only calls to action that lead somewhere real: start and sign in both go to the login — there is no sign-up to pretend', () => {
    const { container } = renderWithProviders(<LandingPage />)

    expect(screen.getByTestId('landing-login')).toHaveAttribute('href', '/login')
    expect(screen.getByTestId('landing-trial')).toHaveAttribute('href', '/login')
    expect(screen.getByTestId('landing-trial')).toHaveTextContent('ابدأ مع مهتم')
    expect(screen.getByTestId('landing-login')).toHaveTextContent('تسجيل الدخول')
    expect(container.querySelector('form, input')).toBeNull()
    // No sign-up, no Google, no demo-booking that does not exist.
    expect(container.textContent).not.toMatch(/Google (Login|sign)|تسجيل بحساب|إنشاء حساب|Sign up/i)
  })

  it('a signed-in visitor stays on the page and is offered the dashboard instead of the login', () => {
    useAuth.setState({ status: 'authenticated', isSuperAdmin: false })
    renderWithProviders(<LandingPage />)

    expect(screen.getByTestId('landing-trial')).toHaveAttribute('href', '/dashboard')
    expect(screen.getByTestId('landing-trial')).toHaveTextContent('فتح لوحة التحكم')
    expect(screen.getByTestId('public-login')).toHaveAttribute('href', '/dashboard')
    expect(screen.queryByTestId('landing-login')).toBeNull()
    useAuth.setState({ status: 'anonymous' })
  })

  it('a platform owner who is signed in is offered the platform, not a company dashboard', () => {
    useAuth.setState({ status: 'authenticated', isSuperAdmin: true })
    renderWithProviders(<LandingPage />)

    expect(screen.getByTestId('landing-trial')).toHaveAttribute('href', '/platform')
    useAuth.setState({ status: 'anonymous', isSuperAdmin: false })
  })

  it('draws the product preview from sample data and labels it as such', () => {
    renderWithProviders(<LandingPage />)

    const preview = screen.getByTestId('product-preview')

    expect(within(preview).getByTestId('preview-sample')).toHaveTextContent('بيانات توضيحية')
    for (const word of ['العملاء', 'المهتمون', 'المواعيد', 'الحجوزات', 'الإيراد']) expect(within(preview).getAllByText(word).length).toBeGreaterThan(0)
    expect(within(preview).getByTestId('preview-funnel').querySelectorAll('li')).toHaveLength(6)
  })

  it('is one screen: the hero, the picture and the short promises — the long feature list and the funnel live on their own pages', () => {
    const { container } = renderWithProviders(<LandingPage />)

    expect(screen.getAllByTestId('value-card')).toHaveLength(3)
    expect(screen.getByTestId('reports-card')).toHaveTextContent('صورة واضحة في أي لحظة')
    for (const gone of ['landing-features', 'feature-card', 'landing-funnel', 'funnel-stage', 'landing-chain', 'landing-closing-block']) {
      expect(screen.queryAllByTestId(gone), gone).toHaveLength(0)
    }
    expect(container.textContent).not.toContain('كل ما تحتاجه لتحويل الاهتمام إلى فرصة')
    expect(container.textContent).not.toContain('المسار العقاري في مهتم')
    // The product's name is مهتم alone.
    expect(container.textContent).not.toMatch(/Mohtam(?!\.pro)|LeadFlow/i)
  })

  it('the header names the three marketing pages by their own addresses, not by anchors on this one', () => {
    renderWithProviders(<LandingPage />)

    const nav = screen.getByTestId('public-nav')
    expect(within(nav).getAllByRole('link').map((a) => [a.textContent, a.getAttribute('href')])).toEqual([['المزايا', '/features'], ['الرحلة', '/journey'], ['الرؤية', '/vision']])
  })

  it('footer links lead to the public pages', () => {
    renderWithProviders(<LandingPage />)

    const footer = screen.getByTestId('public-footer')
    const links = within(within(footer).getByRole('navigation')).getAllByRole('link').map((a) => a.getAttribute('href'))

    expect(links).toEqual(['/privacy', '/terms', '/data-deletion', '/security', '/contact', '/insights', '/about'])
    // The front door's footer is one short line; the contact details are on every other public page.
    expect(screen.queryByTestId('footer-contact')).toBeNull()
  })

  it('the other public pages keep the full footer with the official contact', () => {
    renderWithProviders(<DocumentPage page="about" />)

    expect(within(screen.getByTestId('footer-contact')).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['mailto:info@mohtam.pro', 'tel:+966532115582'])
  })
})

// Words that would explain how the product works rather than what it gives — none belong on a marketing page.
const MECHANISM = /أتمتة|الأتمتة|إسناد|توزيع|تقييم العميل|قواعد|خوارزم|تكامل|automation|attribution|routing|scoring|integration|workflow|webhook|API/i

describe('marketing pages', () => {
  it('/features sells eight outcomes with facts that count up, and explains no mechanism', () => {
    const { container } = renderWithProviders(<FeaturesPage />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByTestId('marketing-title')).toHaveTextContent('قيمة تراها من اليوم الأول')
    expect(screen.getAllByTestId('feature-value').map((li) => li.querySelector('h2')?.textContent)).toEqual(['رؤية أوضح', 'متابعة أسرع', 'قرارات أفضل', 'فرص فقد أقل', 'تقارير احترافية', 'فرق أكثر تنظيمًا', 'وصول أسرع للمعلومة', 'تجربة أرقى لعملائك'])
    expect(within(screen.getByTestId('features-facts')).getAllByRole('definition')).toHaveLength(4)
    expect(container.textContent).not.toMatch(MECHANISM)
    expect(screen.getByTestId('marketing-cta-start')).toHaveAttribute('href', '/login')
  })

  it('/journey walks the six stages; choosing one shows its value and moves the progress', () => {
    const { container } = renderWithProviders(<JourneyPage />)

    const stages = screen.getAllByRole('tab')
    expect(stages.map((tab) => tab.textContent?.replace(/^\d/, ''))).toEqual(['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة'])
    expect(stages[0]).toHaveAttribute('aria-selected', 'true')

    fireEvent.click(stages[3]!)
    expect(stages[3]).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tabpanel')).toHaveTextContent('يصبح الاهتمام موعدًا')
    expect(screen.getByTestId('journey-progress')).toHaveStyle({ width: '60%' })
    expect(stages.filter((tab) => tab.dataset.reached === 'true')).toHaveLength(4)

    fireEvent.keyDown(stages[3]!, { key: 'End' })
    expect(stages[5]).toHaveAttribute('aria-selected', 'true')
    expect(container.textContent).not.toMatch(MECHANISM)
  })

  it('/vision is short: one statement, three pillars, four reasons', () => {
    const { container } = renderWithProviders(<VisionPage />)

    expect(screen.getByTestId('marketing-title')).toHaveTextContent('لماذا مهتم')
    expect(screen.getByTestId('vision-statement')).toHaveTextContent('كل اهتمام يستحق أن يصبح فرصة')
    expect(within(screen.getByTestId('vision-pillars')).getAllByRole('listitem')).toHaveLength(3)
    expect(within(screen.getByTestId('vision-why')).getAllByRole('listitem')).toHaveLength(4)
    expect(container.textContent).not.toMatch(MECHANISM)
  })
})

describe('login page', () => {
  it('has the real controls and no pretend ones: email, password, remember me, forgot password, sign in — no Google, no sign-up', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const { container } = renderWithProviders(<LoginPage />)

    expect(screen.getByLabelText('البريد الإلكتروني')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور', { exact: true })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'تسجيل الدخول' })).toBeInTheDocument()
    expect(screen.getByLabelText('تذكرني على هذا الجهاز')).toBeChecked()
    expect(screen.getByTestId('login-forgot')).toHaveAttribute('href', '/forgot-password')
    expect(container.textContent).not.toMatch(/Google|Apple|إنشاء حساب|Sign up|رمز عبر البريد/i)
    expect(screen.queryByTestId('login-providers')).toBeNull()
  })

  it('sends a forgotten password to the self-service reset, which emails a one-use link', () => {
    renderWithProviders(<LoginPage />)

    expect(screen.getByTestId('login-forgot')).toHaveAttribute('href', '/forgot-password')
  })

  it('asks nobody to type an organisation, even from a link that names one', () => {
    renderWithProviders(<LoginPage />, { route: '/login?tenant=acme' })
    expect(screen.queryByLabelText(/المؤسسة/)).toBeNull()
  })

  it('says the official things on the brand panel and leads to the marketing page', () => {
    renderWithProviders(<LoginPage />)

    expect(screen.getByTestId('login-headline')).toHaveTextContent('حوّل الاهتمام إلى فرصة')
    expect(screen.getByTestId('login-badge')).toHaveTextContent('منصة تشغيل التسويق والمبيعات العقارية')
    expect(screen.getByTestId('login-description')).toHaveTextContent('امنح فريق المبيعات الأولوية للعملاء الأكثر جاهزية')
    expect(within(screen.getByTestId('login-pillars')).getAllByRole('listitem')).toHaveLength(3)
    expect(screen.getByTestId('login-contact')).toHaveAttribute('href', '/contact')
    expect(screen.getByTestId('login-about')).toHaveAttribute('href', '/')
  })
})
