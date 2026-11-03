import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { HOME } from '@/lib/routes'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ApiError, apiGet, apiPost, tokenStorage } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { useAuth } from '@/stores/auth'
import { Button, Card, CardBody, Input, Skeleton } from '@/components/ui'
import { Logo } from '@/components/brand'

/*
 * The three doors into an account that do not start with a password the person already has: an invitation to a
 * company, a link to set a first password, and a link to reset a forgotten one. None of them ever sends a password by
 * email — each sends a short-lived link, and the person chooses their own.
 */

function Shell({ title, children, testId }: { title: string; children: ReactNode; testId: string }) {
  return (
    <div className="grid min-h-dvh place-items-center bg-[var(--color-canvas)] p-4" data-testid={testId}>
      <Card className="w-full max-w-md">
        <CardBody className="space-y-4">
          <Logo layout="horizontal" height={36} />
          <h1 className="text-[1.25rem] font-semibold" data-testid="page-title">{title}</h1>
          {children}
        </CardBody>
      </Card>
    </div>
  )
}

function fieldErrorsOf(caught: unknown): Record<string, string> {
  if (!(caught instanceof ApiError) || caught.status !== 422 || !caught.errors) return {}

  return Object.fromEntries(Object.entries(caught.errors).map(([field, messages]) => [field, messages[0] ?? '']))
}

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const translateError = useApiError()
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)

    try {
      await apiPost('/auth/password/forgot', { email })
      setSent(true)
    } catch (caught) {
      setError(translateError(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Shell title={t('account.forgotTitle')} testId="forgot-password">
      {sent ? (
        <p className="text-[0.9375rem] leading-relaxed" data-testid="forgot-sent">{t('account.forgotSent')}</p>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          <p className="t-caption">{t('account.forgotHint')}</p>
          <Input dir="ltr" type="email" autoComplete="username" label={t('auth.email')} value={email} onChange={(e) => setEmail(e.target.value)} required />
          {error && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]">{error}</p>}
          <Button type="submit" className="w-full" loading={submitting} data-testid="forgot-submit">{t('account.sendLink')}</Button>
        </form>
      )}
      <Link to="/login" className="t-caption underline">{t('account.backToLogin')}</Link>
    </Shell>
  )
}

/** Choosing a password from a link: a first one (setup) or a new one (reset). Signs the person in afterwards. */
export function SetPasswordPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const restore = useAuth((s) => s.restore)
  const translateError = useApiError()
  const { token = '' } = useParams()
  const [state, setState] = useState<'loading' | 'ready' | 'invalid'>('loading')
  const [info, setInfo] = useState<{ email: string; purpose: 'setup' | 'reset' } | null>(null)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    apiGet<{ email: string; purpose: 'setup' | 'reset' }>(`/auth/password/links/${token}`)
      .then((data) => { setInfo(data); setState('ready') })
      .catch(() => setState('invalid'))
  }, [token])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})

    try {
      const result = await apiPost<{ token: string } | { password_changed: true; mfa_required: true }>('/auth/password/reset', { token, password, password_confirmation: confirm })

      // The account has a second factor: the new password works, and signing in asks for the code.
      if ('mfa_required' in result) {
        navigate('/login', { replace: true, state: { notice: 'mfaAfterReset' } })

        return
      }

      tokenStorage.set(result.token)
      await restore()
      navigate(HOME, { replace: true })
    } catch (caught) {
      const next = fieldErrorsOf(caught)
      setErrors(Object.keys(next).length ? next : { form: translateError(caught) })
    } finally {
      setSubmitting(false)
    }
  }

  if (state === 'loading') return <Shell title={t('account.setTitle')} testId="set-password"><Skeleton className="h-32" /></Shell>

  if (state === 'invalid' || !info) {
    return (
      <Shell title={t('account.linkInvalidTitle')} testId="set-password">
        <p className="text-[0.9375rem]" data-testid="link-invalid">{t('account.linkInvalid')}</p>
        <Link to="/forgot-password" className="t-caption underline">{t('account.requestNew')}</Link>
      </Shell>
    )
  }

  return (
    <Shell title={t(info.purpose === 'setup' ? 'access.setTitle' : 'access.resetTitle')} testId="set-password">
      <form onSubmit={submit} className="space-y-4" noValidate>
        <p className="t-caption"><bdi dir="ltr">{info.email}</bdi></p>
        <Input dir="ltr" type="password" autoComplete="new-password" label={t('account.newPassword')} hint={t('account.passwordRule')} value={password} error={errors.password} onChange={(e) => setPassword(e.target.value)} data-testid="new-password" />
        <Input dir="ltr" type="password" autoComplete="new-password" label={t('account.confirmPassword')} value={confirm} onChange={(e) => setConfirm(e.target.value)} data-testid="confirm-password" />
        {(errors.form || errors.token) && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]">{errors.form ?? errors.token}</p>}
        <Button type="submit" className="w-full" loading={submitting} data-testid="set-password-submit">{t('account.savePassword')}</Button>
      </form>
    </Shell>
  )
}

interface InvitationInfo {
  company: string
  email: string
  name: string | null
  role: string
  has_account: boolean
}

/** Accepting an invitation: a new person chooses a password; someone already on Mohtam confirms theirs. */
export function InvitationPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const restore = useAuth((s) => s.restore)
  const translateError = useApiError()
  const { token = '' } = useParams()
  const [state, setState] = useState<'loading' | 'ready' | 'invalid'>('loading')
  const [info, setInfo] = useState<InvitationInfo | null>(null)
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    apiGet<InvitationInfo>(`/public/invitations/${token}`)
      .then((data) => { setInfo(data); setName(data.name ?? ''); setState('ready') })
      .catch(() => setState('invalid'))
  }, [token])

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})

    try {
      const result = await apiPost<{ token: string }>(`/public/invitations/${token}/accept`, info?.has_account ? { password } : { name, password, password_confirmation: confirm })
      tokenStorage.set(result.token)
      await restore()
      navigate(HOME, { replace: true })
    } catch (caught) {
      const next = fieldErrorsOf(caught)
      setErrors(Object.keys(next).length ? next : { form: translateError(caught) })
    } finally {
      setSubmitting(false)
    }
  }

  if (state === 'loading') return <Shell title={t('account.inviteTitle')} testId="invitation"><Skeleton className="h-32" /></Shell>

  if (state === 'invalid' || !info) {
    return (
      <Shell title={t('account.inviteInvalidTitle')} testId="invitation">
        <p className="text-[0.9375rem]" data-testid="invite-invalid">{t('account.inviteInvalid')}</p>
      </Shell>
    )
  }

  return (
    <Shell title={t('account.inviteTitle')} testId="invitation">
      <p className="text-[0.9375rem]" data-testid="invite-company">{t('account.inviteBody', { company: info.company, role: t(`roles.${info.role}`, { defaultValue: info.role }) })}</p>
      <p className="t-caption"><bdi dir="ltr">{info.email}</bdi></p>
      <form onSubmit={submit} className="space-y-4" noValidate>
        {info.has_account ? (
          <>
            <p className="t-caption">{t('account.inviteExisting')}</p>
            <Input dir="ltr" type="password" autoComplete="current-password" label={t('auth.password')} value={password} error={errors.password} onChange={(e) => setPassword(e.target.value)} data-testid="invite-password" />
          </>
        ) : (
          <>
            <Input label={t('account.yourName')} value={name} error={errors.name} onChange={(e) => setName(e.target.value)} data-testid="invite-name" />
            <Input dir="ltr" type="password" autoComplete="new-password" label={t('account.newPassword')} hint={t('account.passwordRule')} value={password} error={errors.password} onChange={(e) => setPassword(e.target.value)} data-testid="invite-password" />
            <Input dir="ltr" type="password" autoComplete="new-password" label={t('account.confirmPassword')} value={confirm} onChange={(e) => setConfirm(e.target.value)} data-testid="invite-confirm" />
          </>
        )}
        {(errors.form || errors.token || errors.seats) && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]">{errors.form ?? errors.token ?? errors.seats}</p>}
        <Button type="submit" className="w-full" loading={submitting} data-testid="invite-accept">{t('account.acceptInvite')}</Button>
      </form>
    </Shell>
  )
}

/** The address an email's link names. Opening it proves the inbox of this one account; it signs nobody in. */
export function VerifyEmailPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [state, setState] = useState<'ask' | 'done' | 'invalid'>(token ? 'ask' : 'invalid')

  // Nothing happens on opening the link: mail scanners open links too, and the password says this is the person's own account.
  async function confirm(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)

    try {
      await apiPost('/auth/email/verify', { token, password })
      setState('done')
    } catch {
      setState('invalid')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Shell title={t('account.verifyTitle')} testId="verify-email">
      {state === 'ask' && (
        <form onSubmit={confirm} className="space-y-3">
          <Input dir="ltr" type="password" autoComplete="current-password" label={t('auth.password')} value={password} onChange={(e) => setPassword(e.target.value)} data-testid="verify-password" />
          <Button type="submit" className="w-full" loading={submitting} disabled={!password} data-testid="verify-submit">{t('account.verifyConfirm')}</Button>
        </form>
      )}
      {state === 'done' && <p data-testid="verify-done">{t('account.verifyDone')}</p>}
      {state === 'invalid' && <p data-testid="verify-invalid">{t('account.verifyInvalid')}</p>}
      <Link to="/login" className="t-caption underline">{t('account.backToLogin')}</Link>
    </Shell>
  )
}

/** The plain names for the two link pages. A link's own token still decides; without one there is only one useful place to go. */
export function ResetPasswordRedirect() {
  const [params] = useSearchParams()
  const token = params.get('token')

  return <Navigate to={token ? `/set-password/${encodeURIComponent(token)}` : '/forgot-password'} replace />
}

export function AcceptInviteRedirect() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const token = params.get('token')

  if (token) return <Navigate to={`/invite/${encodeURIComponent(token)}`} replace />

  return (
    <Shell title={t('account.inviteTitleGeneric')} testId="accept-invite">
      <p>{t('account.inviteNeedsLink')}</p>
      <Link to="/login" className="t-caption underline">{t('account.backToLogin')}</Link>
    </Shell>
  )
}
