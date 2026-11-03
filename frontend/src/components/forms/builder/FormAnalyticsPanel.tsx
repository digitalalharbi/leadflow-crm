import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ErrorState, Select, Skeleton } from '@/components/ui'
import { fetchFormAnalytics } from '@/features/hub'
import { formatNumber } from '@/lib/format'

/** How the form is doing: who saw it, started it and finished it, where people stopped, what a matrix said — and nothing anybody typed. */
export function FormAnalyticsPanel({ formId }: { formId: string }) {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState(30)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['form-analytics', formId, days], queryFn: () => fetchFormAnalytics(formId, days) })
  const n = (v: number | null) => (v === null ? '—' : formatNumber(v, i18n.language))
  const pct = (v: number | null) => (v === null ? '—' : `${v}%`)
  const secs = (v: number | null) => (v === null ? '—' : v >= 60 ? t('hub.builder.analytics.minutes', { n: Math.round(v / 6) / 10 }) : t('hub.builder.analytics.seconds', { n: v }))

  if (isLoading) return <Skeleton className="h-64" />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  const tot = data.totals
  const cards: [string, string][] = [
    ['views', n(tot.views)], ['starts', n(tot.starts)], ['submits', n(tot.submits)], ['completion', pct(tot.completion_rate)], ['abandonment', pct(tot.abandonment_rate)], ['avgTime', secs(tot.avg_completion_seconds)],
  ]

  return (
    <div className="space-y-6" data-testid="form-analytics">
      <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="form-analytics-range">
        {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
      </Select>

      {data.page && (
        <section className="space-y-3" data-testid="page-analytics">
          <dl className="grid grid-cols-3 gap-2 sm:grid-cols-6">
            {(['unique_visitors', 'whatsapp_clicks', 'call_clicks', 'leads', 'sales', 'revenue_minor'] as const).map((k) => (
              <div key={k} className="rounded-xl bg-[var(--color-surface-muted)] p-2 text-center" data-testid={`page-metric-${k}`}>
                <dd className="tabular text-[1.125rem] font-bold">{k === 'revenue_minor' ? Math.round(data.page![k] / 100).toLocaleString(i18n.language) : data.page![k]}</dd>
                <dt className="t-caption">{t(`hub.page.analytics.${k}`)}</dt>
              </div>
            ))}
          </dl>
          <ol className="space-y-1" data-testid="page-funnel">
            {data.page.funnel.map((s) => {
              const top = data.page!.funnel[0]?.n || 1

              return (
                <li key={s.key} className="grid grid-cols-[6.5rem_minmax(0,1fr)_4.5rem] items-center gap-2 text-[0.875rem]" data-testid={`page-funnel-${s.key}`}>
                  <span>{t(`hub.page.analytics.funnel.${s.key}`)}</span>
                  <span className="h-2.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]"><span className="block h-full rounded-full bg-[var(--brand)]" data-chart-mark style={{ width: `${Math.min(100, (s.n / top) * 100)}%` }} /></span>
                  <span className="tabular text-end">{s.n}{s.rate !== null ? ` · ${s.rate}%` : ''}</span>
                </li>
              )
            })}
          </ol>
          {data.page.cost.spend_minor !== null && (
            <dl className="grid grid-cols-3 gap-2 sm:grid-cols-6" data-testid="page-cost">
              {(['cpl', 'cpql', 'per_appointment', 'per_reservation', 'cpa', 'roas'] as const).map((k) => (
                <div key={k} className="rounded-xl border border-[var(--color-line)] p-2 text-center">
                  <dd className="tabular font-bold">{data.page!.cost[k] === null ? '—' : k === 'roas' ? data.page!.cost[k] : Math.round(data.page!.cost[k]! / 100).toLocaleString(i18n.language)}</dd>
                  <dt className="t-caption">{t(`hub.page.analytics.cost.${k}`)}</dt>
                </div>
              ))}
            </dl>
          )}
        </section>
      )}
      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3" data-testid="form-analytics-totals">
        {cards.map(([k, v]) => (
          <div key={k} className="rounded-xl bg-[var(--color-surface-muted)] px-3 py-2" data-testid={`fa-${k}`}>
            <dd className="text-[1.25rem] font-extrabold tabular-nums">{v}</dd>
            <dt className="t-caption text-[var(--color-ink-subtle)]">{t(`hub.builder.analytics.${k}`)}</dt>
          </div>
        ))}
      </dl>

      <section>
        <h3 className="mb-2 font-extrabold">{t('hub.builder.analytics.steps')}</h3>
        <ol className="space-y-2" data-testid="fa-steps">
          {data.steps.map((s) => (
            <li key={s.step} data-testid={`fa-step-${s.step}`}>
              <div className="flex justify-between text-[0.9375rem]"><span className="font-semibold">{t('hub.builder.canvas.step', { n: s.step })}</span><span className="tabular-nums">{n(s.entered)} → {n(s.completed)}{s.drop_off !== null && s.drop_off > 0 && <span className="ms-2 text-[var(--color-warning)]">−{s.drop_off}%</span>}</span></div>
              <div className="mt-0.5 h-2.5 overflow-hidden rounded-full bg-[var(--color-surface-muted)]"><div className="h-full rounded-full bg-[var(--brand)]" style={{ width: `${s.entered > 0 ? Math.max(2, (s.completed / s.entered) * 100) : 0}%` }} /></div>
            </li>
          ))}
        </ol>
      </section>

      <section>
        <h3 className="mb-2 font-extrabold">{t('hub.builder.analytics.abandoned')}</h3>
        {data.abandoned_at.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.builder.analytics.noAbandon')}</p> : (
          <ul className="space-y-1" data-testid="fa-abandoned">
            {data.abandoned_at.map((a, i) => <li key={i} className="flex justify-between text-[0.9375rem]"><span>{a.field ?? t('hub.builder.analytics.unknownField')}{a.step ? <span className="ms-2 text-[var(--color-ink-subtle)]">{t('hub.builder.canvas.step', { n: a.step })}</span> : null}</span><span className="tabular-nums font-bold">{n(a.count)}</span></li>)}
          </ul>
        )}
      </section>

      {data.matrix.map((m) => (
        <section key={m.field} data-testid={`fa-matrix-${m.field}`}>
          <h3 className="mb-2 font-extrabold">{m.label}</h3>
          <div className="space-y-2">
            {m.rows.map((r) => {
              const total = Object.values(r.counts).reduce((a, b) => a + b, 0)

              return (
                <div key={r.row} data-testid={`fa-matrix-row-${m.field}-${r.row}`}>
                  <div className="flex justify-between text-[0.875rem]"><span className="font-semibold">{r.label}</span><span className="tabular-nums text-[var(--color-ink-subtle)]">{n(r.answered)}</span></div>
                  <div className="mt-0.5 flex h-3 overflow-hidden rounded-full bg-[var(--color-surface-muted)]" role="img" aria-label={m.columns.map((c) => `${c.label}: ${r.counts[c.value] ?? 0}`).join('، ')}>
                    {m.columns.map((c, i) => total > 0 && (r.counts[c.value] ?? 0) > 0 ? <div key={c.value} title={`${c.label}: ${r.counts[c.value]}`} style={{ width: `${(r.counts[c.value] / total) * 100}%`, opacity: 0.35 + (0.65 * (i + 1)) / m.columns.length }} className="h-full bg-[var(--brand)]" /> : null)}
                  </div>
                  <p className="mt-0.5 text-[0.75rem] text-[var(--color-ink-subtle)]">{m.columns.map((c) => `${c.label} ${r.counts[c.value] ?? 0}`).join(' · ')}</p>
                </div>
              )
            })}
          </div>
        </section>
      ))}

      <section>
        <h3 className="mb-2 font-extrabold">{t('hub.builder.analytics.versions')}</h3>
        <ul className="space-y-1" data-testid="fa-versions">
          {data.versions.map((v) => <li key={v.version} className="flex justify-between text-[0.9375rem]"><span>{t('hub.builder.version', { n: v.version })}{v.is_live && <span className="ms-2 rounded-full bg-[var(--brand-soft)] px-2 text-[0.75rem] font-bold text-[var(--brand-text)]">{t('hub.builder.live')}</span>}</span><span className="tabular-nums">{n(v.submits)}</span></li>)}
        </ul>
      </section>
    </div>
  )
}
