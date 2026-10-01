import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Globe } from 'lucide-react'
import { apiGet, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { applyLocale } from '@/i18n'
import { Button, Input } from '@/components/ui'
import type { Branding } from '@/types/api'
import { BrandField, BrandMark } from '@/components/brand'

/**
 * The login screen renders the *customer's* brand, not ours: logo, colours and
 * product name come from a public endpoint keyed by subdomain. This is the first
 * thing a prospect sees in a demo, so it has to be theirs from the first pixel.
 */
export function LoginPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
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

  useBrandingTheme(branding)

  useEffect(() => {
    if (status === 'authenticated') navigate('/', { replace: true })
  }, [status, navigate])

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
      navigate('/', { replace: true })
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

  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      {/* Form side */}
      <div className="flex flex-col px-6 py-8 sm:px-10">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            {logo ? (
              <img src={logo} alt="" className="size-9 rounded-[var(--radius-control)] object-contain" />
            ) : (
              <BrandMark size={30} animated />
            )}
            <span className="text-[0.9375rem] font-semibold">{systemName}</span>
          </div>

          <button
            type="button"
            onClick={() => applyLocale(i18n.language.startsWith('ar') ? 'en' : 'ar')}
            className="pressable flex items-center gap-1.5 rounded-[var(--radius-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]"
          >
            <Globe className="size-4" aria-hidden />
            {i18n.language.startsWith('ar') ? 'English' : 'العربية'}
          </button>
        </div>

        <div className="flex flex-1 items-center justify-center py-10">
          <div className="w-full max-w-sm">
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
                  className="rounded-[var(--radius-control)] border border-[color-mix(in_srgb,var(--color-status-lost)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] px-3 py-2.5 text-[0.8125rem] text-[var(--color-status-lost)]"
                >
                  {error}
                </p>
              )}

              <Button type="submit" size="lg" loading={submitting} className="w-full">
                {submitting ? t('auth.signingIn') : t('auth.signIn')}
              </Button>
            </form>

            {branding?.support_email && (
              <p className="mt-6 text-center text-[0.75rem] text-[var(--color-ink-subtle)]">
                {branding.support_email}
              </p>
            )}
          </div>
        </div>
      </div>

      {/* Brand side — hidden on small screens where it would only cost scroll. */}
      <div
        className="relative hidden overflow-hidden lg:block"
        style={{
          background: branding?.login_background_url
            ? `url(${branding.login_background_url}) center/cover`
            : [
                // Two off-axis highlights over the brand colour. A flat
                // two-stop gradient is the default every template ships with;
                // light that falls from somewhere reads as a made thing.
                'radial-gradient(110% 80% at 18% 8%, color-mix(in srgb, var(--brand) 55%, white) 0%, transparent 55%)',
                'radial-gradient(90% 70% at 85% 95%, color-mix(in srgb, var(--brand-secondary) 70%, black) 0%, transparent 60%)',
                'linear-gradient(145deg, var(--brand) 0%, var(--brand-secondary, #16181f) 100%)',
              ].join(', '),
        }}
      >
        {/*
          Not decoration: the field draws interests arriving, travelling, and a
          few of them resolving into opportunities. It is the product's claim,
          rendered — and it is suppressed when a tenant supplies its own
          background, because then the picture is theirs to choose.
        */}
        {!branding?.login_background_url && (
          <BrandField className="absolute inset-0 h-full w-full text-white" />
        )}

        <div className="absolute inset-0 bg-[var(--color-neutral-950)]/15" />

        <div className="relative flex h-full flex-col justify-end p-12 text-white">
          <h2 className="text-[2.125rem] leading-[1.15] font-semibold tracking-[-0.02em]">
            {branding?.company_name ?? systemName}
          </h2>
          {/* The tagline belongs to مهتم; a tenant's own name stands alone. */}
          <p className="mt-2.5 text-[1.0625rem] font-medium text-white/90">
            {branding?.company_name ? t('auth.welcomeSubtitle') : t('brand.tagline')}
          </p>
          {!branding?.company_name && (
            <p className="mt-3 max-w-sm text-[0.9375rem] leading-relaxed text-white/70">
              {t('brand.subtext')}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
