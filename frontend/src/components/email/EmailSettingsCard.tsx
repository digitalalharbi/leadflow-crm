import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { STATE_TONE, useIntegrationStates } from '@/features/integrationState'
import {
  disconnectEmail, fetchEmailSettings, saveEmailSettings, testEmailSettings,
} from '@/features/email'
import { Badge, Button, Card, CardBody, CardHeader, Input, Select, Skeleton } from '@/components/ui'

/**
 * Where a workspace gives us its own mail server.
 *
 * Optional, and said so: without it email leaves through the platform's
 * transport, which works and which the customer's mail provider sees as us
 * rather than as the business. With it the message leaves from the business's
 * own address and domain, which is what keeps it out of the junk folder.
 *
 * The badge is the ladder's word and never "connected". Filling in the form is
 * "entered"; the server accepting a real test message is "ready"; a customer's
 * own mail client opening one is the first moment this is "live".
 */
export function EmailSettingsCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const state = useIntegrationStates().get('email')

  const { data, isLoading } = useQuery({ queryKey: ['email-settings'], queryFn: fetchEmailSettings })

  const [form, setForm] = useState({
    host: '', port: '587', username: '', password: '', encryption: 'tls', from_address: '', from_name: '',
  })

  useEffect(() => {
    if (!data) return
    setForm((previous) => ({
      ...previous,
      host: data.host ?? '',
      port: String(data.port ?? 587),
      username: data.username ?? '',
      encryption: data.encryption,
      from_address: data.from_address ?? '',
      from_name: data.from_name ?? '',
      // Never filled in: the server does not send it back, and an empty box
      // means "leave the stored one alone".
      password: '',
    }))
  }, [data])

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['email-settings'] })
    void queryClient.invalidateQueries({ queryKey: ['email-capability'] })
    void queryClient.invalidateQueries({ queryKey: ['integration-health'] })
  }

  const save = useMutation({
    mutationFn: () =>
      saveEmailSettings({ ...form, port: Number(form.port) || undefined, password: form.password || undefined }),
    onSuccess: () => {
      refresh()
      toast(t('common.saved'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const test = useMutation({
    mutationFn: testEmailSettings,
    onSuccess: (result) => {
      refresh()
      toast(t('email.testSent', { email: result.sent_to }), 'success')
    },
    onError: (e) => {
      refresh()
      toast(translateError(e), 'error')
    },
  })

  const disconnect = useMutation({
    mutationFn: disconnectEmail,
    onSuccess: () => {
      refresh()
      toast(t('email.disconnected'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  if (isLoading || !data) {
    return (
      <Card>
        <CardBody><Skeleton className="h-40" /></CardBody>
      </Card>
    )
  }

  const set = (key: keyof typeof form) => (value: string) => setForm((p) => ({ ...p, [key]: value }))

  return (
    <Card data-testid="email-settings">
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <Mail className="size-4 text-[var(--color-ink-muted)]" />
            {t('email.settingsTitle')}
          </span>
        }
        description={t('email.settingsSubtitle')}
        action={
          state && (
            <Badge tone={STATE_TONE[state]} testId="email-state">
              {t(`integrationState.${state}`)}
            </Badge>
          )
        }
      />
      <CardBody className="space-y-4">
        {data.mode === 'mock' && <p className="notice notice-warning">{t('email.mockNotice')}</p>}
        {data.mode === 'platform' && <p className="notice">{t('email.platformNotice')}</p>}
        {data.last_error && <p className="notice notice-danger">{data.last_error}</p>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            dir="ltr"
            label={t('email.host')}
            value={form.host}
            placeholder="smtp.example.com"
            className="font-mono" data-testid="email-host"
            onChange={(e) => set('host')(e.target.value)}
          />
          <div className="grid grid-cols-2 gap-3">
            <Input
              dir="ltr"
              label={t('email.port')}
              inputMode="numeric"
              value={form.port}
              className="font-mono" data-testid="email-port"
              onChange={(e) => set('port')(e.target.value.replace(/\D/g, ''))}
            />
            <Select
              label={t('email.encryption')}
              value={form.encryption}
              data-testid="email-encryption"
              onChange={(e) => set('encryption')(e.target.value)}
            >
              <option value="tls">STARTTLS</option>
              <option value="ssl">SSL/TLS</option>
            </Select>
          </div>
          <Input
            dir="ltr"
            label={t('email.username')}
            value={form.username}
            autoComplete="off"
            className="font-mono" data-testid="email-username"
            onChange={(e) => set('username')(e.target.value)}
          />
          <Input
            dir="ltr"
            type="password"
            label={t('email.password')}
            value={form.password}
            autoComplete="new-password"
            placeholder={data.has_password ? '••••••••' : ''}
            hint={data.has_password ? t('email.passwordStored') : undefined}
            className="font-mono" data-testid="email-password"
            onChange={(e) => set('password')(e.target.value)}
          />
          <Input
            dir="ltr"
            type="email"
            label={t('email.fromAddress')}
            value={form.from_address}
            data-testid="email-from-address"
            onChange={(e) => set('from_address')(e.target.value)}
          />
          <Input
            label={t('email.fromName')}
            value={form.from_name}
            data-testid="email-from-name"
            onChange={(e) => set('from_name')(e.target.value)}
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button onClick={() => save.mutate()} loading={save.isPending} data-testid="email-save">
            {t('common.save')}
          </Button>
          <Button
            variant="secondary"
            onClick={() => test.mutate()}
            loading={test.isPending}
            disabled={!data.configured}
            data-testid="email-test"
          >
            {t('email.test')}
          </Button>
          {data.configured && (
            <Button
              variant="ghost"
              onClick={() => disconnect.mutate()}
              loading={disconnect.isPending}
              data-testid="email-disconnect"
            >
              {t('email.disconnect')}
            </Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
