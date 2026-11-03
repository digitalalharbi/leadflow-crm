import { useEffect, useRef, useState, type FormEvent } from 'react'
import { homeFor, HOME } from '@/lib/routes'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRight, ChevronLeft, Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { apiGet, apiPost, ApiError, tokenStorage } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { applyLocale } from '@/i18n'
import { Button, Input, OtpInput } from '@/components/ui'
import type { Branding } from '@/types/api'
import { AuthCard, AuthShell, type AuthProvider } from '@/components/auth/AuthShell'

interface Workspace {
  id: string
  name: string
  role: string
  current: boolean
}

type Step = 'credentials' | 'code' | 'workspace'

/**
 * Authentication first: an email and a password say who this is, then the server says where they belong. One company:
 * straight in. More than one: they choose. A workspace's own login still wears its name, keyed by subdomain or ?tenant=.
 */
export function LoginPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const login = useAuth((s) => s.login)
  const loginGoogle = useAuth((s) => s.loginGoogle)
  const restore = useAuth((s) => s.restore)
  const status = useAuth((s) => s.status)
  const user = useAuth((s) => s.user)
  const translateError = useApiError()

  const [branding, setBranding] = useState<Branding | null>(null)
  const [providers, setProviders] = useState<AuthProvider[]>([])
  const [step, setStep] = useState<Step>('credentials')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [remember, setRemember] = useState(true)
  const [code, setCode] = useState('')
  // Back from Google: the one-time ticket that stands in for the password in this card's steps.
  const [googleTicket, setGoogleTicket] = useState<string | null>(null)
  const [workspaces, setWorkspaces] = useState<Workspace[]>([])
  const [workspaceNeedingCode, setWorkspaceNeedingCode] = useState<string | null>(null)
  const notice = (location.state as { notice?: string } | null)?.notice === 'mfaAfterReset' ? t('auth.mfaAfterReset') : null
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  // While this sign-in is still deciding where to go, being signed in is not yet a reason to leave.
  const deciding = useRef(false)

  // Kept for deep links and for a person with the same password in two unlinked companies; never something to type.
  const tenant = searchParams.get('tenant') ?? undefined

  /*
   * Where the person was going when the session asked them to sign in — an alert's link to a lead, say.
   * Only a path inside this app counts: anything else (a protocol-relative address, another origin, the
   * login screen itself) falls back to the home screen, so this can never be turned into a redirect elsewhere.
   */
  const wanted = (location.state as { from?: { pathname?: string; search?: string } } | null)?.from
  const landing =
    wanted?.pathname && /^\/(?!\/)/.test(wanted.pathname) && wanted.pathname !== '/login' && wanted.pathname !== '/' ? `${wanted.pathname}${wanted.search ?? ''}` : homeFor(user)

  useBrandingTheme(branding)

  useEffect(() => {
    if (status === 'authenticated' && !deciding.current) navigate(landing, { replace: true })
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

    // The server names the ways in that really work; none is drawn on hope.
    apiGet<{ providers: AuthProvider[] }>('/auth/providers')
      .then((data) => setProviders(data?.providers ?? []))
      .catch(() => setProviders([]))
  }, [searchParams])

  // The server's answer from Google arrives in the address: read it once, then take it out of the address and the history.
  useEffect(() => {
    const ticket = new URLSearchParams(window.location.hash.slice(1)).get('google')
    const refused = searchParams.get('google_error')

    if (refused) setError(t(`auth.google.${refused}`, { defaultValue: t('auth.google.failed') }))
    if (!ticket && !refused) return

    window.history.replaceState(window.history.state, '', window.location.pathname)
    if (ticket) void finishSignIn(ticket)
    // Once, on arrival.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function rememberForGoogle() {
    try {
      sessionStorage.setItem('mohtam.google.remember', remember ? '1' : '0')
    } catch {
      /* Private browsing: the default stands. */
    }
  }

  /** The same steps as a password: a second factor if the account has one, then where they belong. */
  async function finishSignIn(ticket: string, enteredCode?: string) {
    setGoogleTicket(ticket)
    setSubmitting(true)
    setError(null)
    setFieldErrors({})
    deciding.current = true

    try {
      let keep = true
      try {
        keep = sessionStorage.getItem('mohtam.google.remember') !== '0'
      } catch {
        /* Private browsing: the default stands. */
      }

      const result = await loginGoogle({ ticket, code: enteredCode, remember: keep })

      if (result === 'mfa') {
        setStep('code')

        return
      }

      await afterSignIn()
    } catch (caught) {
      if (step !== 'code') setGoogleTicket(null)
      catchErrors(caught)
    } finally {
      setSubmitting(false)
    }
  }

  async function afterSignIn() {
    const memberships = await apiGet<{ workspaces: Workspace[] }>('/auth/workspaces').then((data) => data.workspaces).catch(() => [])

    if (memberships.length > 1) {
      setWorkspaces(memberships)
      setStep('workspace')

      return
    }

    navigate(landing, { replace: true })
  }

  function catchErrors(caught: unknown) {
    if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
      const next: Record<string, string> = {}
      for (const [field, messages] of Object.entries(caught.errors)) {
        if (messages[0]) next[field] = messages[0]
      }
      setFieldErrors(next)
      if (!next.email && !next.password && !next.code) setError(translateError(caught))
    } else {
      setError(translateError(caught))
    }
  }

  async function signIn(event: FormEvent) {
    event.preventDefault()

    if (googleTicket) {
      await finishSignIn(googleTicket, code.trim())

      return
    }

    setSubmitting(true)
    setError(null)
    setFieldErrors({})
    deciding.current = true

    try {
      const result = await login({ email, password, tenant, code: step === 'code' ? code.trim() : undefined, remember })

      // The password was right and the account has a second factor: ask for the code, keep what was typed.
      if (result === 'mfa') {
        setStep('code')

        return
      }

      await afterSignIn()
    } catch (caught) {
      catchErrors(caught)
    } finally {
      setSubmitting(false)
    }
  }

  /** The server checks the membership, and the other company's own second factor; the browser never says where it is. */
  async function enter(workspace: Workspace, withCode?: string) {
    if (workspace.current) {
      navigate(landing, { replace: true })

      return
    }

    setSubmitting(true)
    setError(null)
    setFieldErrors({})

    try {
      const result = await apiPost<{ token?: string; mfa_required?: boolean }>(`/auth/workspaces/${workspace.id}/switch`, withCode ? { code: withCode } : undefined)

      if (!result.token) {
        setWorkspaceNeedingCode(workspace.id)
        setCode('')

        return
      }

      tokenStorage.set(result.token)
      await restore()
      navigate(HOME, { replace: true })
    } catch (caught) {
      catchErrors(caught)
    } finally {
      setSubmitting(false)
    }
  }

  const errorBox = error && (
    <p role="alert" className="rounded-[var(--radius-control)] border border-[color-mix(in_srgb,var(--color-status-lost)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] px-3 py-2.5 text-[0.9375rem] text-[var(--color-status-lost)]">
      {error}
    </p>
  )

  return (
    <AuthShell branding={branding} intro>
      {step === 'credentials' && (
        <AuthCard title={t('auth.welcome')} subtitle={t('auth.welcomeSubtitle')} providers={providers} onProvider={rememberForGoogle} help testId="login-card">
          {notice && <p role="status" className="notice notice-info" data-testid="login-notice">{notice}</p>}

          <form onSubmit={signIn} className="space-y-3" noValidate>
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
              className="h-12 px-11 text-[1rem]"
              startSlot={<Mail className="size-4" aria-hidden />}
            />

            <Input
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="current-password"
              required
              dir="ltr"
              label={t('auth.password')}
              value={password}
              error={fieldErrors.password}
              onChange={(e) => setPassword(e.target.value)}
              className="h-12 px-11 text-[1rem]"
              startSlot={<Lock className="size-4" aria-hidden />}
              endSlot={
                <button
                  type="button"
                  onClick={() => setShowPassword((value) => !value)}
                  aria-label={showPassword ? t('auth.hide') : t('auth.show')}
                  aria-pressed={showPassword}
                  className="pressable grid size-11 place-items-center text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                  data-testid="login-show-password"
                >
                  {showPassword ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
                </button>
              }
            />

            <div className="flex flex-wrap items-center justify-between gap-x-3">
              <label className="flex min-h-11 cursor-pointer items-center gap-2 text-[0.9375rem] font-medium">
                <input type="checkbox" className="size-[1.125rem] accent-[var(--brand)]" checked={remember} onChange={(e) => setRemember(e.target.checked)} data-testid="login-remember" />
                {t('auth.remember')}
              </label>
              {/* Self-service: a one-hour link by email, never a password. */}
              <Link to="/forgot-password" className="inline-flex min-h-11 items-center text-[0.9375rem] font-semibold text-[var(--brand-text)] underline-offset-4 hover:underline" data-testid="login-forgot">
                {t('auth.forgot')}
              </Link>
            </div>

            {errorBox}

            <Button type="submit" size="lg" loading={submitting} className="h-12 w-full !rounded-lg text-[1rem] font-semibold">
              {submitting ? t('auth.signingIn') : t('auth.signIn')}
              {!submitting && <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />}
            </Button>
          </form>

          {branding?.support_email && <p className="text-center text-[0.875rem] text-[var(--color-ink-subtle)]">{branding.support_email}</p>}
        </AuthCard>
      )}

      {step === 'code' && (
        <AuthCard title={t('auth.mfaTitle')} subtitle={t('auth.mfaCodeHint')} testId="login-card">
          <form onSubmit={signIn} className="space-y-3" noValidate>
            <OtpInput
              name="one-time-code"
              inputMode="text"
              autoFocus
              label={t('auth.mfaCode')}
              value={code}
              error={fieldErrors.code}
              onChange={(e) => setCode(e.target.value)}
              className="h-12"
              data-testid="login-mfa-code"
            />
            {errorBox}
            <Button type="submit" size="lg" loading={submitting} className="h-12 w-full !rounded-lg text-[1rem] font-semibold" data-testid="login-mfa-submit">{t('auth.verify')}</Button>
            <Button type="button" variant="ghost" className="min-h-11 w-full" onClick={() => { setStep('credentials'); setCode(''); setGoogleTicket(null); setError(null); setFieldErrors({}) }} data-testid="login-mfa-back">
              <ChevronLeft className="size-4 rtl:rotate-180" aria-hidden />
              {t('common.back')}
            </Button>
          </form>
        </AuthCard>
      )}

      {step === 'workspace' && (
        <AuthCard title={t('auth.chooseWorkspace')} subtitle={t('auth.chooseWorkspaceHint')} testId="login-workspaces">
          <ul className="space-y-2">
            {workspaces.map((workspace) => (
              <li key={workspace.id}>
                <button
                  type="button"
                  disabled={submitting}
                  onClick={() => void enter(workspace)}
                  className="pressable flex min-h-14 w-full items-center justify-between gap-2 rounded-[var(--radius-control)] border border-[var(--color-border)] px-3 py-2 text-start hover:bg-[var(--color-surface-muted)]"
                  data-testid={`login-workspace-${workspace.id}`}
                >
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{workspace.name}</span>
                    <span className="block text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`roles.${workspace.role}`, { defaultValue: workspace.role })}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
          {workspaceNeedingCode && (
            <form
              className="space-y-3"
              data-testid="login-workspace-code"
              onSubmit={(event) => {
                event.preventDefault()
                const target = workspaces.find((workspace) => workspace.id === workspaceNeedingCode)
                if (target) void enter(target, code.trim())
              }}
            >
              <OtpInput autoFocus label={t('auth.mfaCode')} value={code} error={fieldErrors.code} onChange={(e) => setCode(e.target.value)} />
              <Button type="submit" className="h-11 w-full" loading={submitting}>{t('auth.verify')}</Button>
            </form>
          )}
          {errorBox}
        </AuthCard>
      )}
    </AuthShell>
  )
}
