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
              <img src={logo} alt="" className="size-9 rounded-lg object-contain" />
            ) : (
              <span
                className="grid size-9 place-items-center rounded-lg text-sm font-bold text-white"
                style={{ background: 'var(--brand)' }}
                aria-hidden
              >
                {systemName.slice(0, 1)}
              </span>
            )}
            <span className="text-[0.9375rem] font-semibold">{systemName}</span>
          </div>

          <button
            type="button"
            onClick={() => applyLocale(i18n.language.startsWith('ar') ? 'en' : 'ar')}
            className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[0.8125rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]"
          >
            <Globe className="size-4" aria-hidden />
            {i18n.language.startsWith('ar') ? 'English' : 'العربية'}
          </button>
        </div>

        <div className="flex flex-1 items-center justify-center py-10">
          <div className="w-full max-w-sm">
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.welcome')}</h1>
            <p className="mt-1.5 text-sm text-[var(--color-ink-muted)]">{t('auth.welcomeSubtitle')}</p>

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
                  className="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[0.8125rem] text-red-800"
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
            : 'linear-gradient(145deg, var(--brand) 0%, var(--brand-secondary, #0f172a) 100%)',
        }}
      >
        <div className="absolute inset-0 bg-slate-900/25" />
        <div className="relative flex h-full flex-col justify-end p-12 text-white">
          <h2 className="text-3xl font-semibold leading-snug">{branding?.company_name ?? systemName}</h2>
          <p className="mt-3 max-w-md text-[0.9375rem] leading-relaxed text-white/85">
            {t('auth.welcomeSubtitle')}
          </p>
        </div>
      </div>
    </div>
  )
}
