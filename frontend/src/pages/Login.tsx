import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Globe, Megaphone, Repeat2, Timer } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { apiGet, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { applyLocale } from '@/i18n'
import { Button, Input } from '@/components/ui'
import type { Branding } from '@/types/api'
import { Logo } from '@/components/brand'
import { Journey } from '@/components/public/Journey'
import markUrl from '@/assets/brand/logo/mono-black/mohtam-mark-mono-black.svg?url'
import { cn } from '@/lib/cn'

/**
 * The login screen renders the *customer's* brand, not ours: logo, colours and
 * product name come from a public endpoint keyed by subdomain. This is the first
 * thing a prospect sees in a demo, so it has to be theirs from the first pixel.
 */
export function LoginPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const login = useAuth((s) => s.login)
  const status = useAuth((s) => s.status)
  const translateError = useApiError()

  const [branding, setBranding] = useState<Branding | null>(null)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [tenant, setTenant] = useState(searchParams.get('tenant') ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  /*
   * Where the person was going when the session asked them to sign in — an alert's link to a lead, say.
   * Only a path inside this app counts: anything else (a protocol-relative address, another origin, the
   * login screen itself) falls back to the home screen, so this can never be turned into a redirect elsewhere.
   */
  const wanted = (location.state as { from?: { pathname?: string; search?: string } } | null)?.from
  const landing =
    wanted?.pathname && /^\/(?!\/)/.test(wanted.pathname) && wanted.pathname !== '/login' ? `${wanted.pathname}${wanted.search ?? ''}` : '/'

  useBrandingTheme(branding)

  useEffect(() => {
    if (status === 'authenticated') navigate(landing, { replace: true })
  }, [status, navigate, landing])

  // Resolve branding from ?tenant= or the subdomain the user arrived on.
  useEffect(() => {
    const slug = searchParams.get('tenant')

    apiGet<Branding | null>('/branding', slug ? { tenant: slug } : undefined)
      .then((data) => {
        if (!data) return
        setBranding(data)
        if (data.locale) applyLocale(data.locale)
      })
      .catch(() => {
        /* Branding is optional — a generic login still works. */
      })
  }, [searchParams])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    setFieldErrors({})

    try {
      await login({ email, password, tenant: tenant || undefined })
      navigate(landing, { replace: true })
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        const next: Record<string, string> = {}
        for (const [field, messages] of Object.entries(caught.errors)) {
          if (messages[0]) next[field] = messages[0]
        }
        setFieldErrors(next)
        if (!next.email && !next.password) setError(translateError(caught))
      } else {
        setError(translateError(caught))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const systemName = branding?.system_name ?? t('common.appName')
  const logo = branding?.login_logo_url ?? branding?.logo_url
  /*
   * One name for the question the whole brand panel turns on: is the picture the tenant's or ours? A
   * photograph they uploaded keeps the dark, white-type treatment; a workspace with its own name and logo
   * gets its own heading; everyone else gets مهتم — the logo, the headline, the journey.
   */
  const ownBackground = Boolean(branding?.login_background_url)
  const platformLook = !ownBackground && !branding?.company_name && !logo && !branding?.system_name
  const journey = JOURNEY.map((key) => t(`brand.journey.${key}`))
  // The organisation field is for the person with more than one; it opens by itself when a link names one.
  const [organizationOpen, setOrganizationOpen] = useState(Boolean(tenant))

  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      {/* The brand panel: first in the markup, so it sits at the start edge — the right, in Arabic. */}
      <div
        className={cn('relative hidden overflow-hidden lg:block', ownBackground ? undefined : 'login-panel')}
        style={ownBackground ? { background: `url(${branding?.login_background_url}) center/cover` } : undefined}
        data-testid="login-brand-panel"
      >
        {/* The official mark, large and quiet, in the second pine: cut from the kit's own file and filled with a token. */}
        {!ownBackground && (
          <span
            aria-hidden
            data-testid="login-watermark"
            className="pointer-events-none absolute -top-24 -end-28 size-[36rem] bg-[var(--chrome-2)]"
            style={{ WebkitMask: `url("${markUrl}") center / contain no-repeat`, mask: `url("${markUrl}") center / contain no-repeat` }}
          />
        )}
        {ownBackground && <div className="absolute inset-0 bg-[var(--color-neutral-950)]/15" />}

        <div className="relative flex h-full flex-col justify-between p-12 text-[var(--chrome-ink)]">
          <div>{platformLook && <Logo variant="reverse" layout="horizontal" height={56} />}</div>

          <div>
            {platformLook ? (
              <>
                <p className="inline-flex rounded-full bg-[var(--chrome-2)] px-3.5 py-1 text-[0.9375rem] font-semibold text-[var(--accent-on-dark)]" data-testid="login-badge">{t('brand.badge')}</p>
                <h2 className="mt-4 text-[2.625rem] leading-[1.2] font-bold" data-testid="login-headline">{t('brand.tagline')}</h2>
                <p className="mt-3 max-w-md text-[1.0625rem] leading-relaxed text-[var(--chrome-ink)]/80" data-testid="login-description">{t('brand.subtext')}</p>
                <Journey steps={journey} tone="dark" className="mt-7" testId="login-journey" />
              </>
            ) : (
              <>
                <h2 className="text-[2.125rem] leading-[1.15] font-semibold ltr:tracking-[-0.02em]">{branding?.company_name ?? systemName}</h2>
                {/* The tagline belongs to مهتم; a workspace's own name stands alone. */}
                <p className="mt-2.5 text-[1.0625rem] font-medium text-[var(--chrome-ink)]/90">{t('auth.welcomeSubtitle')}</p>
              </>
            )}

            {!ownBackground && (
              <ul className="mt-8 space-y-2.5" data-testid="login-pillars">
                {PILLARS.map(({ key, icon: Icon }) => (
                  <li key={key} className="flex items-center gap-3 text-[1rem] font-medium">
                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--chrome-2)] text-[var(--chrome-muted)]" aria-hidden><Icon className="size-4" /></span>
                    {t(`brand.pillars.${key}`)}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>

      {/* The form side: limestone, one white card, nothing else competing with it. */}
      <div className="flex flex-col bg-[var(--color-canvas)] px-5 py-4 pt-[max(1rem,env(safe-area-inset-top))] sm:px-10 lg:py-5">
        <div className="flex items-center justify-between gap-3">
          {/* The logo leads on a phone, where there is no brand panel; on a laptop the panel carries it. */}
          <div className={cn(platformLook && 'lg:invisible')}>
            {logo || branding?.system_name ? (
              <div className="flex items-center gap-2.5">
                {logo ? <img src={logo} alt="" className="size-9 rounded-[var(--radius-control)] object-contain" /> : <Logo variant="auto" layout="mark" height={32} />}
                <span className="text-[1.0625rem] font-semibold">{systemName}</span>
              </div>
            ) : (
              <Logo variant="auto" layout="horizontal" height={48} />
            )}
          </div>

          <button
            type="button"
            onClick={() => applyLocale(i18n.language.startsWith('ar') ? 'en' : 'ar')}
            className="pressable flex min-h-11 items-center gap-1.5 rounded-full px-3 text-[0.9375rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)] hover:text-[var(--color-ink)]"
          >
            <Globe className="size-4" aria-hidden />
            {i18n.language.startsWith('ar') ? 'English' : 'العربية'}
          </button>
        </div>

        <div className="flex flex-1 flex-col items-center justify-center gap-4 py-4">
          {/* On a phone the idea is one line above the card, not a panel beside it. */}
          {platformLook && (
            <div className="w-full max-w-md lg:hidden" data-testid="login-mobile-intro">
              <p className="text-[1.5rem] font-bold leading-tight">{t('brand.tagline')}</p>
              <p className="mt-1 text-[0.9375rem] text-[var(--color-ink-muted)]">{t('brand.badge')}</p>
            </div>
          )}

          <div className="w-full max-w-md rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-6 shadow-[0_1px_2px_rgb(20_32_29/0.04)] sm:p-7" data-testid="login-card">
            <h1 className="text-[1.75rem] leading-tight font-bold">{t('auth.welcome')}</h1>
            <p className="mt-1.5 text-[1rem] text-[var(--color-ink-muted)]">{t('auth.welcomeSubtitle')}</p>

            <form onSubmit={handleSubmit} className="mt-6 space-y-4" noValidate>
              <Input
                type="email"
                name="email"
                autoComplete="username"
                required
                dir="ltr"
                label={t('auth.email')}
                value={email}
                error={fieldErrors.email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="name@company.com"
                className="h-12 text-[1rem]"
              />

              <div>
                <Input
                  type="password"
                  name="password"
                  autoComplete="current-password"
                  required
                  dir="ltr"
                  label={t('auth.password')}
                  value={password}
                  error={fieldErrors.password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  className="h-12 text-[1rem]"
                />
                {/* There is no self-service reset: this leads to the page that says who resets it, not to a form that would not work. */}
                <Link to="/contact#password" className="mt-1 inline-flex min-h-11 items-center text-[0.9375rem] font-medium text-[var(--brand-text)] underline-offset-4 hover:underline" data-testid="login-forgot">
                  {t('auth.forgot')}
                </Link>
              </div>

              <div>
                <button
                  type="button"
                  onClick={() => setOrganizationOpen((value) => !value)}
                  aria-expanded={organizationOpen}
                  className="pressable flex min-h-11 items-center gap-1.5 text-[0.9375rem] font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                  data-testid="login-organization-toggle"
                >
                  <ChevronDown className={cn('size-4 transition-transform', organizationOpen && 'rotate-180')} aria-hidden />
                  {t('auth.moreOrganizations')}
                </button>
                {organizationOpen && (
                  <Input
                    name="tenant"
                    dir="ltr"
                    label={t('auth.organization')}
                    hint={t('common.optional')}
                    value={tenant}
                    onChange={(e) => setTenant(e.target.value)}
                    placeholder="company"
                    className="h-12 text-[1rem]"
                    containerClassName="mt-1"
                  />
                )}
              </div>

              {error && (
                <p
                  role="alert"
                  className="rounded-[var(--radius-control)] border border-[color-mix(in_srgb,var(--color-status-lost)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] px-3 py-2.5 text-[0.9375rem] text-[var(--color-status-lost)]"
                >
                  {error}
                </p>
              )}

              <Button type="submit" size="lg" loading={submitting} className="h-12 w-full text-[1rem]">
                {submitting ? t('auth.signingIn') : t('auth.signIn')}
              </Button>
            </form>

            {branding?.support_email && (
              <p className="mt-5 text-center text-[0.875rem] text-[var(--color-ink-subtle)]">{branding.support_email}</p>
            )}
          </div>

          {/* The journey, short, for the person on a phone. */}
          {platformLook && (
            <div className="w-full max-w-md rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3 lg:hidden" data-testid="login-mobile-journey">
              <Journey steps={journey} tone="light" testId="login-mobile-journey-steps" compact />
            </div>
          )}

          <nav aria-label="مهتم" className="flex flex-wrap justify-center gap-x-1 text-[0.9375rem]">
            <Link to="/welcome" className="inline-flex min-h-11 items-center rounded-lg px-2.5 font-medium text-[var(--brand-text)] hover:underline" data-testid="login-about">{t('auth.about')}</Link>
            <Link to="/privacy" className="inline-flex min-h-11 items-center rounded-lg px-2.5 text-[var(--color-ink-muted)] hover:underline">{t('brand.links.privacy')}</Link>
            <Link to="/terms" className="inline-flex min-h-11 items-center rounded-lg px-2.5 text-[var(--color-ink-muted)] hover:underline">{t('brand.links.terms')}</Link>
          </nav>
        </div>
      </div>
    </div>
  )
}

const PILLARS: Array<{ key: 'ads' | 'speed' | 'loop'; icon: LucideIcon }> = [
  { key: 'ads', icon: Megaphone },
  { key: 'speed', icon: Timer },
  { key: 'loop', icon: Repeat2 },
]

const JOURNEY = ['arrived', 'contacted', 'qualified', 'appointment', 'won'] as const
