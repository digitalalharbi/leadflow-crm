import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ChevronDown, FileText, Mail, RotateCcw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, CardHeader, EmptyState, Skeleton } from '@/components/ui'
import { StatusPill, type StatusKey } from '@/components/visual'
import { retryDelivery, useEmailDeliveries, type DeliveryStatus } from '@/features/emailDeliveries'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

const PILL: Record<DeliveryStatus, StatusKey> = { queued: 'pending', sending: 'pending', sent: 'pending', delivered: 'completed', bounced: 'risk', logged: 'attention', failed: 'risk' }

/**
 * The product's own email — reports sent to people — and how each went: which provider is in force
 * and whether it can really send, the counts, every delivery with its attempts and what the provider
 * said, and a way to try a failed one again. A message that was only written to the log says so; it
 * is never called sent.
 */
export function EmailDeliveryPanel() {
  const { t, i18n } = useTranslation()
  const { data } = useEmailDeliveries()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [open, setOpen] = useState<string | null>(null)

  const retry = useMutation({
    mutationFn: retryDelivery,
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['email-deliveries'] }); toast(t('emailDelivery.retried'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (!data) return <Skeleton className="h-64" />

  return (
    <Card data-testid="email-delivery">
      <CardHeader title={t('emailDelivery.title')} description={t('emailDelivery.hint')} />
      <CardBody className="space-y-4">
        <div className="flex flex-wrap items-center gap-3" data-testid="mail-provider">
          <span className="grid size-10 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Mail className="size-5" /></span>
          <div className="min-w-0">
            <p className="text-[1rem] font-bold">{t(`emailDelivery.provider.${data.provider.key}`)}</p>
            <p className="t-caption" data-testid="mail-provider-state">
              {!data.provider.delivers ? t('emailDelivery.logOnly') : data.provider.configured ? t('emailDelivery.ready') : t('emailDelivery.missing', { what: data.provider.requires.join(', ') })}
            </p>
          </div>
          <ul className="ms-auto flex flex-wrap gap-1.5" data-testid="mail-counts">
            {(['queued', 'sent', 'delivered', 'bounced', 'logged', 'failed'] as const).map((s) => (
              <li key={s}><Badge tone={s === 'failed' && (data.counts.failed ?? 0) > 0 ? 'danger' : 'neutral'}>{t(`emailDelivery.status.${s}`)} · {data.counts[s] ?? 0}</Badge></li>
            ))}
          </ul>
        </div>

        <ul className="grid gap-1.5 sm:grid-cols-2" data-testid="mail-providers">
          {data.providers.map((p) => (
            <li key={p.key} data-testid={`mail-provider-${p.key}`} data-configured={p.configured} className={cn('flex items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-[0.875rem]', p.key === data.provider.key ? 'bg-[var(--brand-soft)]' : 'bg-[var(--color-surface-muted)]')}>
              <span className="font-semibold">{t(`emailDelivery.provider.${p.key}`)}</span>
              <span className="t-caption">{p.configured ? t('emailDelivery.configured') : p.requires.length ? t('emailDelivery.needs', { what: p.requires.join(', ') }) : ''}</span>
            </li>
          ))}
        </ul>

        {data.deliveries.length === 0 ? (
          <EmptyState icon={<FileText className="size-6" />} title={t('emailDelivery.emptyTitle')} body={t('emailDelivery.emptyBody')} />
        ) : (
          <ul className="divide-y divide-[var(--color-line)] rounded-xl border border-[var(--color-line)]" data-testid="deliveries">
            {data.deliveries.map((d) => (
              <li key={d.id} data-testid={`delivery-${d.id}`} data-status={d.status} className="px-3 py-2">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusPill status={PILL[d.status]} />
                  <span className="min-w-0 flex-1 truncate text-[0.9375rem]"><strong>{d.subject}</strong> <span className="t-caption" dir="ltr">→ {d.to}</span></span>
                  <span className="t-caption">{t(`emailDelivery.status.${d.status}`)} · {d.attempts}/{d.max_attempts}</span>
                  {d.created_at && <span className="t-caption">{formatLastSeen(d.created_at, i18n.language)}</span>}
                  {d.status === 'failed' && <Button size="sm" variant="outline" icon={<RotateCcw className="size-3.5" />} loading={retry.isPending} onClick={() => retry.mutate(d.id)} data-testid={`delivery-retry-${d.id}`}>{t('emailDelivery.retry')}</Button>}
                  <button type="button" onClick={() => setOpen(open === d.id ? null : d.id)} aria-expanded={open === d.id} aria-label={t('emailDelivery.log')} data-testid={`delivery-log-${d.id}`} className="pressable grid size-7 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]">
                    <ChevronDown className={cn('size-4 transition-transform', open === d.id && 'rotate-180')} aria-hidden />
                  </button>
                </div>
                {d.error && d.status === 'failed' && <p className="mt-1 flex items-start gap-1.5 text-[0.8125rem] text-[var(--color-danger)]"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />{d.error}</p>}
                {d.status === 'logged' && <p className="t-caption mt-1">{t('emailDelivery.loggedNote')}</p>}
                {open === d.id && (
                  <ol className="mt-2 space-y-1" data-testid={`delivery-attempts-${d.id}`}>
                    {d.log.map((a) => (
                      <li key={a.attempt} className="flex flex-wrap items-center gap-2 rounded-lg bg-[var(--color-surface-muted)] px-2.5 py-1 text-[0.8125rem]">
                        <span className="font-bold tabular-nums">#{a.attempt}</span>
                        <span className={a.ok ? 'text-[var(--brand)]' : 'text-[var(--color-danger)]'}>{a.ok ? t('emailDelivery.attemptOk') : a.retryable ? t('emailDelivery.attemptRetry') : t('emailDelivery.attemptFinal')}</span>
                        {a.error && <span className="t-caption min-w-0 flex-1 truncate" dir="ltr">{a.error}</span>}
                      </li>
                    ))}
                  </ol>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
