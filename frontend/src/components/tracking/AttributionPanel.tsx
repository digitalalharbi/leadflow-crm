import { useState } from 'react'
import { useQueries, useQuery } from '@tanstack/react-query'
import { CalendarCheck, Coins, Eye, Megaphone, Sparkles } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Select, Skeleton } from '@/components/ui'
import { Donut, RankBars, RingProgress } from '@/components/visual'
import { fetchAttribution, fetchAttributionCompare, fetchAttributionOptions, COMPARE_METRICS, DIMENSIONS, MODELS, type AttributionFilters, type AttributionModel, type AttributionRow, type CompareMetric, type Dimension } from '@/features/tracking'
import { fetchProjects } from '@/features/leads'
import { HeatGrid } from '@/components/visual'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const RAMP = ['var(--brand)', 'var(--color-info)', 'var(--color-warning)', 'var(--color-success)', 'var(--color-temp-hot)', 'var(--color-status-appointment)']

/** The five questions a marketing manager asks, each answered by one card. */
const ANSWERS: { key: string; dimension: Dimension; metric: keyof AttributionRow; icon: LucideIcon }[] = [
  { key: 'ad', dimension: 'ad', metric: 'leads', icon: Megaphone },
  { key: 'interested', dimension: 'campaign', metric: 'interested', icon: Sparkles },
  { key: 'visit', dimension: 'source', metric: 'visits', icon: Eye },
  { key: 'reservation', dimension: 'campaign', metric: 'reservations', icon: CalendarCheck },
  { key: 'revenue', dimension: 'channel', metric: 'revenue', icon: Coins },
]

/**
 * Who gets the credit, all the way to revenue.
 *
 * First touch and last touch lead (they are what people argue about); linear and position are one
 * press away. The ring is the share of leads nobody can explain — the first number to fix.
 */
export function AttributionPanel({ days }: { days: number }) {
  const { t, i18n } = useTranslation()
  const [model, setModel] = useState<AttributionModel>('last')
  const [dimension, setDimension] = useState<Dimension>('channel')
  const [filters, setFilters] = useState<AttributionFilters>({})
  const [metric, setMetric] = useState<CompareMetric>('revenue')

  const options = useQuery({ queryKey: ['attribution-options'], queryFn: fetchAttributionOptions, staleTime: 5 * 60_000 })
  const projects = useQuery({ queryKey: ['projects'], queryFn: fetchProjects, staleTime: 5 * 60_000 })

  const { data, isLoading } = useQuery({
    queryKey: ['attribution', model, dimension, days, filters],
    queryFn: () => fetchAttribution(model, dimension, days, filters),
    placeholderData: (previous) => previous,
  })

  const compare = useQuery({
    queryKey: ['attribution-compare', dimension, days, filters],
    queryFn: () => fetchAttributionCompare(dimension, days, filters),
    placeholderData: (previous) => previous,
  })

  const answers = useQueries({
    queries: ANSWERS.map((a) => ({
      queryKey: ['attribution-answer', a.key, model, days, filters],
      queryFn: () => fetchAttribution(model, a.dimension, days, filters),
      staleTime: 60_000,
    })),
  })

  const setFilter = (key: keyof AttributionFilters, value: string) => setFilters((f) => ({ ...f, [key]: value || undefined }))
  const active = Object.values(filters).filter(Boolean).length

  const label = (row: AttributionRow, dim: Dimension) => {
    if (row.key === 'unattributed') return t('tracking.unattributed')
    if (row.key === 'none') return t('tracking.noValue')
    if (dim === 'channel') return t(`tracking.channels.${row.key}`, { defaultValue: row.label ?? row.key })

    return row.label ?? row.key
  }

  const money = (v: number) => formatMoneyCompact(v, 'SAR', i18n.language)
  const num = (v: number) => formatNumber(Math.round(v * 10) / 10, i18n.language)
  const ring = data?.totals.unattributed_percent ?? null

  return (
    <div className="space-y-3" data-testid="attribution-panel">
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label={t('tracking.model')} className="inline-flex gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1">
          {MODELS.map((m) => (
            <button key={m} type="button" aria-pressed={model === m} data-testid={`model-${m}`} onClick={() => setModel(m)}
              className={cn('pressable rounded-full px-3 py-1 text-[0.9375rem] font-semibold', model === m ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]')}>
              {t(`tracking.models.${m}`)}
            </button>
          ))}
        </div>
        <Select value={dimension} onChange={(e) => setDimension(e.target.value as Dimension)} className="filter-pill min-w-36" aria-label={t('tracking.dimension')} data-testid="attribution-dimension">
          {DIMENSIONS.map((d) => <option key={d} value={d}>{t(`tracking.dimensions.${d}`)}</option>)}
        </Select>
      </div>

      {/* The slice the question is asked of: any of these, together. */}
      <div className="flex flex-wrap items-end gap-2" data-testid="attribution-filters">
        {([
          ['project_id', 'project', (projects.data ?? []).map((p) => ({ id: p.id, name: p.name }))],
          ['campaign_id', 'campaign', options.data?.campaigns ?? []],
          ['platform', 'platform', (options.data?.platforms ?? []).map((p) => ({ id: p, name: t(`platforms.${p}`, { defaultValue: p }) }))],
          ['ad_id', 'ad', options.data?.ads ?? []],
          ['creative_id', 'creative', options.data?.creatives ?? []],
          ['partner_id', 'partner', options.data?.partners ?? []],
          ['rep_id', 'rep', options.data?.reps ?? []],
        ] as const).map(([key, label, list]) => (
          <Select key={key} value={filters[key] ?? ''} onChange={(e) => setFilter(key, e.target.value)} className="filter-pill min-w-32" aria-label={t(`tracking.filter.${label}`)} data-testid={`attr-filter-${label}`}>
            <option value="">{t(`tracking.filter.${label}`)}</option>
            {list.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </Select>
        ))}
        {active > 0 && <button type="button" onClick={() => setFilters({})} data-testid="attr-filter-clear" className="pressable text-[0.875rem] font-semibold text-[var(--brand)] underline">{t('tracking.filter.clear', { count: active })}</button>}
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5" data-testid="attribution-answers">
        {ANSWERS.map((a, i) => {
          const q = answers[i]
          const rows = (q.data?.rows ?? []).filter((r) => r.key !== 'unattributed' && r.key !== 'none')
          const top = rows.toSorted((x, y) => Number(y[a.metric]) - Number(x[a.metric]))[0]
          const value = top ? Number(top[a.metric]) : 0
          const Icon = a.icon

          return (
            <Card key={a.key} data-testid={`answer-${a.key}`}>
              <CardBody className="space-y-1">
                <p className="flex items-center gap-1.5 text-[0.875rem] font-semibold text-[var(--color-ink-muted)]"><Icon className="size-4" aria-hidden />{t(`tracking.answers.${a.key}`)}</p>
                {q.isLoading ? <Skeleton className="h-10" /> : top && value > 0 ? (
                  <>
                    <p className="truncate text-[1.125rem] font-extrabold" data-testid={`answer-${a.key}-name`}>{label(top, a.dimension)}</p>
                    <p className="text-[0.9375rem] tabular-nums text-[var(--color-ink-subtle)]" dir="ltr">{a.metric === 'revenue' ? money(value) : num(value)}</p>
                  </>
                ) : <p className="text-[1rem] text-[var(--color-ink-subtle)]" data-testid={`answer-${a.key}-none`}>—</p>}
              </CardBody>
            </Card>
          )
        })}
      </div>

      {/* The four models side by side: what each makes of who brought the interested, the visits, the reservations, the revenue. */}
      {compare.data && compare.data.rows.length > 0 && (
        <Card data-testid="attribution-compare">
          <CardBody>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 className="t-card-title">{t('tracking.compare.title')}</h3>
                <p className="t-caption">{t('tracking.compare.hint')}</p>
              </div>
              <div role="group" aria-label={t('tracking.compare.metric')} className="inline-flex gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1">
                {COMPARE_METRICS.map((m) => (
                  <button key={m} type="button" aria-pressed={metric === m} data-testid={`compare-metric-${m}`} onClick={() => setMetric(m)}
                    className={cn('pressable rounded-full px-3 py-1 text-[0.875rem] font-semibold', metric === m ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]')}>
                    {t(`tracking.compare.m.${m}`)}
                  </button>
                ))}
              </div>
            </div>
            <HeatGrid
              testId="compare-grid"
              columns={MODELS.map((m) => t(`tracking.models.${m}`))}
              rows={compare.data.rows
                .filter((r) => r.key !== 'unattributed' && r.key !== 'none')
                .toSorted((x, y) => y.by_model.last[metric] - x.by_model.last[metric])
                .slice(0, 8)
                .map((r) => ({
                  key: r.key,
                  label: label({ key: r.key, label: r.label } as AttributionRow, compare.data!.dimension),
                  note: t('tracking.compare.swing', { percent: Math.round(metric === 'revenue' ? r.swing.revenue : r.swing.reservations) }),
                  cells: MODELS.map((m) => {
                    const total = compare.data!.totals[m][metric]

                    return total > 0 ? Math.round((r.by_model[m][metric] / total) * 100) : null
                  }),
                }))}
            />
          </CardBody>
        </Card>
      )}

      {isLoading && !data ? <Skeleton className="h-64" /> : data && (
        <div className="grid gap-3 lg:grid-cols-12">
          <Card className="lg:col-span-3" data-testid="attribution-unattributed">
            <CardBody className="flex flex-col items-center gap-2 text-center">
              <RingProgress value={ring ?? 0} size={112} thickness={12} tone={ring === null ? 'neutral' : ring <= 10 ? 'good' : ring <= 30 ? 'warn' : 'bad'} label={t('tracking.unattributedRing')} testId="unattributed-ring">
                <span className="text-[1.375rem] font-extrabold tabular-nums" data-testid="unattributed-percent">{ring === null ? '—' : `${ring}%`}</span>
              </RingProgress>
              <p className="text-[0.9375rem] font-semibold">{t('tracking.unattributedRing')}</p>
              <p className="text-[0.875rem] text-[var(--color-ink-subtle)]" data-testid="attribution-totals">
                {t('tracking.totals', { leads: data.totals.leads, won: num(data.totals.won) })}
              </p>
            </CardBody>
          </Card>

          <Card className="lg:col-span-4" data-testid="attribution-donut">
            <CardBody>
              <h3 className="t-card-title mb-3">{t('tracking.creditShare')}</h3>
              <Donut
                size={128}
                thickness={16}
                legend="side"
                segments={data.rows.filter((r) => r.leads > 0).map((r, i) => ({
                  key: r.key, label: label(r, data.dimension), value: Math.round(r.leads * 100) / 100,
                  color: r.key === 'unattributed' ? 'var(--color-ink-subtle)' : RAMP[i % RAMP.length],
                }))}
              />
            </CardBody>
          </Card>

          <Card className="lg:col-span-5" data-testid="attribution-ranking">
            <CardBody>
              <h3 className="t-card-title mb-3">{t('tracking.revenueRank')}</h3>
              <RankBars
                limit={6}
                items={data.rows.filter((r) => r.key !== 'unattributed').map((r) => ({
                  key: r.key, label: label(r, data.dimension), value: r.revenue > 0 ? r.revenue : r.won,
                  note: <span className="tabular-nums" dir="ltr">{r.revenue > 0 ? money(r.revenue) : `${num(r.won)} ${t('tracking.stat.won')}`} · {num(r.leads)} {t('tracking.stat.leads')}</span>,
                }))}
              />
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  )
}
