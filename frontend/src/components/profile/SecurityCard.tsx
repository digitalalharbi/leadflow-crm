import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { can, useAuth } from '@/stores/auth'
import { Badge, Button, Card, CardBody, CardHeader, Input, OtpInput, Skeleton } from '@/components/ui'
import { formatDateTime } from '@/lib/format'

interface Security { mfa_enabled: boolean; recovery_codes_left: number; workspace_requires_mfa: boolean }
interface Session { id: number; ip: string | null; user_agent: string | null; created_at: string; last_used_at: string | null; current: boolean }

/**
 * The person's own security: an authenticator app as a second factor (never SMS), the places they are signed in, and —
 * for an administrator — the workspace rule that everyone must use one.
 */
export function SecurityCard() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const user = useAuth((s) => s.user)

  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState<string[] | null>(null)
  const [disablePassword, setDisablePassword] = useState('')
  const [disableCode, setDisableCode] = useState('')

  const security = useQuery({ queryKey: ['security'], queryFn: () => apiGet<Security>('/auth/security') })
  const sessions = useQuery({ queryKey: ['security-sessions'], queryFn: () => apiGet<Session[]>('/auth/security/sessions') })
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['security'] })
    void queryClient.invalidateQueries({ queryKey: ['security-sessions'] })
  }
  const onError = (caught: unknown) => toast(translateError(caught), 'error')

  const begin = useMutation({ mutationFn: () => apiPost<{ secret: string; otpauth_uri: string }>('/auth/security/mfa/setup'), onSuccess: setSetup, onError })
  const confirm = useMutation({
    mutationFn: () => apiPost<{ recovery_codes: string[] }>('/auth/security/mfa/confirm', { code: code.trim() }),
    onSuccess: (result) => {
      setRecovery(result.recovery_codes)
      setSetup(null)
      setCode('')
      refresh()
    },
    onError,
  })
  const disable = useMutation({
    mutationFn: () => apiPost('/auth/security/mfa/disable', { password: disablePassword, code: disableCode.trim() }),
    onSuccess: () => {
      setDisablePassword('')
      setDisableCode('')
      toast(t('security.disabled'), 'success')
      refresh()
    },
    onError,
  })
  const end = useMutation({ mutationFn: (id: number) => apiDelete(`/auth/security/sessions/${id}`), onSuccess: refresh, onError })
  const endOthers = useMutation({ mutationFn: () => apiPost('/auth/security/sessions/end-others'), onSuccess: refresh, onError })
  const [googlePassword, setGooglePassword] = useState('')
  const restore = useAuth((s) => s.restore)
  const unlinkGoogle = useMutation({
    mutationFn: () => apiDelete('/auth/security/google', { password: googlePassword }),
    onSuccess: async () => {
      setGooglePassword('')
      await restore()
    },
    onError,
  })
  const policy = useMutation({ mutationFn: (on: boolean) => apiPatch('/settings/security', { require_mfa: on }), onSuccess: refresh, onError })

  if (security.isLoading) return <Skeleton className="h-40" />
  const data = security.data

  return (
    <Card id="security" data-testid="security-card">
      <CardHeader
        title={t('security.title')}
        description={t('security.hint')}
        action={<Badge tone={data?.mfa_enabled ? 'success' : 'neutral'} testId="mfa-state">{data?.mfa_enabled ? t('security.on') : t('security.off')}</Badge>}
      />
      <CardBody className="space-y-5">
        {user?.google_linked && (
          <form
            className="flex flex-wrap items-end gap-2"
            data-testid="google-linked"
            onSubmit={(event) => {
              event.preventDefault()
              unlinkGoogle.mutate()
            }}
          >
            <Input containerClassName="min-w-0 flex-1" dir="ltr" type="password" autoComplete="current-password" label={t('auth.google.linked')} hint={t('auth.currentPassword')} value={googlePassword} onChange={(e) => setGooglePassword(e.target.value)} />
            <Button type="submit" variant="outline" disabled={!googlePassword} loading={unlinkGoogle.isPending} data-testid="google-unlink">{t('auth.google.unlink')}</Button>
          </form>
        )}
        {data?.workspace_requires_mfa && !data.mfa_enabled && (
          <p role="alert" className="notice notice-warning" data-testid="mfa-required-notice">{t('security.requiredNotice')}</p>
        )}

        {recovery && (
          <div className="rounded-[var(--radius-md)] bg-[var(--color-surface-sunken)] p-3" data-testid="mfa-recovery">
            <p className="mb-2 text-[0.9375rem] font-medium">{t('security.recoveryCodes')}</p>
            <ul dir="ltr" className="grid grid-cols-2 gap-1 font-mono text-[0.875rem]">{recovery.map((c) => <li key={c}>{c}</li>)}</ul>
            <Button size="sm" variant="ghost" className="mt-2" onClick={() => setRecovery(null)}>{t('apiKeys.done')}</Button>
          </div>
        )}

        {!data?.mfa_enabled && !setup && (
          <Button icon={<ShieldCheck className="size-4" />} loading={begin.isPending} onClick={() => begin.mutate()} data-testid="mfa-begin">{t('security.turnOn')}</Button>
        )}

        {setup && (
          <div className="space-y-3">
            <p className="text-[0.9375rem]">{t('security.scan')}</p>
            <code dir="ltr" className="block break-all rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.8125rem]" data-testid="mfa-secret">{setup.secret}</code>
            <a dir="ltr" href={setup.otpauth_uri} className="t-caption underline">{t('security.openApp')}</a>
            <div className="flex flex-wrap items-end gap-2">
              <OtpInput label={t('auth.mfaCode')} value={code} onChange={(e) => setCode(e.target.value)} data-testid="mfa-confirm-code" />
              <Button loading={confirm.isPending} disabled={code.trim().length < 6} onClick={() => confirm.mutate()} data-testid="mfa-confirm">{t('security.confirm')}</Button>
            </div>
          </div>
        )}

        {data?.mfa_enabled && !data.workspace_requires_mfa && (
          <details>
            <summary className="cursor-pointer text-[0.9375rem] text-[var(--color-ink-muted)]">{t('security.turnOff')}</summary>
            <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
              <Input type="password" label={t('auth.currentPassword')} dir="ltr" value={disablePassword} onChange={(e) => setDisablePassword(e.target.value)} />
              <Input label={t('auth.mfaCode')} dir="ltr" value={disableCode} onChange={(e) => setDisableCode(e.target.value)} />
              <Button variant="outline" loading={disable.isPending} disabled={!disablePassword || !disableCode.trim()} onClick={() => disable.mutate()}>{t('security.turnOff')}</Button>
            </div>
          </details>
        )}

        {user && can.manageIntegrations(user) && (
          <label className="flex min-h-11 items-center gap-2 text-[0.9375rem]">
            <input type="checkbox" role="switch" checked={Boolean(data?.workspace_requires_mfa)} disabled={policy.isPending} onChange={(e) => policy.mutate(e.target.checked)} className="size-4 accent-[var(--brand)]" data-testid="mfa-policy" />
            {t('security.requireForAll')}
          </label>
        )}

        <div>
          <h3 className="t-label mb-2">{t('security.sessions')}</h3>
          <ul className="divide-y divide-[var(--color-line)]" data-testid="session-list">
            {(sessions.data ?? []).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[0.9375rem]">
                <div className="min-w-0">
                  <p className="truncate">{s.user_agent || t('security.unknownDevice')}</p>
                  <p className="t-caption" dir="auto">{s.ip ?? ''} · {s.last_used_at ? formatDateTime(s.last_used_at, i18n.language) : formatDateTime(s.created_at, i18n.language)}</p>
                </div>
                {s.current ? <Badge tone="info">{t('security.thisDevice')}</Badge> : (
                  <Button size="sm" variant="ghost" loading={end.isPending && end.variables === s.id} onClick={() => end.mutate(s.id)}>{t('security.endSession')}</Button>
                )}
              </li>
            ))}
          </ul>
          {(sessions.data ?? []).some((s) => !s.current) && (
            <Button className="mt-2" size="sm" variant="secondary" loading={endOthers.isPending} onClick={() => endOthers.mutate()} data-testid="end-other-sessions">{t('security.endOthers')}</Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
