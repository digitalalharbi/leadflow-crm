import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Webhook } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Skeleton } from '@/components/ui'
import {
  WEBHOOK_EVENTS, createWebhook, deleteWebhook, fetchWebhookDeliveries, fetchWebhooks, replayDelivery, rotateWebhook, testWebhook, updateWebhook,
  type WebhookEvent, type WebhookSubscriptionRow,
} from '@/features/webhooks'
import { formatDateTime } from '@/lib/format'

const HEALTH_TONE = { healthy: 'success', error: 'danger', paused: 'warning', disabled: 'neutral', awaiting: 'neutral' } as const

/**
 * Outbound webhooks: the company's own systems told when a lead arrives, a visit is booked, a unit is reserved or a sale
 * closes. Each request is signed with the subscription's secret, shown once here. A failing endpoint is retried, and
 * paused after repeated failures; turning it back on resumes it.
 */
export function WebhooksCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [name, setName] = useState('')
  const [url, setUrl] = useState('')
  const [events, setEvents] = useState<WebhookEvent[]>(['lead.created'])
  const [secret, setSecret] = useState<string | null>(null)
  const [open, setOpen] = useState<string | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['webhooks'], queryFn: fetchWebhooks })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['webhooks'] })
  const onError = (caught: unknown) => toast(translateError(caught), 'error')

  const create = useMutation({
    mutationFn: () => createWebhook({ name: name.trim(), url: url.trim(), events }),
    onSuccess: (result) => {
      setSecret(result.secret)
      setName('')
      setUrl('')
      refresh()
    },
    onError,
  })
  const toggle = useMutation({ mutationFn: (row: WebhookSubscriptionRow) => updateWebhook(row.id, { is_active: !row.is_active || row.paused }), onSuccess: refresh, onError })
  const remove = useMutation({ mutationFn: deleteWebhook, onSuccess: refresh, onError })
  const rotate = useMutation({ mutationFn: rotateWebhook, onSuccess: (result) => setSecret(result.secret), onError })
  const test = useMutation({
    mutationFn: testWebhook,
    onSuccess: (delivery) => {
      toast(delivery.status === 'succeeded' ? t('webhooks.testOk') : t('webhooks.testFailed', { reason: delivery.error ?? '' }), delivery.status === 'succeeded' ? 'success' : 'error')
      refresh()
    },
    onError,
  })

  const pick = (event: WebhookEvent) => setEvents((current) => (current.includes(event) ? current.filter((e) => e !== event) : [...current, event]))

  if (isLoading) return <Skeleton className="h-40" />

  return (
    <Card data-testid="webhooks">
      <CardHeader title={t('webhooks.title')} description={t('webhooks.hint')} />
      <CardBody>
        {secret && (
          <div role="alert" className="notice notice-warning mb-4" data-testid="webhook-secret">
            <p className="font-semibold">{t('webhooks.secretOnce')}</p>
            <code className="mt-2 block break-all rounded-md bg-[var(--color-surface-sunken)] p-2 font-mono text-[0.875rem]" dir="ltr">{secret}</code>
            <Button size="sm" variant="ghost" className="mt-2" onClick={() => setSecret(null)}>{t('apiKeys.done')}</Button>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-[14rem_minmax(0,1fr)]">
          <Input label={t('webhooks.name')} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} data-testid="webhook-name" />
          <Input label={t('webhooks.url')} value={url} onChange={(e) => setUrl(e.target.value)} dir="ltr" placeholder="https://" data-testid="webhook-url" />
        </div>
        <fieldset className="mt-3">
          <legend className="t-label mb-1.5">{t('webhooks.events')}</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-2">
            {WEBHOOK_EVENTS.map((event) => (
              <label key={event} className="inline-flex min-h-11 items-center gap-2 text-[0.9375rem]">
                <input type="checkbox" checked={events.includes(event)} onChange={() => pick(event)} className="size-4 accent-[var(--brand)]" data-testid={`webhook-event-${event}`} />
                {t(`webhooks.event.${event.replace('.', '_')}`)}
              </label>
            ))}
          </div>
        </fieldset>
        <Button className="mt-3" icon={<Webhook className="size-4" />} loading={create.isPending} disabled={!name.trim() || !url.trim().startsWith('https://') || events.length === 0} onClick={() => create.mutate()} data-testid="webhook-create">
          {t('webhooks.create')}
        </Button>

        {data && data.length > 0 ? (
          <ul className="mt-5 divide-y divide-[var(--color-line)]" data-testid="webhook-list">
            {data.map((row) => (
              <li key={row.id} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-semibold">{row.name}</p>
                    <p className="t-caption break-all" dir="ltr">{row.url}</p>
                    {row.last_error && <p className="t-caption text-[var(--color-danger)]">{row.last_error}</p>}
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={HEALTH_TONE[row.health]} testId={`webhook-health-${row.id}`}>{t(`webhooks.health.${row.health}`)}</Badge>
                    <Button size="sm" variant="outline" loading={test.isPending && test.variables === row.id} onClick={() => test.mutate(row.id)}>{t('webhooks.test')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => toggle.mutate(row)}>{row.is_active && !row.paused ? t('webhooks.pause') : t('webhooks.resume')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => setOpen(open === row.id ? null : row.id)} aria-expanded={open === row.id}>{t('webhooks.log')}</Button>
                    <Button size="sm" variant="ghost" loading={rotate.isPending && rotate.variables === row.id} onClick={() => rotate.mutate(row.id)} data-testid={`webhook-rotate-${row.id}`}>{t('integrations.rotateSecret')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => remove.mutate(row.id)}>{t('webhooks.delete')}</Button>
                  </div>
                </div>
                {open === row.id && <DeliveryLog id={row.id} />}
              </li>
            ))}
          </ul>
        ) : (
          <p className="t-caption mt-4">{t('webhooks.empty')}</p>
        )}
      </CardBody>
    </Card>
  )
}

function DeliveryLog({ id }: { id: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['webhook-deliveries', id], queryFn: () => fetchWebhookDeliveries(id) })
  const replay = useMutation({ mutationFn: replayDelivery, onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['webhook-deliveries', id] }) })

  if (isLoading) return <Skeleton className="mt-2 h-16" />
  if (!data?.length) return <p className="t-caption mt-2">{t('webhooks.noDeliveries')}</p>

  return (
    <ul className="mt-2 space-y-1 text-[0.875rem]" data-testid={`webhook-log-${id}`}>
      {data.map((d) => (
        <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-[var(--color-surface-muted)] px-2 py-1.5">
          <span dir="ltr" className="font-mono">{d.event}</span>
          <span>{t(`webhooks.status.${d.status}`)}{d.response_status ? ` · ${d.response_status}` : ''} · {formatDateTime(d.created_at, i18n.language)}</span>
          {d.status === 'failed' && <Button size="sm" variant="ghost" onClick={() => replay.mutate(d.id)}>{t('webhooks.replay')}</Button>}
        </li>
      ))}
    </ul>
  )
}
