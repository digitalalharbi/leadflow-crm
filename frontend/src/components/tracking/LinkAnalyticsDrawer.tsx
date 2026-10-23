import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Drawer, ErrorState, Select, Skeleton } from '@/components/ui'
import { AreaTrend } from '@/components/visual'
import { fetchLinkAnalytics } from '@/features/hub'
import { formatMoneyCompact, formatNumber, formatRelative } from '@/lib/format'
import { FunnelBars } from './FunnelBars'
import { HealthBadge, HealthChecks } from './HealthChecks'

const COUNTS = ['clicks', 'unique_visitors', 'landing_views', 'form_starts', 'form_submissions', 'leads', 'qualified', 'interested', 'appointments', 'visits', 'reservations', 'won', 'whatsapp', 'calls'] as const
const RATES = ['view_rate', 'form_start_rate', 'completion_rate', 'lead_rate', 'qualification_rate', 'interested_rate', 'appointment_rate', 'reservation_rate', 'close_rate', 'revenue_per_lead', 'revenue_per_click'] as const

/**
 * One link, from the first click to the riyal: the counts, the rates between them, the funnel with where people were lost, whether
 * the tracking can be trusted, and what has just happened. Money appears only for those who may see money.
 */
export function LinkAnalyticsDrawer({ linkId, onClose }: { linkId: string | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState(30)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['link-analytics', linkId, days], queryFn: () => fetchLinkAnalytics(linkId!, days), enabled: linkId !== null })
  const a = data?.analytics

  return (
    <Drawer open={linkId !== null} onClose={onClose} title={data?.link.name ?? t('hub.analytics.title')} testId="link-analytics" size="lg">
      {isLoading ? <Skeleton className="h-64" /> : isError || !data || !a ? <ErrorState onRetry={() => void refetch()} /> : (
        <div className="space-y-6">
          <div className="flex items-center justify-between gap-2">
            <HealthBadge status={data.health.status} testId="analytics-health" />
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="analytics-range">
              {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
          </div>

          <section>
            <h3 className="mb-2 font-extrabold">{t('hub.funnel.title')}</h3>
            <FunnelBars rows={a.funnel} />
          </section>

          <section data-testid="analytics-counts">
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {COUNTS.map((k) => (
                <div key={k} className="rounded-xl bg-[var(--color-surface-muted)] px-3 py-2" data-testid={`count-${k}`}>
                  <dd className="text-[1.25rem] font-extrabold tabular-nums">{formatNumber(a.counts[k] ?? 0, i18n.language)}</dd>
                  <dt className="t-caption text-[var(--color-ink-subtle)]">{t(`hub.analytics.count.${k}`)}</dt>
                </div>
              ))}
              {a.counts.revenue !== null && a.counts.revenue !== undefined && (
                <div className="rounded-xl bg-[var(--color-surface-muted)] px-3 py-2" data-testid="count-revenue">
                  <dd className="text-[1.25rem] font-extrabold tabular-nums">{formatMoneyCompact(a.counts.revenue, 'SAR', i18n.language)}</dd>
                  <dt className="t-caption text-[var(--color-ink-subtle)]">{t('hub.analytics.count.revenue')}</dt>
                </div>
              )}
            </dl>
          </section>

          <section data-testid="analytics-rates">
            <h3 className="mb-2 font-extrabold">{t('hub.analytics.rates')}</h3>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
              {RATES.filter((k) => a.rates[k] !== undefined).map((k) => (
                <div key={k} className="flex items-baseline justify-between border-b border-[var(--color-border)] py-1 text-[0.9375rem]" data-testid={`rate-${k}`}>
                  <dt>{t(`hub.analytics.rate.${k}`)}</dt>
                  <dd className="font-bold tabular-nums">{a.rates[k] === null ? '—' : k.startsWith('revenue') ? formatMoneyCompact(a.rates[k], 'SAR', i18n.language) : `${a.rates[k]}%`}</dd>
                </div>
              ))}
            </dl>
          </section>

          <section>
            <h3 className="mb-2 font-extrabold">{t('hub.analytics.trend')}</h3>
            <AreaTrend points={a.trend.map((d) => ({ label: d.date, value: d.clicks }))} valueLabel={t('hub.analytics.count.clicks')} testId="analytics-trend" />
          </section>

          <section>
            <h3 className="mb-2 font-extrabold">{t('hub.health.title')}</h3>
            <HealthChecks checks={data.health.checks} />
          </section>

          <section>
            <h3 className="mb-2 font-extrabold">{t('hub.analytics.recent')}</h3>
            {data.recent.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.analytics.noEvents')}</p> : (
              <ul className="space-y-1" data-testid="analytics-recent">
                {data.recent.map((e, i) => (
                  <li key={i} className="flex justify-between gap-2 text-[0.9375rem]"><span dir="ltr" className="font-mono text-[0.875rem]">{e.event}</span><span className="text-[var(--color-ink-subtle)]">{formatRelative(e.at, i18n.language)}</span></li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </Drawer>
  )
}
