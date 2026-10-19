import { useQuery } from '@tanstack/react-query'
import { RefreshCcw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Skeleton } from '@/components/ui'
import { platformColor } from '@/components/charts'
import { apiGet } from '@/lib/api'
import { LADDER, type LadderState } from '@/features/adConnections'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

interface PlatformHealth {
  platform: string
  state: LadderState
  configured: boolean
  missing: string[]
  counts: Partial<Record<'sent' | 'pending' | 'awaiting_credentials' | 'failed' | 'unmatchable', number>>
  last_sent_at: string | null
  last_error: string | null
}

/**
 * Whether sales are reaching each ad platform — the loop that lets its
 * algorithm learn from customers rather than forms. One row per platform: the
 * ladder, the last thirty days as a bar (sent, waiting, lost), and what is
 * missing when it is not configured.
 */
export function ConversionHealthPanel() {
  const { t, i18n } = useTranslation()
  const { data } = useQuery({
    queryKey: ['conversion-health'],
    queryFn: () => apiGet<{ platforms: PlatformHealth[] }>('/conversions/health').then((r) => r.platforms),
  })

  return (
    <section data-testid="conversion-health">
      <h2 className="t-card-title mb-2 flex items-center gap-1.5 px-1">
        <RefreshCcw className="size-4 text-[var(--brand)]" aria-hidden />
        {t('conversionHealth.title')}
      </h2>
      <Card>
        <CardBody className="space-y-3">
          {!data ? <Skeleton className="h-40" /> : data.map((p) => {
            const c = p.counts
            const sent = c.sent ?? 0
            const waiting = (c.pending ?? 0) + (c.awaiting_credentials ?? 0)
            const lost = (c.failed ?? 0) + (c.unmatchable ?? 0)
            const all = Math.max(1, sent + waiting + lost)
            const reached = LADDER.indexOf(p.state)

            return (
              <div key={p.platform} className="grid items-center gap-2 sm:grid-cols-12" data-testid={`conversion-${p.platform}`} data-state={p.state}>
                <span className="flex items-center gap-2 text-[0.9375rem] font-medium sm:col-span-3">
                  <span className="size-2.5 rounded-full" style={{ background: platformColor(p.platform) }} aria-hidden />
                  {t(`platforms.${p.platform}`)}
                </span>
                <span className="flex gap-0.5 sm:col-span-2" title={t(`integrationState.${p.state}`)}>
                  {LADDER.slice(1).map((r, i) => <span key={r} className={cn('h-1.5 flex-1 rounded-full', i < reached ? 'bg-[var(--brand)]' : 'bg-[var(--color-chrome-fill)]')} />)}
                </span>
                <span className="flex h-2 overflow-hidden rounded-full bg-[var(--color-chrome-fill)] sm:col-span-4" aria-label={t('conversionHealth.bar', { sent, waiting, lost })}>
                  <span style={{ width: `${(sent / all) * 100}%`, background: 'var(--color-status-won)' }} />
                  <span style={{ width: `${(waiting / all) * 100}%`, background: 'var(--color-warning)', opacity: 0.6 }} />
                  <span style={{ width: `${(lost / all) * 100}%`, background: 'var(--color-danger)', opacity: 0.5 }} />
                </span>
                <span className="truncate text-[0.8125rem] text-[var(--color-ink-muted)] sm:col-span-3">
                  {!p.configured
                    ? t('conversionHealth.missing', { keys: p.missing.join('، ') })
                    : p.last_sent_at
                      ? t('conversionHealth.lastSent', { when: formatRelative(p.last_sent_at, i18n.language) })
                      : t('conversionHealth.none')}
                </span>
              </div>
            )
          })}
        </CardBody>
      </Card>
    </section>
  )
}
