import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BadgeDollarSign, CalendarCheck, Coins, Eye, KeyRound, Percent, ShieldCheck, Sparkles, Target, TrendingUp, UserCheck, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { AreaTrend, ComparisonCard, Donut, HeatGrid, KpiCard, RankBars, RealEstateFunnel, RingProgress, StatusPill, type StatusKey } from '@/components/visual'
import { platformColor } from '@/components/charts'
import type { KpiKey, SharedReportData } from '@/features/reportShares'
import { formatCompact, formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import { findingText } from '@/lib/findings'

/**
 * The sections of a live report, one component each, every one a picture before it is a number:
 * a card, a ring, a funnel, a trend, a ranking. A table is only ever behind "details".
 *
 * Each takes the whole report and draws its own part of it, so the page is just the sections the
 * owner chose in the order they chose.
 */
export interface ReportCtx {
  data: SharedReportData
  money: (v: number | null | undefined) => string
  num: (v: number | null | undefined) => string
}

export function useReportCtx(data: SharedReportData): ReportCtx {
  const { i18n } = useTranslation()

  return {
    data,
    money: (v) => formatMoneyCompact(v ?? null, data.report.currency, i18n.language),
    num: (v) => formatNumber(v ?? 0, i18n.language),
  }
}

const KPI_ICON: Record<KpiKey, LucideIcon> = {
  leads: Users, qualified: ShieldCheck, interested: Sparkles, visits: Eye, reservations: KeyRound, won: UserCheck, conversion_rate: Percent,
  spend: Coins, cpl: BadgeDollarSign, cpa: Target, cac: Target, roas: TrendingUp, revenue: Coins,
}
const SECTION_KPIS: Record<string, KpiKey[]> = {
  kpi_volume: ['leads', 'qualified', 'interested', 'visits', 'reservations', 'won', 'conversion_rate'],
  kpi_cost: ['spend', 'cpl', 'cpa', 'cac', 'roas'],
  kpi_revenue: ['revenue'],
}
/** Lower is better for what the company pays. */
const LOWER_IS_BETTER: KpiKey[] = ['spend', 'cpl', 'cpa', 'cac']
const MONEY_KPIS: KpiKey[] = ['spend', 'cpl', 'cpa', 'cac', 'revenue']

/**
 * The headline figures of a section, each with a sparkline and — when the report compares — against
 * the last period. Cards only: the page lays consecutive KPI sections out as one grid.
 */
export function KpiCards({ ctx, section }: { ctx: ReportCtx; section: 'kpi_volume' | 'kpi_cost' | 'kpi_revenue' }) {
  const { t } = useTranslation()
  const { data, money, num } = ctx
  const kpis = data.kpis ?? {}
  const keys = SECTION_KPIS[section].filter((key) => key in kpis)
  // A figure that cannot be worked out is “not available”, never a zero.
  const format = (key: KpiKey, v: number | null | undefined) => (v == null ? t('rsec.unavailable') : key === 'roas' ? `${v}x` : key === 'conversion_rate' ? `${v}%` : MONEY_KPIS.includes(key) ? money(v) : num(v))
  const spark = (key: KpiKey) => (data.trend ?? []).map((p) => Number((p as unknown as Record<string, number | undefined>)[key] ?? 0))

  if (keys.length === 0) return null

  return (
    <>
      {keys.map((key) => {
        const value = kpis[key]
        const before = data.previous?.[key]

        if (data.previous && typeof value === 'number' && key !== 'conversion_rate') {
          return (
            <ComparisonCard
              key={key}
              testId={`sk-${key}`}
              label={t(`revenue.kpi.${key}`)}
              value={format(key, value)}
              current={value}
              before={before ?? null}
              previous={format(key, before)}
              invert={LOWER_IS_BETTER.includes(key)}
              nowLabel={t('reportView.now')}
              beforeLabel={t('reportView.before')}
            />
          )
        }

        return (
          <KpiCard
            key={key}
            testId={`sk-${key}`}
            valueTestId={`sk-${key}-value`}
            label={t(`revenue.kpi.${key}`)}
            icon={KPI_ICON[key]}
            tone={key === 'revenue' || key === 'won' || key === 'roas' ? 'good' : 'neutral'}
            value={<span dir="ltr">{format(key, value)}</span>}
            spark={spark(key).length > 1 && !['cpl', 'cpa', 'cac', 'roas', 'qualified', 'conversion_rate'].includes(key) ? spark(key) : undefined}
          />
        )
      })}
    </>
  )
}

export function FunnelSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const { data } = ctx

  if (!data.funnel) return null

  return (
    <Card data-testid="shared-funnel"><CardBody>
      <h2 className="t-card-title mb-3">{t('reFunnel.title')}</h2>
      <RealEstateFunnel funnel={data.funnel} currency={data.report.currency} />
    </CardBody></Card>
  )
}

const LEVEL_COLOR: Record<string, string> = {
  hot: 'var(--color-danger)', interested: 'var(--brand)', visit_ready: 'color-mix(in srgb, var(--brand) 60%, white)', reservation_ready: 'var(--color-warning)',
}

/** Who is interested, how much, and whether they were answered in time. */
export function InterestedSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.interested

  if (!d) return null

  return (
    <Card data-testid="shared-interested"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.interested')}</h2>
      <div className="flex flex-wrap items-center justify-around gap-5">
        <Donut size={132} thickness={16} legend="side" centerLabel={t('revenue.kpi.interested')} centerValue={ctx.num(d.total)}
          segments={d.levels.map((l) => ({ key: l.key, label: t(`interest.level.${l.key}`, { defaultValue: l.key }), value: l.count, color: LEVEL_COLOR[l.key] ?? 'var(--brand)' }))} />
        <div className="text-center">
          <RingProgress size={84} thickness={9} value={d.on_time_pct ?? 0} tone={(d.on_time_pct ?? 0) >= 80 ? 'good' : (d.on_time_pct ?? 0) >= 50 ? 'warn' : 'bad'} label={t('reportView.onTime')}>
            <span className="text-[0.9375rem] font-bold tabular-nums" dir="ltr">{d.on_time_pct === null ? '—' : `${d.on_time_pct}%`}</span>
          </RingProgress>
          <p className="t-caption mt-1">{t('reportView.onTime')}</p>
        </div>
      </div>
    </CardBody></Card>
  )
}

export function QualificationSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.qualification

  if (!d) return null

  return (
    <Card data-testid="shared-qualification"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.qualification')}</h2>
      <div className="flex flex-wrap items-center justify-around gap-5">
        <RingProgress size={104} thickness={11} value={d.rate ?? 0} tone="good" label={t('reportView.qualifiedRate')}>
          <span className="text-[1.125rem] font-bold tabular-nums" dir="ltr">{d.rate === null ? '—' : `${d.rate}%`}</span>
        </RingProgress>
        <Donut size={112} thickness={14} legend="side" centerValue={ctx.num(d.total)}
          segments={[
            { key: 'qualified', label: t('reportView.qual.qualified'), value: d.qualified, color: 'var(--brand)' },
            { key: 'partial', label: t('reportView.qual.partial'), value: d.partial, color: 'var(--color-warning)' },
            { key: 'unqualified', label: t('reportView.qual.unqualified'), value: d.unqualified, color: 'var(--color-border-strong)' },
          ]} />
      </div>
    </CardBody></Card>
  )
}

export function VisitsSection({ ctx }: { ctx: ReportCtx }) {
  const { t, i18n } = useTranslation()
  const d = ctx.data.visits

  if (!d) return null

  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })

  return (
    <Card data-testid="shared-visits"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.visits')}</h2>
      <div className="flex flex-wrap items-center gap-5">
        <RingProgress size={96} thickness={10} value={d.completion_rate ?? 0} tone="good" label={t('reportView.completion')}>
          <span className="text-[1rem] font-bold tabular-nums" dir="ltr">{d.completion_rate === null ? '—' : `${d.completion_rate}%`}</span>
        </RingProgress>
        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4">
          {(['booked', 'completed', 'no_show', 'cancelled'] as const).map((k) => (
            <div key={k} className="rounded-xl bg-[var(--color-surface-muted)] p-2 text-center">
              <p className="t-metric" dir="ltr">{ctx.num(d[k])}</p>
              <p className="t-caption">{t(`reportView.visit.${k}`)}</p>
            </div>
          ))}
        </div>
      </div>
      {d.by_day && d.by_day.length > 1 && (
        <div className="mt-3">
          <AreaTrend height={90} valueLabel={t('reportView.visits')} points={d.by_day.map((p) => ({ label: day.format(new Date(p.date)), value: p.count }))} />
        </div>
      )}
    </CardBody></Card>
  )
}

export function ReservationsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.reservations

  if (!d) return null

  return (
    <Card data-testid="shared-reservations"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.reservations')}</h2>
      <div className="flex flex-wrap items-center justify-around gap-5">
        <Donut size={124} thickness={15} legend="side" centerValue={ctx.num(d.pending + d.confirmed + d.converted)} centerLabel={t('reportView.live')}
          segments={[
            { key: 'pending', label: t('visits.reservationStatus.pending'), value: d.pending, color: 'var(--color-warning)' },
            { key: 'confirmed', label: t('visits.reservationStatus.confirmed'), value: d.confirmed, color: 'var(--brand)' },
            { key: 'converted', label: t('visits.reservationStatus.converted'), value: d.converted, color: 'color-mix(in srgb, var(--brand) 55%, black)' },
            { key: 'cancelled', label: t('visits.reservationStatus.cancelled'), value: d.cancelled, color: 'var(--color-border-strong)' },
          ]} />
        <div className="text-center">
          <RingProgress size={84} thickness={9} value={d.conversion_rate ?? 0} tone="good" label={t('reportView.reservationRate')}>
            <span className="text-[0.9375rem] font-bold tabular-nums" dir="ltr">{d.conversion_rate === null ? '—' : `${d.conversion_rate}%`}</span>
          </RingProgress>
          <p className="t-caption mt-1">{t('reportView.reservationRate')}</p>
        </div>
      </div>
    </CardBody></Card>
  )
}

const TREND_KEYS = ['leads', 'interested', 'visits', 'reservations', 'won', 'spend', 'revenue'] as const
type TrendKey = (typeof TREND_KEYS)[number]

export function TrendSection({ ctx, className }: { ctx: ReportCtx; className?: string }) {
  const { t, i18n } = useTranslation()
  const { data, money } = ctx
  const trend = data.trend ?? []
  const available = TREND_KEYS.filter((key) => trend.some((p) => key in p))
  const [key, setKey] = useState<TrendKey>(available[0] ?? 'leads')
  const isMoney = key === 'spend' || key === 'revenue'
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })
  const value = (p: (typeof trend)[number], k: TrendKey) => ((p[k] as number | undefined) ?? 0)
  const total = trend.reduce((sum, p) => sum + value(p, key), 0)
  // The previous period lines up day by day, when the server sent it.
  const earlier = data.previous_trend

  return (
    <Card className={className} data-testid="shared-trend"><CardBody>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="t-card-title">{t('revenue.trend')}</h2>
        <div role="group" className="flex flex-wrap gap-1">
          {available.map((k) => (
            <button key={k} type="button" aria-pressed={key === k} data-testid={`shared-trend-${k}`} onClick={() => setKey(k)}
              className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', key === k ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
              {t(`revenue.kpi.${k}`)}
            </button>
          ))}
        </div>
      </div>
      <p className="mb-2 text-[1.375rem] font-bold tabular-nums" dir="ltr">{isMoney ? money(total) : formatCompact(total, i18n.language)}</p>
      <AreaTrend height={150} axes valueLabel={t(`revenue.kpi.${key}`)} previousLabel={t('reportView.before')} formatValue={(v) => (isMoney ? money(v) : formatNumber(v, i18n.language))}
        points={trend.map((p, i) => ({ label: day.format(new Date(p.date)), value: value(p, key), previous: earlier?.[i] ? value(earlier[i], key) : undefined }))} />
    </CardBody></Card>
  )
}

export function PlatformsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()

  if ((ctx.data.platforms ?? []).every((p) => p.leads === 0)) return null

  return (
    <Card data-testid="shared-platforms"><CardBody>
      <h2 className="t-card-title mb-3">{t('revenue.leadsByPlatform')}</h2>
      <Donut size={120} thickness={14} legend="side" centerLabel={t('revenue.kpi.leads')}
        segments={(ctx.data.platforms ?? []).map((p) => ({ key: p.key, label: t(`platforms.${p.key}`, { defaultValue: p.key }), value: p.leads, color: platformColor(p.key) }))} />
    </CardBody></Card>
  )
}

const CAMPAIGN_SORTS = ['leads', 'qualified', 'interested', 'visits', 'reservations', 'won', 'spend', 'revenue'] as const

/**
 * Campaigns: a picture first — who brought the most, and how far their leads got — then the table the spec asks for, with
 * search, a sort, a platform filter and a row you can open. The columns are only the ones the report may show.
 */
export function CampaignsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const { data, money, num } = ctx
  const all = data.campaigns?.rows ?? []
  const [query, setQuery] = useState('')
  const [platform, setPlatform] = useState('')
  const [sort, setSort] = useState<(typeof CAMPAIGN_SORTS)[number]>('leads')
  const [open, setOpen] = useState<string | null>(null)
  const steps = ['interested', 'visits', 'reservations', 'won'] as const

  if (all.length === 0) return <Card data-testid="shared-campaigns"><CardBody><h2 className="t-card-title mb-2">{t('rsec.campaigns.title')}</h2><p className="t-caption">{t('rsec.campaigns.empty')}</p></CardBody></Card>

  const platforms = [...new Set(all.map((c) => c.platform))]
  const hasSpend = all.some((c) => 'spend' in c)
  const hasRevenue = all.some((c) => 'revenue' in c)
  const sorts = CAMPAIGN_SORTS.filter((k) => (k === 'spend' ? hasSpend : k === 'revenue' ? hasRevenue : true))
  const rows = all
    .filter((c) => (platform === '' || c.platform === platform) && c.name.toLowerCase().includes(query.trim().toLowerCase()))
    .toSorted((a, b) => ((b[sort] as number | null | undefined) ?? -1) - ((a[sort] as number | null | undefined) ?? -1))
  const cell = (v: number | null | undefined, fmt: (n: number) => string) => (v == null ? <span className="text-[var(--color-ink-subtle)]">{t('rsec.unavailable')}</span> : fmt(v))

  return (
    <Card data-testid="shared-campaigns"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.campaigns.title')}</h2>
      {all.length >= 2 && (
        <HeatGrid
          testId="shared-campaign-heat"
          className="mb-3"
          columns={steps.map((s) => t(`revenue.step.${s}`))}
          rows={all.slice(0, 6).map((c) => ({ key: c.name, label: c.name, cells: steps.map((s) => (c.leads > 0 ? Math.round((c[s] / c.leads) * 100) : null)) }))}
        />
      )}
      <RankBars limit={6} items={all.map((c) => ({ key: c.name, label: c.name, value: c.leads, note: <span className="tabular-nums" dir="ltr">{c.won} {t('revenue.kpi.won')}</span> }))} />

      <div className="mt-4 flex flex-wrap items-end gap-2" data-testid="shared-campaign-controls">
        <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('rsec.campaigns.search')} aria-label={t('rsec.campaigns.search')} data-testid="shared-campaign-search"
          className="min-w-0 flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-[0.9375rem]" />
        <select value={platform} onChange={(e) => setPlatform(e.target.value)} aria-label={t('rsec.col.platform')} data-testid="shared-campaign-platform" className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-[0.9375rem]">
          <option value="">{t('rsec.campaigns.allPlatforms')}</option>
          {platforms.map((p) => <option key={p} value={p}>{t(`platforms.${p}`, { defaultValue: p })}</option>)}
        </select>
        <select value={sort} onChange={(e) => setSort(e.target.value as typeof sort)} aria-label={t('rsec.campaigns.sort')} data-testid="shared-campaign-sort" className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-[0.9375rem]">
          {sorts.map((k) => <option key={k} value={k}>{t(`rsec.col.${k === 'won' ? 'won' : k}`)}</option>)}
        </select>
      </div>

      <div className="scroll-x mt-2">
        <table className="w-full border-collapse text-[0.875rem]" data-testid="shared-campaign-table">
          <thead><tr className="text-[var(--color-ink-muted)]">
            <th className="p-1.5 text-start">{t('rsec.col.campaign')}</th><th>{t('rsec.col.platform')}</th>
            {all.some((c) => c.impressions != null) && <th>{t('rsec.col.impressions')}</th>}
            {all.some((c) => c.clicks != null) && <><th>{t('rsec.col.clicks')}</th><th>{t('rsec.col.ctr')}</th></>}
            <th>{t('rsec.col.leads')}</th><th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th>
            {hasSpend && <><th>{t('rsec.col.spend')}</th><th>{t('rsec.col.cpc')}</th><th>{t('rsec.col.cpl')}</th></>}
            {hasRevenue && <th>{t('rsec.col.revenue')}</th>}
            {hasSpend && hasRevenue && <th>{t('rsec.col.roas')}</th>}
          </tr></thead>
          <tbody>
            {rows.map((c) => (
              <>
                <tr key={c.name} className="cursor-pointer border-t border-[var(--color-line)] text-center tabular-nums hover:bg-[var(--color-surface-muted)]" onClick={() => setOpen(open === c.name ? null : c.name)} data-testid="shared-campaign-row" aria-expanded={open === c.name}>
                  <td className="max-w-[14rem] truncate p-1.5 text-start font-medium">{c.name}</td>
                  <td>{t(`platforms.${c.platform}`, { defaultValue: c.platform })}</td>
                  {all.some((x) => x.impressions != null) && <td dir="ltr">{cell(c.impressions, num)}</td>}
                  {all.some((x) => x.clicks != null) && <><td dir="ltr">{cell(c.clicks, num)}</td><td dir="ltr">{cell(c.ctr, (n) => `${n}%`)}</td></>}
                  <td>{num(c.leads)}</td><td>{num(c.qualified)}</td><td>{num(c.interested)}</td><td>{num(c.visits)}</td><td>{num(c.reservations)}</td><td>{num(c.won)}</td>
                  {hasSpend && <><td dir="ltr">{cell(c.spend, money)}</td><td dir="ltr">{cell(c.cpc, money)}</td><td dir="ltr">{cell(c.cpl, money)}</td></>}
                  {hasRevenue && <td dir="ltr">{cell(c.revenue, money)}</td>}
                  {hasSpend && hasRevenue && <td dir="ltr">{cell(c.roas, (n) => `${n}x`)}</td>}
                </tr>
                {open === c.name && (
                  <tr key={`${c.name}-detail`} className="bg-[var(--color-surface-muted)]" data-testid="shared-campaign-detail">
                    <td colSpan={14} className="p-3 text-start">
                      <p className="t-small font-semibold">{c.name}</p>
                      <p className="t-caption">{[t(`platforms.${c.platform}`, { defaultValue: c.platform }), c.status ? t(`rsec.campaigns.status.${c.status}`, { defaultValue: c.status }) : null, c.project].filter(Boolean).join(' · ')}</p>
                      <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5">
                        {([['leads', c.leads], ['qualified', c.qualified], ['interested', c.interested], ['visits', c.visits], ['won', c.won]] as const).map(([k, v]) => (
                          <div key={k} className="rounded-lg bg-[var(--color-surface)] p-2 text-center"><p className="t-metric" dir="ltr">{num(v)}</p><p className="t-caption">{t(`rsec.col.${k}`)}</p></div>
                        ))}
                      </div>
                    </td>
                  </tr>
                )}
              </>
            ))}
          </tbody>
        </table>
      </div>
      {data.campaigns && data.campaigns.total > all.length && <p className="t-caption mt-2">{t('rsec.campaigns.more', { shown: all.length, total: data.campaigns.total })}</p>}
    </CardBody></Card>
  )
}

const MODELS = ['first', 'last', 'linear', 'position'] as const

/**
 * Who gets the credit, under the model the report was made with — always named on the card. On a plan with advanced
 * attribution the other three are one tap away; otherwise there is only the one.
 */
export function AttributionSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.attribution
  const [model, setModel] = useState<(typeof MODELS)[number] | null>(null)

  if (!d) return null

  const shown = model ?? d.model
  const rows = (shown === d.model ? d.rows : d.models?.[shown]) ?? []

  return (
    <Card data-testid="shared-attribution"><CardBody>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="t-card-title">{t('rsec.attribution.title')}</h2>
        <span className="rounded-full bg-[var(--brand-soft)] px-2.5 py-0.5 text-[0.8125rem] font-semibold text-[var(--brand)]" data-testid="shared-attr-model">{t('rsec.modelBadge', { model: t(`builder.modelName.${shown}`) })}</span>
      </div>
      {d.models && (
        <div role="group" aria-label={t('rsec.attribution.compare')} className="mb-3 flex flex-wrap gap-1">
          {MODELS.map((m) => (
            <button key={m} type="button" aria-pressed={shown === m} data-testid={`shared-attr-${m}`} onClick={() => setModel(m)}
              className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', shown === m ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
              {t(`builder.modelName.${m}`)}
            </button>
          ))}
        </div>
      )}
      {rows.length === 0 ? <p className="t-caption">{t('rsec.attribution.empty')}</p> : (
        <>
          <p className="t-caption mb-2">{t('rsec.attribution.columns')}</p>
          <div className="scroll-x">
            <table className="w-full border-collapse text-[0.875rem]" data-testid="shared-attr-table">
              <thead><tr className="text-[var(--color-ink-muted)]"><th className="p-1.5 text-start">{t('rsec.col.source')}</th><th>{t('rsec.col.leads')}</th><th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th>{rows.some((r) => r.revenue != null) && <th>{t('rsec.col.revenue')}</th>}</tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.key} className="border-t border-[var(--color-line)] text-center tabular-nums">
                    <td className="p-1.5 text-start font-medium">{r.label ? t(`rsec.channel.${r.label}`, { defaultValue: r.label }) : t('tracking.unattributed')}</td>
                    <td>{Math.round(r.leads * 10) / 10}</td><td>{Math.round((r.qualified ?? 0) * 10) / 10}</td><td>{Math.round(r.interested * 10) / 10}</td><td>{Math.round(r.visits * 10) / 10}</td><td>{Math.round(r.reservations * 10) / 10}</td>
                    {rows.some((x) => x.revenue != null) && <td dir="ltr">{ctx.money(r.revenue)}</td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </CardBody></Card>
  )
}

/** Per salesperson: names and rates only, never a contact detail. Shown on a public link only if the owner switched it on. */
export function TeamSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const rows = ctx.data.team ?? []
  const pct = (v: number | null) => (v == null ? t('rsec.unavailable') : `${v}%`)
  const hasRevenue = rows.some((r) => r.revenue != null)

  return (
    <Card data-testid="shared-team"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.team.title')}</h2>
      {rows.length === 0 ? <p className="t-caption">{t('rsec.team.empty')}</p> : (
        <div className="scroll-x">
          <table className="w-full border-collapse text-[0.875rem]" data-testid="shared-team-table">
            <thead><tr className="text-[var(--color-ink-muted)]">
              <th className="p-1.5 text-start">{t('rsec.team.name')}</th><th>{t('rsec.team.leads')}</th><th>{t('rsec.team.contactRate')}</th><th>{t('rsec.team.response')}</th><th>{t('rsec.team.followUp')}</th>
              <th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th><th>{t('rsec.team.conversion')}</th>{hasRevenue && <th>{t('rsec.col.revenue')}</th>}
            </tr></thead>
            <tbody>
              {rows.map((m) => (
                <tr key={m.name} className="border-t border-[var(--color-line)] text-center tabular-nums" data-testid="shared-team-row">
                  <td className="max-w-[12rem] truncate p-1.5 text-start font-medium">{m.name}</td>
                  <td>{ctx.num(m.leads)}</td><td dir="ltr">{pct(m.contact_rate)}</td>
                  <td dir="ltr">{m.response_minutes == null ? t('rsec.unavailable') : t('rsec.sales.responseValue', { m: m.response_minutes })}</td>
                  <td dir="ltr">{pct(m.follow_up_completion)}</td>
                  <td>{ctx.num(m.qualified)}</td><td>{ctx.num(m.interested)}</td><td>{ctx.num(m.visits)}</td><td>{ctx.num(m.reservations)}</td><td>{ctx.num(m.won)}</td><td dir="ltr">{pct(m.conversion_rate)}</td>
                  {hasRevenue && <td dir="ltr">{ctx.money(m.revenue)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </CardBody></Card>
  )
}

export function AutomationSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.automation

  if (!d) return null

  return (
    <Card data-testid="shared-automation"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.automation')}</h2>
      <div className="flex flex-wrap items-center gap-5">
        <RingProgress size={96} thickness={10} value={d.handled_pct ?? 0} tone="good" label={t('reportView.handled')}>
          <span className="text-[1rem] font-bold tabular-nums" dir="ltr">{d.handled_pct === null ? '—' : `${d.handled_pct}%`}</span>
        </RingProgress>
        <div className="grid flex-1 grid-cols-3 gap-2">
          {(['automated', 'human', 'needs_human'] as const).map((k) => (
            <div key={k} className="rounded-xl bg-[var(--color-surface-muted)] p-2 text-center">
              <p className="t-metric" dir="ltr">{ctx.num(d[k])}</p>
              <p className="t-caption">{t(`reportView.auto.${k}`)}</p>
            </div>
          ))}
        </div>
      </div>
    </CardBody></Card>
  )
}

const HEALTH_STATUS: Record<string, StatusKey> = { production_verified: 'healthy', live_verified: 'healthy', ready: 'attention', code_verified: 'attention', not_configured: 'pending' }

export function TrackingHealthSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const rows = ctx.data.tracking_health ?? []

  return (
    <Card data-testid="shared-tracking"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.tracking')}</h2>
      <ul className="grid gap-2 sm:grid-cols-2">
        {rows.map((r) => (
          <li key={r.platform} className="flex items-center justify-between gap-2 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2">
            <span className="flex items-center gap-2 text-[0.9375rem] font-medium">
              <span aria-hidden className="size-2.5 rounded-full" style={{ background: platformColor(r.platform) }} />
              {t(`platforms.${r.platform}`, { defaultValue: r.platform })}
            </span>
            <span className="flex items-center gap-2">
              <span className="t-caption tabular-nums" dir="ltr">{r.sent}/{r.sent + r.failed}</span>
              <StatusPill status={r.failed > 0 ? 'risk' : (HEALTH_STATUS[r.state] ?? 'pending')} />
            </span>
          </li>
        ))}
      </ul>
    </CardBody></Card>
  )
}

const say = findingText

export function InsightsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const items = ctx.data.insights ?? []

  return (
    <Card data-testid="shared-insights"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.insights')}</h2>
      <ul className="space-y-2">
        {items.map((f, i) => (
          <li key={i} className="rounded-xl bg-[var(--color-surface-muted)] px-3 py-2 text-[0.9375rem]" data-testid="shared-insight">{say(t, 'finding', f)}</li>
        ))}
      </ul>
    </CardBody></Card>
  )
}

export function RecommendationsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const items = ctx.data.recommendations ?? []

  return (
    <Card data-testid="shared-recommendations"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.recommendations')}</h2>
      <ul className="space-y-2.5">
        {items.map((r, i) => (
          <li key={i} className="rounded-xl border border-[var(--color-line)] p-3" data-testid="shared-recommendation">
            <p className="text-[0.9375rem] font-semibold">{say(t, 'finding', r)}</p>
            <p className="t-caption mt-0.5">{say(t, 'impact', r)}</p>
            <p className="mt-1.5 flex flex-wrap items-center gap-2 text-[0.9375rem]">
              <CalendarCheck className="size-4 text-[var(--brand)]" aria-hidden />
              <span>{say(t, 'action', r)}</span>
              {r.link && <Link to={r.link} className="t-small font-medium text-[var(--brand)] hover:underline">{t('reportView.open')}</Link>}
            </p>
          </li>
        ))}
      </ul>
    </CardBody></Card>
  )
}

/** A partner's statement: what they are owed, what has been paid, what is left — and the payments behind it. */
export function StatementSection({ ctx }: { ctx: ReportCtx }) {
  const { t, i18n } = useTranslation()
  const d = ctx.data.statement

  if (!d) return null

  const paidShare = d.commission.due ? Math.min(100, Math.round((d.commission.paid / d.commission.due) * 100)) : 0
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { dateStyle: 'medium' })

  return (
    <Card data-testid="shared-statement"><CardBody>
      <h2 className="t-card-title mb-1">{t('partners.statement.shared')} · {d.partner}</h2>
      <p className="t-caption mb-3" data-testid="shared-statement-terms">
        {d.terms.agreed ? (d.terms.type === 'fixed' ? t('partners.statement.termsFixed', { amount: ctx.money(d.terms.fixed) }) : t('partners.statement.termsPercent', { percent: d.terms.percent })) : t('partners.noCommission')}
      </p>
      <div className="flex flex-wrap items-center gap-5">
        <RingProgress size={96} thickness={10} value={paidShare} tone="good" label={t('partners.statement.paidShare')}>
          <span className="text-[1rem] font-bold tabular-nums" dir="ltr">{paidShare}%</span>
        </RingProgress>
        <div className="grid flex-1 grid-cols-3 gap-2 text-center">
          {([['due', d.commission.due], ['paid', d.commission.paid], ['balance', d.commission.balance]] as const).map(([key, value]) => (
            <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] p-2" data-testid={`shared-statement-${key}`}>
              <p className="t-metric" dir="ltr">{value === null ? '—' : ctx.money(value)}</p>
              <p className="t-caption">{t(`partners.statement.${key}`)}</p>
            </div>
          ))}
        </div>
      </div>
      {d.payments.length > 0 && (
        <ul className="mt-3 space-y-1" data-testid="shared-statement-payments">
          {d.payments.map((p, i) => (
            <li key={i} className="flex items-center justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.9375rem]">
              <span className="tabular-nums" dir="ltr">{ctx.money(p.amount)}</span>
              <span className="t-caption min-w-0 flex-1 truncate">{[day.format(new Date(p.paid_on)), p.reference].filter(Boolean).join(' · ')}</span>
            </li>
          ))}
        </ul>
      )}
    </CardBody></Card>
  )
}
