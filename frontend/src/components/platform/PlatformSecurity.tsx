import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, CardHeader, Input } from '@/components/ui'
import { apiGet, apiPost, tokenStorage } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

/**
 * The owner's own second factor: scan the secret into an authenticator app, confirm with a code, keep the recovery
 * codes (shown once). From then on the platform login asks for the code.
 */
export function PlatformSecurity() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState<string[] | null>(null)

  const { data } = useQuery({
    queryKey: ['platform-me'],
    queryFn: () => apiGet<{ mfa_enabled: boolean; mfa_enrolment_required: boolean }>('/platform/auth/me'),
  })

  const start = useMutation({
    mutationFn: () => apiPost<{ secret: string; otpauth_uri: string }>('/platform/auth/mfa/setup'),
    onSuccess: setSetup,
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const confirm = useMutation({
    mutationFn: () => apiPost<{ token: string; recovery_codes: string[] }>('/platform/auth/mfa/confirm', { code }),
    onSuccess: (result) => {
      tokenStorage.set(result.token)
      setRecovery(result.recovery_codes)
      setSetup(null)
      void queryClient.invalidateQueries({ queryKey: ['platform-me'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Card data-testid="platform-security">
      <CardHeader title={t('platformAdmin.mfaTitle')} description={data?.mfa_enabled ? t('platformAdmin.mfaOn') : t('platformAdmin.mfaOff')} />
      <CardBody className="space-y-3">
        {recovery && (
          <div className="rounded-[var(--radius-md)] bg-[var(--color-surface-sunken)] p-3" data-testid="mfa-recovery">
            <p className="mb-2 text-[0.9375rem] font-medium">{t('platformAdmin.recoveryCodes')}</p>
            <ul dir="ltr" className="grid grid-cols-2 gap-1 font-mono text-[0.875rem]">{recovery.map((c) => <li key={c}>{c}</li>)}</ul>
          </div>
        )}
        {!data?.mfa_enabled && !setup && <Button onClick={() => start.mutate()} loading={start.isPending} data-testid="mfa-start">{t('platformAdmin.mfaEnable')}</Button>}
        {setup && (
          <div className="space-y-2">
            <p className="text-[0.9375rem]">{t('platformAdmin.mfaScan')}</p>
            <code dir="ltr" className="block break-all rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.8125rem]" data-testid="mfa-secret">{setup.secret}</code>
            <a dir="ltr" href={setup.otpauth_uri} className="t-caption underline">{t('platformAdmin.mfaOpenApp')}</a>
            <div className="flex items-end gap-2">
              <Input dir="ltr" inputMode="numeric" label={t('platformAdmin.code')} value={code} onChange={(e) => setCode(e.target.value)} data-testid="mfa-code" />
              <Button onClick={() => confirm.mutate()} loading={confirm.isPending} data-testid="mfa-confirm">{t('platformAdmin.mfaConfirm')}</Button>
            </div>
          </div>
        )}
      </CardBody>
    </Card>
  )
}
