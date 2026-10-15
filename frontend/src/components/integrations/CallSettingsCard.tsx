import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Skeleton } from '@/components/ui'
import {
  disconnectCalls, fetchCallSettings, saveCallSettings, testCallSettings,
} from '@/features/calls'

/**
 * Where a workspace connects its own telephony.
 *
 * Without it a call is the rep dialling, which is a perfectly good way for a
 * call to happen and is what the product reports plainly. With it the provider
 * rings the rep and bridges the customer, and the status and duration that come
 * back are the provider's own — which is the only way this product ever holds a
 * measured call length.
 */
export function CallSettingsCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [sid, setSid] = useState('')
  const [token, setToken] = useState('')
  const [from, setFrom] = useState('')

  const { data, isLoading } = useQuery({ queryKey: ['call-settings'], queryFn: fetchCallSettings })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['call-settings'] })
    void queryClient.invalidateQueries({ queryKey: ['integration-health'] })
  }

  const save = useMutation({
    mutationFn: () =>
      saveCallSettings({
        // Only what was typed. An untouched field means "keep what is stored",
        // and a blank here would otherwise be sent as an overwrite.
        ...(sid.trim() ? { account_sid: sid.trim() } : {}),
        ...(token.trim() ? { auth_token: token.trim() } : {}),
        ...((from.trim() || data?.from_number) ? { from_number: from.trim() || data?.from_number || '' } : {}),
      }),
    onSuccess: () => {
      setSid('')
      setToken('')
      toast(t('calls.saved'), 'success')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const test = useMutation({
    mutationFn: testCallSettings,
    onSuccess: () => {
      toast(t('calls.testOk'), 'success')
      refresh()
    },
    onError: (caught) => {
      toast(translateError(caught), 'error')
      refresh()
    },
  })

  const remove = useMutation({
    mutationFn: disconnectCalls,
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const connected = data?.status === 'connected'

  return (
    <Card data-testid="call-settings">
      <CardHeader
        title={t('calls.title')}
        description={t('calls.hint')}
        action={
          connected ? (
            <Badge tone="success">{t('calls.connected')}</Badge>
          ) : (
            // In words, and not "disconnected": nothing is broken. The rep
            // dials, which is the arrangement until a provider is connected.
            <Badge tone="neutral">{t('calls.manual')}</Badge>
          )
        }
      />
      <CardBody>
        <div className="grid gap-3 sm:grid-cols-3">
          <Input
            label={t('calls.accountSid')}
            dir="ltr"
            placeholder={data?.account_sid_hint ?? 'AC…'}
            value={sid}
            onChange={(e) => setSid(e.target.value)}
            data-testid="call-account-sid"
          />
          <Input
            label={t('calls.authToken')}
            dir="ltr"
            type="password"
            placeholder={data?.has_token ? '••••••••' : ''}
            value={token}
            onChange={(e) => setToken(e.target.value)}
            data-testid="call-auth-token"
          />
          <Input
            label={t('calls.fromNumber')}
            dir="ltr"
            placeholder={data?.from_number ?? '+9665…'}
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            data-testid="call-from-number"
          />
        </div>

        {data?.status_callback && (
          <p className="t-caption mt-3">
            {t('calls.callbackHint')}{' '}
            <code className="break-all" dir="ltr">{data.status_callback}</code>
          </p>
        )}

        {data?.last_error && (
          <p className="notice mt-3 text-[var(--color-danger)]" role="alert">{data.last_error}</p>
        )}

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <Button
            icon={<Phone className="size-4" />}
            loading={save.isPending}
            disabled={!sid.trim() && !token.trim() && !from.trim()}
            onClick={() => save.mutate()}
            data-testid="save-call-settings"
          >
            {t('common.save')}
          </Button>
          <Button
            variant="outline"
            loading={test.isPending}
            disabled={!data?.configured}
            onClick={() => test.mutate()}
            data-testid="test-call-settings"
          >
            {t('calls.test')}
          </Button>
          {data?.configured && (
            <Button variant="ghost" loading={remove.isPending} onClick={() => remove.mutate()}>
              {t('calls.disconnect')}
            </Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
