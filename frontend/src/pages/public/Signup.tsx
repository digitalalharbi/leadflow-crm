import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MarketingNav } from '@/components/public/MarketingNav'
import { PublicLayout } from '@/components/public/PublicLayout'
import { Button, Input } from '@/components/ui'
import { usePublicMeta } from '@/hooks/usePublicMeta'
import { brandTitle } from '@/lib/brand'
import { ApiError, apiGet, apiPost } from '@/lib/api'

/*
 * /pricing and /signup: the sales funnel. The plans are the platform owner's own catalogue, read live, so a price
 * only ever appears once it has been set; a plan with none says "contact us". A trial is ASKED for here: the request
 * becomes a lead in Mohtam's own workspace and the owner opens the company's workspace. No public page or route
 * creates a company (RealUseReadinessTest pins that), and nobody is ever handed a password.
 */

interface PublicPlan {
  key: string
  name: string
  description: string | null
  price_monthly: string | null
  price_yearly: string | null
  currency: string
  included_seats: number | null
  features: string[]
  priced: boolean
}

interface PlansResponse { plans: PublicPlan[]; trial_days: number }

const COPY = {
  ar: {
    pricingTitle: 'الباقات والأسعار',
    pricingSub: 'ابدأ بتجربة مجانية، وانتقل إلى الباقة التي تناسب فريقك حين تكون جاهزاً.',
    signupTitle: 'اطلب تجربتك المجانية',
    signupSub: 'اترك بياناتك وسنفتح لشركتك مساحة تجريبية ونرسل لك رابطاً لتختار كلمة مرورك بنفسك.',
    month: 'شهرياً', year: 'سنوياً', seats: 'مقاعد مضمّنة', contactPrice: 'تواصل معنا للتسعير',
    choose: 'اطلب تجربة بهذه الباقة', trial: (d: number) => `تجربة مجانية ${d} يوماً`, demo: 'اطلب عرضاً حياً', loading: 'جارٍ تحميل الباقات…', loadFailed: 'تعذّر تحميل الباقات الآن. جرّب لاحقاً أو تواصل معنا.',
    optional: 'اختياري', company: 'اسم الشركة', name: 'اسمك', email: 'بريدك الإلكتروني', plan: 'الباقة', anyPlan: 'سأحدد لاحقاً',
    consent: 'أوافق على الشروط وسياسة الخصوصية وعلى التواصل معي بشأن طلبي.', submit: 'أرسل طلب التجربة', phone: 'جوالك',
    sentTitle: 'وصلنا طلبك', sent: 'سنتواصل معك لفتح مساحة شركتك وإرسال رابط اختيار كلمة المرور إلى بريدك.',
    closed: 'نموذج الطلب غير متاح حالياً. تواصل معنا وسنفتح لك مساحتك.', contact: 'تواصل معنا', haveAccount: 'لديك حساب؟ تسجيل الدخول',
    feature: { tracking_hub: 'مركز التتبع', advanced_forms: 'نماذج متقدمة', automations: 'الأتمتة', attribution: 'قياس المصدر', exports: 'التصدير', api: 'واجهة برمجية', advanced_reports: 'تقارير متقدمة', whatsapp: 'واتساب', custom_branding: 'هوية مخصصة', multiple_projects: 'مشاريع متعددة' } as Record<string, string>,
  },
  en: {
    pricingTitle: 'Plans and pricing',
    pricingSub: 'Start with a free trial, then move to the plan that fits your team when you are ready.',
    signupTitle: 'Request your free trial',
    signupSub: 'Leave your details and we will open a trial workspace for your company and email you a link to choose your own password.',
    month: 'per month', year: 'per year', seats: 'seats included', contactPrice: 'Contact us for pricing',
    choose: 'Request a trial with this plan', trial: (d: number) => `${d}-day free trial`, demo: 'Request a live demo', loading: 'Loading plans…', loadFailed: 'Plans could not be loaded right now. Try again later or contact us.',
    optional: 'Optional', company: 'Company name', name: 'Your name', email: 'Your email', plan: 'Plan', anyPlan: 'I will decide later',
    consent: 'I agree to the terms and privacy policy and to be contacted about my request.', submit: 'Send trial request', phone: 'Your mobile',
    sentTitle: 'We got your request', sent: 'We will contact you to open your company workspace and email you a link to choose your password.',
    closed: 'The request form is not available right now. Contact us and we will open your workspace.', contact: 'Contact us', haveAccount: 'Already have an account? Sign in',
    feature: { tracking_hub: 'Tracking hub', advanced_forms: 'Advanced forms', automations: 'Automations', attribution: 'Attribution', exports: 'Exports', api: 'API', advanced_reports: 'Advanced reports', whatsapp: 'WhatsApp', custom_branding: 'Custom branding', multiple_projects: 'Multiple projects' } as Record<string, string>,
  },
}

const useCopy = () => {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'

  return { lang, copy: COPY[lang] } as const
}

function usePlans() {
  const [data, setData] = useState<PlansResponse | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    apiGet<PlansResponse>('/public/plans').then((d) => setData(d)).catch(() => setFailed(true))
  }, [])

  return { data, failed }
}

const money = (value: string, currency: string, lang: string) => `${new Intl.NumberFormat(lang === 'ar' ? 'ar-SA' : 'en-US', { maximumFractionDigits: 0 }).format(Number(value))} ${currency}`

export function PricingPage() {
  const { lang, copy } = useCopy()
  const { data, failed } = usePlans()
  usePublicMeta(brandTitle(lang, copy.pricingTitle), copy.pricingSub, '/pricing', lang)

  return (
    <PublicLayout nav={<MarketingNav />} active="pricing">
      <div className="mx-auto w-full max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:py-12" data-testid="page-pricing">
        <header className="max-w-3xl">
          <h1 className="text-[2rem] font-bold leading-tight sm:text-[2.5rem]">{copy.pricingTitle}</h1>
          <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{copy.pricingSub}</p>
        </header>

        {!data && !failed && <p role="status" className="text-[var(--color-ink-muted)]">{copy.loading}</p>}
        {failed && <p role="alert" className="notice notice-info" data-testid="pricing-failed">{copy.loadFailed}</p>}

        {data && (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="pricing-plans">
            {data.plans.map((plan) => (
              <article key={plan.key} className="flex flex-col gap-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-5" data-testid={`plan-${plan.key}`}>
                <h2 className="text-[1.25rem] font-bold">{plan.name}</h2>
                {plan.description && <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{plan.description}</p>}
                <p className="text-[1.5rem] font-bold" data-testid={`plan-price-${plan.key}`}>
                  {plan.price_monthly ? <>{money(plan.price_monthly, plan.currency, lang)} <span className="text-[0.875rem] font-medium text-[var(--color-ink-muted)]">{copy.month}</span></> : plan.price_yearly ? <>{money(plan.price_yearly, plan.currency, lang)} <span className="text-[0.875rem] font-medium text-[var(--color-ink-muted)]">{copy.year}</span></> : <span className="text-[1.0625rem]">{copy.contactPrice}</span>}
                </p>
                {plan.included_seats ? <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{plan.included_seats} {copy.seats}</p> : null}
                <ul className="flex-1 space-y-1 text-[0.9375rem]">
                  {plan.features.map((f) => <li key={f}>✓ {copy.feature[f] ?? f}</li>)}
                </ul>
                <Link to={`/signup?plan=${encodeURIComponent(plan.key)}`} className="pressable inline-flex min-h-12 items-center justify-center rounded-full bg-[var(--brand)] px-6 font-semibold text-[var(--brand-contrast)] hover:bg-[var(--brand-hover)]" data-testid={`plan-choose-${plan.key}`}>{copy.choose}</Link>
              </article>
            ))}
          </div>
        )}

        <div className="flex flex-wrap items-center gap-3">
          {data && <span className="text-[0.9375rem] text-[var(--color-ink-muted)]">{copy.trial(data.trial_days)}</span>}
          <Link to="/contact" className="pressable inline-flex min-h-12 items-center rounded-full px-6 font-semibold ring-1 ring-inset ring-[var(--color-border-strong)] hover:bg-[var(--color-chrome-fill)]" data-testid="pricing-demo">{copy.demo}</Link>
        </div>
      </div>
    </PublicLayout>
  )
}

export function SignupPage() {
  const { lang, copy } = useCopy()
  const { data } = usePlans()
  const [params] = useSearchParams()
  usePublicMeta(brandTitle(lang, copy.signupTitle), copy.signupSub, '/signup', lang)
  const [open, setOpen] = useState<boolean | null>(null)
  const [form, setForm] = useState({ company: '', name: '', email: '', phone: '', plan: params.get('plan') ?? '', website: '' })
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  useEffect(() => {
    apiGet<{ form: boolean }>('/public/contact').then((d) => setOpen(Boolean(d?.form))).catch(() => setOpen(false))
  }, [])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setErrors({})

    try {
      const planName = data?.plans.find((p) => p.key === form.plan)?.name
      await apiPost('/public/contact', {
        kind: 'demo',
        name: form.name,
        email: form.email,
        phone: form.phone || undefined,
        company: form.company,
        message: planName ? `${copy.plan}: ${planName}` : undefined,
        website: form.website,
        consent,
      })
      setSent(true)
    } catch (caught) {
      setErrors(caught instanceof ApiError && caught.errors ? Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k, v[0] ?? ''])) : { form: copy.loadFailed })
    } finally {
      setBusy(false)
    }
  }

  return (
    <PublicLayout nav={<MarketingNav />} active="signup">
      <div className="mx-auto w-full max-w-xl space-y-5 px-4 py-8 sm:px-6 lg:py-12" data-testid="page-signup">
        <header>
          <h1 className="text-[2rem] font-bold leading-tight">{copy.signupTitle}</h1>
          <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{copy.signupSub}</p>
        </header>

        {open === false && (
          <p className="notice notice-info" data-testid="signup-closed">{copy.closed} <Link to="/contact" className="font-semibold underline underline-offset-4">{copy.contact}</Link></p>
        )}

        {sent ? (
          <div role="status" className="notice notice-info space-y-1" data-testid="signup-sent">
            <p className="font-bold">{copy.sentTitle}</p>
            <p>{copy.sent}</p>
          </div>
        ) : open !== false && (
          <form onSubmit={submit} noValidate className="space-y-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-5" data-testid="signup-form">
            <Input label={copy.company} value={form.company} error={errors.company} onChange={set('company')} autoComplete="organization" />
            <Input label={copy.name} value={form.name} error={errors.name} onChange={set('name')} autoComplete="name" />
            <Input dir="ltr" type="email" label={copy.email} value={form.email} error={errors.email} onChange={set('email')} autoComplete="email" placeholder="name@company.com" />
            <Input dir="ltr" type="tel" label={copy.phone} hint={copy.optional} value={form.phone} error={errors.phone} onChange={set('phone')} autoComplete="tel" />
            {data && data.plans.length > 0 && (
              <label className="block text-[0.9375rem] font-medium">
                {copy.plan}
                <select className="mt-1 h-12 w-full rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-3" value={form.plan} onChange={set('plan')} data-testid="signup-plan">
                  <option value="">{copy.anyPlan}</option>
                  {data.plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                </select>
              </label>
            )}
            {/* For bots only: no person sees or tabs into it. */}
            <div aria-hidden className="absolute -start-[9999px] h-0 w-0 overflow-hidden"><label>Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} /></label></div>
            <label className="flex min-h-11 cursor-pointer items-start gap-2 text-[0.9375rem]">
              <input type="checkbox" className="mt-1 size-[1.125rem] accent-[var(--brand)]" checked={consent} onChange={(e) => setConsent(e.target.checked)} data-testid="signup-consent" />
              <span>{copy.consent}</span>
            </label>
            {errors.consent && <p role="alert" className="text-[0.875rem] text-[var(--color-status-lost)]">{errors.consent}</p>}
            {errors.form && <p role="alert" className="text-[0.875rem] text-[var(--color-status-lost)]">{errors.form}</p>}
            <Button type="submit" className="h-12 w-full !rounded-lg font-semibold" loading={busy} disabled={!consent} data-testid="signup-submit">{copy.submit}</Button>
          </form>
        )}

        <p className="text-center text-[0.9375rem]"><Link to="/login" className="font-semibold underline underline-offset-4">{copy.haveAccount}</Link></p>
      </div>
    </PublicLayout>
  )
}
