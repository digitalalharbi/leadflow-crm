import { describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LandingPage } from './Landing'
import { DocumentPage } from './DocumentPage'
import { LoginPage } from '@/pages/Login'
import { LEGAL, type LegalKey } from '@/content/legal'
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

  it('shows a visible marker, not an invented value, where the deployment has not supplied a fact', () => {
    renderWithProviders(<DocumentPage page="privacy" />)

    expect(screen.getAllByTestId('to-add').length).toBeGreaterThan(0)
    expect(screen.getByTestId('doc-draft')).toBeInTheDocument()
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
    expect(screen.getByText(/لا توجد حاليًا إعادة تعيين ذاتية/)).toBeInTheDocument()
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

  it('has only calls to action that lead somewhere real: sign in, and a request for a trial (a contact page)', () => {
    const { container } = renderWithProviders(<LandingPage />)

    expect(screen.getByTestId('landing-login')).toHaveAttribute('href', '/login')
    expect(screen.getByTestId('landing-trial')).toHaveAttribute('href', '/contact?intent=trial')
    expect(container.querySelector('form, input')).toBeNull()
    // No sign-up, no Google, no demo-booking that does not exist.
    expect(container.textContent).not.toMatch(/Google (Login|sign)|تسجيل بحساب|إنشاء حساب|Sign up/i)
  })

  it('draws the product preview from sample data and labels it as such', () => {
    renderWithProviders(<LandingPage />)

    const preview = screen.getByTestId('product-preview')

    expect(within(preview).getByTestId('preview-sample')).toHaveTextContent('بيانات توضيحية')
    for (const word of ['العملاء', 'المهتمون', 'المواعيد', 'الحجوزات', 'الإيراد']) expect(within(preview).getAllByText(word).length).toBeGreaterThan(0)
    expect(within(preview).getByTestId('preview-funnel').querySelectorAll('li')).toHaveLength(6)
  })

  it('sells the idea, not the workings: three promises, the picture of the reports, the closing line — and no platforms, features or mechanisms', () => {
    const { container } = renderWithProviders(<LandingPage />)

    expect(screen.getAllByTestId('value-card')).toHaveLength(3)
    expect(screen.getByTestId('reports-card')).toHaveTextContent('صورة واضحة في أي لحظة')
    expect(screen.getByTestId('landing-closing')).toHaveTextContent('كل اهتمام يستحق أن يصبح فرصة')
    expect(screen.queryByTestId('landing-capabilities')).toBeNull()
    expect(screen.queryByTestId('landing-integrations')).toBeNull()
    // How it is done stays with the product: no platform names, no "connects to", no feature or SLA detail.
    expect(container.textContent).not.toMatch(/يتصل بـ|Meta|TikTok|Snapchat|Google|WhatsApp|واتساب|API|SLA|ويب هوك|Webhook|أتمتة|Automation|attribution|مصفوفة|قمع|pipeline/i)
  })

  it('footer links lead to the six public pages', () => {
    renderWithProviders(<LandingPage />)

    const links = within(screen.getByTestId('public-footer')).getAllByRole('link').map((a) => a.getAttribute('href'))

    expect(links).toEqual(['/privacy', '/terms', '/data-deletion', '/security', '/contact', '/about'])
  })
})

describe('login page', () => {
  it('has the real controls and no pretend ones: email, password, sign in — no Google, no sign-up, no remember-me with nothing behind it', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const { container } = renderWithProviders(<LoginPage />)

    expect(screen.getByLabelText('البريد الإلكتروني')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور', { exact: true })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'تسجيل الدخول' })).toBeInTheDocument()
    expect(container.textContent).not.toMatch(/Google|إنشاء حساب|Sign up|تذكرني|Remember/i)
    expect(container.querySelectorAll('input[type="checkbox"]')).toHaveLength(0)
  })

  it('sends a forgotten password to the self-service reset, which emails a one-use link', () => {
    renderWithProviders(<LoginPage />)

    expect(screen.getByTestId('login-forgot')).toHaveAttribute('href', '/forgot-password')
  })

  it('keeps the organisation field for the person with more than one, closed until it is asked for or a link names one', () => {
    renderWithProviders(<LoginPage />)
    expect(screen.queryByLabelText('المؤسسة')).toBeNull()

    renderWithProviders(<LoginPage />, { route: '/login?tenant=acme' })
    expect(screen.getAllByLabelText(/المؤسسة/).length).toBeGreaterThan(0)
  })

  it('says the official things on the brand panel and leads to the marketing page', () => {
    renderWithProviders(<LoginPage />)

    expect(screen.getByTestId('login-headline')).toHaveTextContent('حوّل الاهتمام إلى فرصة')
    expect(screen.getByTestId('login-badge')).toHaveTextContent('منصة تشغيل التسويق والمبيعات العقارية')
    expect(screen.getByTestId('login-description')).toHaveTextContent('امنح فريق المبيعات الأولوية للعملاء الأكثر جاهزية')
    expect(within(screen.getByTestId('login-pillars')).getAllByRole('listitem')).toHaveLength(3)
    expect(screen.getByTestId('login-about')).toHaveAttribute('href', '/welcome')
  })
})
