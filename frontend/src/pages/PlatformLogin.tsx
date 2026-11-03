import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { Button, Input } from '@/components/ui'
import { AuthCard, AuthShell } from '@/components/auth/AuthShell'

/**
 * The platform owner's door — not a company's login with a different heading. It talks to its own endpoint, accepts
 * only platform accounts, and asks for the authenticator code when the account has a second factor.
 */
export function PlatformLoginPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const loginPlatform = useAuth((s) => s.loginPlatform)
  const status = useAuth((s) => s.status)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const translateError = useApiError()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [needsCode, setNeedsCode] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (status === 'authenticated' && isSuperAdmin) navigate('/platform', { replace: true })
  }, [status, isSuperAdmin, navigate])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)

    try {
      const result = await loginPlatform({ email, password, code: needsCode ? code : undefined })

      if (result === 'mfa') {
        setNeedsCode(true)
      } else {
        navigate('/platform', { replace: true })
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        setError(t(needsCode ? 'platformAdmin.badCode' : 'platformAdmin.badCredentials'))
      } else {
        setError(translateError(caught))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div data-console="platform">
      <AuthShell panel={false} footer={false}>
        <AuthCard title={t('platformAdmin.loginTitle')} subtitle={t('platformAdmin.loginHint')}>
          <form onSubmit={handleSubmit} className="space-y-4" noValidate data-testid="platform-login">
            <Input dir="ltr" type="email" autoComplete="username" label={t('auth.email')} value={email} onChange={(e) => setEmail(e.target.value)} required />
            <Input dir="ltr" type="password" autoComplete="current-password" label={t('auth.password')} value={password} onChange={(e) => setPassword(e.target.value)} required />
            {needsCode && (
              <Input dir="ltr" inputMode="numeric" autoComplete="one-time-code" label={t('platformAdmin.code')} hint={t('platformAdmin.codeHint')} value={code} onChange={(e) => setCode(e.target.value)} data-testid="platform-code" autoFocus />
            )}
            {error && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]" data-testid="platform-login-error">{error}</p>}
            <Button type="submit" className="w-full" loading={submitting}>{t('auth.signIn')}</Button>
          </form>
        </AuthCard>
      </AuthShell>
    </div>
  )
}
