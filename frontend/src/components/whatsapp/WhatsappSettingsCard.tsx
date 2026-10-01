import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, MessageSquare, RefreshCw, Unplug } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  disconnectWhatsapp, fetchWhatsappSettings, rotateVerifyToken,
  saveWhatsappSettings, testWhatsappConnection,
} from '@/features/conversations'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Modal, Skeleton } from '@/components/ui'

const STATUS_TONE = {
  connected: 'success',
  not_connected: 'neutral',
  configuration_required: 'warning',
  error: 'danger',
} as const

/**
 * Connecting a workspace to its own WhatsApp Business account.
 *
 * Secrets travel one way. The three token fields show whether a value is set,
 * never what it is, and saving one blank leaves what is stored alone — so
 * changing a phone number does not silently wipe an access token.
 */
export function WhatsappSettingsCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [form, setForm] = useState({
    phone_number_id: '',
    business_account_id: '',
    access_token: '',
    verify_token: '',
    app_secret: '',
  })
  const [rotated, setRotated] = useState<string | null>(null)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [copied, setCopied] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['whatsapp-settings'],
    queryFn: fetchWhatsappSettings,
  })

  // Only the non-secret fields come back, so only those are prefilled.
  useEffect(() => {
    if (data) {
      setForm((prev) => ({
        ...prev,
        phone_number_id: data.phone_number_id ?? '',
        business_account_id: data.business_account_id ?? '',
      }))
    }
  }, [data])

  const save = useMutation({
    mutationFn: () =>
      saveWhatsappSettings({
        phone_number_id: form.phone_number_id || null,
        business_account_id: form.business_account_id || null,
        // Blank means "leave it as it is", which is why they are omitted.
        ...(form.access_token ? { access_token: form.access_token } : {}),
        ...(form.verify_token ? { verify_token: form.verify_token } : {}),
        ...(form.app_secret ? { app_secret: form.app_secret } : {}),
      }),
    onSuccess: () => {
      setForm((prev) => ({ ...prev, access_token: '', verify_token: '', app_secret: '' }))
      void queryClient.invalidateQueries({ queryKey: ['whatsapp-settings'] })
      toast(t('common.saved'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const test = useMutation({
    mutationFn: testWhatsappConnection,
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['whatsapp-settings'] })
      toast(result.detail, result.ok ? 'success' : 'error')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const rotate = useMutation({
    mutationFn: rotateVerifyToken,
    onSuccess: (result) => {
      setRotated(result.verify_token)
      void queryClient.invalidateQueries({ queryKey: ['whatsapp-settings'] })
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const disconnect = useMutation({
    mutationFn: disconnectWhatsapp,
    onSuccess: () => {
      setDisconnectOpen(false)
      void queryClient.invalidateQueries({ queryKey: ['whatsapp-settings'] })
      toast(t('whatsapp.disconnected'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const copyWebhook = async () => {
    if (!data) return
    try {
      await navigator.clipboard.writeText(data.webhook_url)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      toast(t('common.copyFailed'), 'error')
    }
  }

  if (isLoading || !data) {
    return (
      <Card>
        <CardBody>
          <Skeleton className="h-40" />
        </CardBody>
      </Card>
    )
  }

  return (
    <>
      <Card data-testid="whatsapp-settings">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <MessageSquare className="size-4 text-[var(--color-ink-muted)]" />
              {t('whatsapp.title')}
            </span>
          }
          description={t('whatsapp.subtitle')}
          action={
            <Badge tone={STATUS_TONE[data.status]} testId="whatsapp-status">
              {t(`integrations.${camel(data.status)}`)}
            </Badge>
          }
        />
        <CardBody className="space-y-4">
          {data.mode === 'mock' && (
            <p className="notice notice-warning">{t('whatsapp.mockNotice')}</p>
          )}

          {data.last_error && (
            <p className="notice notice-danger">{data.last_error}</p>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              label={t('whatsapp.phoneNumberId')}
              hint={t('whatsapp.phoneNumberIdHint')}
              value={form.phone_number_id}
              data-testid="wa-phone-number-id"
              onChange={(e) => setForm((p) => ({ ...p, phone_number_id: e.target.value.replace(/\D/g, '') }))}
            />
            <Input
              dir="ltr"
              label={t('whatsapp.businessAccountId')}
              value={form.business_account_id}
              data-testid="wa-business-account-id"
              onChange={(e) => setForm((p) => ({ ...p, business_account_id: e.target.value.replace(/\D/g, '') }))}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              type="password"
              autoComplete="off"
              label={t('whatsapp.accessToken')}
              hint={data.has_access_token ? t('whatsapp.secretStored') : t('whatsapp.secretMissing')}
              placeholder={data.has_access_token ? '••••••••••••' : ''}
              value={form.access_token}
              data-testid="wa-access-token"
              onChange={(e) => setForm((p) => ({ ...p, access_token: e.target.value }))}
            />
            <Input
              dir="ltr"
              type="password"
              autoComplete="off"
              label={t('whatsapp.appSecret')}
              hint={data.has_app_secret ? t('whatsapp.secretStored') : t('whatsapp.appSecretHint')}
              placeholder={data.has_app_secret ? '••••••••••••' : ''}
              value={form.app_secret}
              data-testid="wa-app-secret"
              onChange={(e) => setForm((p) => ({ ...p, app_secret: e.target.value }))}
            />
          </div>

          <div className="rounded-xl border border-[var(--color-line)] p-3">
            <p className="text-[0.8125rem] font-medium">{t('whatsapp.webhookTitle')}</p>
            <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-muted)]">{t('whatsapp.webhookHint')}</p>

            <div className="mt-2 flex items-center gap-2">
              <code dir="ltr" className="flex-1 truncate rounded-lg bg-[var(--color-surface-sunken)] px-2 py-1.5 text-xs">
                {data.webhook_url}
              </code>
              <Button variant="ghost" size="sm" onClick={copyWebhook} aria-label={t('common.copy')}>
                {copied ? <Check className="size-4 text-[var(--color-status-won)]" /> : <Copy className="size-4" />}
              </Button>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Input
                dir="ltr"
                type="password"
                autoComplete="off"
                containerClassName="flex-1"
                label={t('whatsapp.verifyToken')}
                hint={data.has_verify_token ? t('whatsapp.secretStored') : t('whatsapp.secretMissing')}
                placeholder={data.has_verify_token ? '••••••••••••' : ''}
                value={form.verify_token}
                data-testid="wa-verify-token"
                onChange={(e) => setForm((p) => ({ ...p, verify_token: e.target.value }))}
              />
              <Button
                variant="ghost"
                className="mt-6"
                onClick={() => rotate.mutate()}
                loading={rotate.isPending}
                data-testid="wa-rotate"
              >
                <RefreshCw className="size-4" />
                {t('whatsapp.rotate')}
              </Button>
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button onClick={() => save.mutate()} loading={save.isPending} data-testid="wa-save">
              {t('common.save')}
            </Button>
            <Button variant="secondary" onClick={() => test.mutate()} loading={test.isPending} data-testid="wa-test">
              {t('whatsapp.test')}
            </Button>
            {data.status !== 'not_connected' && (
              <Button variant="ghost" onClick={() => setDisconnectOpen(true)} data-testid="wa-disconnect">
                <Unplug className="size-4" />
                {t('whatsapp.disconnect')}
              </Button>
            )}
          </div>
        </CardBody>
      </Card>

      <Modal
        open={Boolean(rotated)}
        onClose={() => setRotated(null)}
        title={t('whatsapp.verifyToken')}
        description={t('whatsapp.rotateNotice')}
        footer={<Button onClick={() => setRotated(null)}>{t('common.close')}</Button>}
      >
        <code dir="ltr" className="block break-all rounded-lg bg-[var(--color-surface-sunken)] p-3 text-sm">
          {rotated}
        </code>
      </Modal>

      <Modal
        open={disconnectOpen}
        onClose={() => setDisconnectOpen(false)}
        title={t('whatsapp.disconnect')}
        description={t('whatsapp.disconnectWarning')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDisconnectOpen(false)}>{t('common.cancel')}</Button>
            <Button
              variant="danger"
              onClick={() => disconnect.mutate()}
              loading={disconnect.isPending}
              data-testid="wa-disconnect-confirm"
            >
              {t('whatsapp.disconnect')}
            </Button>
          </>
        }
      >
        <p className="text-sm text-[var(--color-ink-muted)]">{t('whatsapp.disconnectDetail')}</p>
      </Modal>
    </>
  )
}

function camel(status: string) {
  return status.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase())
}
