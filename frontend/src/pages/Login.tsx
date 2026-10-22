import { useEffect, useState, type FormEvent } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Globe, Megaphone, Repeat2, Timer } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { apiGet, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { applyLocale } from '@/i18n'
import { Button, Input } from '@/components/ui'
import type { Branding } from '@/types/api'
import { Logo } from '@/components/brand'
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
   * One name for the question the whole brand panel turns on: is the picture
   * the tenant's or ours? A photograph they uploaded keeps the dark, white-type
   * treatment; our own drawing follows the theme.
   */
  const ownBackground = Boolean(branding?.login_background_url)

  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      {/* Form side */}
      <div className="flex flex-col bg-[var(--color-surface)] px-6 py-8 sm:px-10">
        <div className="flex items-center justify-between">
          {/* The official logo unless this workspace has an identity of its own; see BrandLockup. */}
          {logo || branding?.system_name ? (
            <div className="flex items-center gap-2.5">
              {logo ? (
                <img src={logo} alt="" className="size-9 rounded-[var(--radius-control)] object-contain" />
              ) : (
                <Logo variant="auto" layout="mark" height={32} />
              )}
              <span className="text-[1.0625rem] font-semibold">{systemName}</span>
            </div>
          ) : (
            <Logo variant="auto" layout="horizontal" height={48} />
          )}

          <button
            type="button"
            onClick={() => applyLocale(i18n.language.startsWith('ar') ? 'en' : 'ar')}
            className="pressable flex items-center gap-1.5 rounded-[var(--radius-control)] px-2.5 py-1.5 text-[0.9375rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]"
          >
            <Globe className="size-4" aria-hidden />
            {i18n.language.startsWith('ar') ? 'English' : 'العربية'}
          </button>
        </div>

        <div className="flex flex-1 items-center justify-center py-10">
          <div className="w-full max-w-sm rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-canvas)] p-7 sm:p-8" data-testid="login-card">
            <h1 className="t-display">{t('auth.welcome')}</h1>
            <p className="t-small mt-1.5 text-[var(--color-ink-muted)]">{t('auth.welcomeSubtitle')}</p>

            <form onSubmit={handleSubmit} className="mt-7 space-y-4" noValidate>
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
              />

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
              />

              <Input
                name="tenant"
                dir="ltr"
                label={t('auth.organization')}
                hint={t('common.optional')}
                value={tenant}
                onChange={(e) => setTenant(e.target.value)}
                placeholder="company"
              />

              {error && (
                <p
                  role="alert"
                  className="rounded-[var(--radius-control)] border border-[color-mix(in_srgb,var(--color-status-lost)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] px-3 py-2.5 text-[0.9375rem] text-[var(--color-status-lost)]"
                >
                  {error}
                </p>
              )}

              <Button type="submit" size="lg" loading={submitting} className="w-full">
                {submitting ? t('auth.signingIn') : t('auth.signIn')}
              </Button>
            </form>

            {branding?.support_email && (
              <p className="mt-6 text-center text-[0.875rem] text-[var(--color-ink-subtle)]">
                {branding.support_email}
              </p>
            )}
          </div>
        </div>
      </div>

      {/* Brand side — hidden on small screens where it would only cost scroll. */}
      <div
        className={cn(
          'relative hidden overflow-hidden lg:block',
          /*
           * A tenant's own photograph keeps the dark treatment it was always given: light type over
           * an image needs the scrim. Everything else is the identity's pine — see `.login-panel`.
           */
          ownBackground ? undefined : 'login-panel',
        )}
        style={
          ownBackground
            ? { background: `url(${branding?.login_background_url}) center/cover` }
            : undefined
        }
      >
        {/*
          The official mark, large and quiet, in the second pine: a shape cut from the kit's own file
          and filled with a token, so it can never drift from the logo. No copper dot — one colour.
        */}
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
          <div>
            {!ownBackground && !branding?.company_name && <Logo variant="reverse" layout="horizontal" height={56} />}
          </div>

          <div>
            {!ownBackground && !branding?.company_name ? (
              <>
                <h2 className="text-[2.5rem] leading-[1.2] font-bold" data-testid="login-headline">{t('brand.tagline')}</h2>
                <p className="mt-3 max-w-sm text-[1.0625rem] leading-relaxed text-[var(--chrome-ink)]/80">{t('brand.subtext')}</p>
                <JourneyPath />
              </>
            ) : (
              <>
                <h2 className="text-[2.125rem] leading-[1.15] font-semibold ltr:tracking-[-0.02em]">
                  {branding?.company_name ?? systemName}
                </h2>
                {/* The tagline belongs to مهتم; a tenant's own name stands alone. */}
                <p className="mt-2.5 text-[1.0625rem] font-medium text-[var(--chrome-ink)]/90">{t('auth.welcomeSubtitle')}</p>
              </>
            )}

            {!ownBackground && (
              <ul className="mt-8 space-y-2.5" data-testid="login-pillars">
                {PILLARS.map(({ key, icon: Icon }) => (
                  <li key={key} className="flex items-center gap-3 text-[1rem] font-medium">
                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--chrome-2)] text-[var(--chrome-muted)]" aria-hidden>
                      <Icon className="size-4" />
                    </span>
                    {t(`brand.pillars.${key}`)}
                  </li>
                ))}
              </ul>
            )}
          </div>
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

/**
 * The path a lead takes, said in five words before anyone signs in: وصل ← تواصل ← تأهّل ← موعد ←
 * صفقة. The last is the one that matters, so it alone wears the copper. No numbers on it on
 * purpose: a figure on a login screen reads as somebody's data, and this one would belong to nobody.
 */
function JourneyPath() {
  const { t, i18n } = useTranslation()
  const arrow = i18n.dir() === 'rtl' ? '←' : '→'

  return (
    <ol className="mt-7 flex flex-wrap items-center gap-x-3 gap-y-1 text-[1.0625rem] font-semibold" data-testid="login-journey">
      {JOURNEY.map((key, index) => (
        <li key={key} className="flex items-center gap-3">
          <span className={index === JOURNEY.length - 1 ? 'text-[var(--accent-on-dark)]' : undefined} data-stage={key}>
            {t(`brand.journey.${key}`)}
          </span>
          {index < JOURNEY.length - 1 && (
            <span aria-hidden className="text-[var(--chrome-muted)]">
              {arrow}
            </span>
          )}
        </li>
      ))}
    </ol>
  )
}
