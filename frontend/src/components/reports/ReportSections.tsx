import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BadgeDollarSign, CalendarCheck, Coins, Eye, KeyRound, Sparkles, Target, TrendingUp, UserCheck, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { TableWrap } from '@/components/ui/Table'
import { AreaTrend, ComparisonCard, Donut, HeatGrid, KpiCard, PipelineFunnel, RankBars, RingProgress, StatusPill, type StatusKey } from '@/components/visual'
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
interface Ctx {
  data: SharedReportData
  money: (v: number | null | undefined) => string
  num: (v: number | null | undefined) => string
}

export function useReportCtx(data: SharedReportData): Ctx {
  const { i18n } = useTranslation()

  return {
    data,
    money: (v) => formatMoneyCompact(v ?? null, data.report.currency, i18n.language),
    num: (v) => formatNumber(v ?? 0, i18n.language),
  }
}

const KPI_ICON: Record<KpiKey, LucideIcon> = {
  leads: Users, interested: Sparkles, visits: Eye, reservations: KeyRound, won: UserCheck, spend: Coins, cpl: BadgeDollarSign, cac: Target, roas: TrendingUp, revenue: Coins,
}
const SECTION_KPIS: Record<string, KpiKey[]> = {
  kpi_volume: ['leads', 'interested', 'visits', 'reservations', 'won'],
  kpi_cost: ['spend', 'cpl', 'cac', 'roas'],
  kpi_revenue: ['revenue'],
}
/** Lower is better for what the company pays. */
const LOWER_IS_BETTER: KpiKey[] = ['spend', 'cpl', 'cac']

/**
 * The headline figures of a section, each with a sparkline and — when the report compares — against
 * the last period. Cards only: the page lays consecutive KPI sections out as one grid.
 */
export function KpiCards({ ctx, section }: { ctx: Ctx; section: 'kpi_volume' | 'kpi_cost' | 'kpi_revenue' }) {
  const { t } = useTranslation()
  const { data, money, num } = ctx
  const kpis = data.kpis ?? {}
  const keys = SECTION_KPIS[section].filter((key) => key in kpis)
  const format = (key: KpiKey, v: number | null | undefined) => (key === 'roas' ? (v == null ? '—' : `${v}x`) : ['spend', 'cpl', 'cac', 'revenue'].includes(key) ? money(v) : num(v))
  const spark = (key: KpiKey) => (data.trend ?? []).map((p) => Number((p as unknown as Record<string, number | undefined>)[key] ?? 0))

  if (keys.length === 0) return null

  return (
    <>
      {keys.map((key) => {
        const value = kpis[key]
        const before = data.previous?.[key]

        if (data.previous && typeof value === 'number') {
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
            spark={spark(key).length > 1 && key !== 'cpl' && key !== 'cac' && key !== 'roas' ? spark(key) : undefined}
          />
        )
      })}
    </>
  )
}

export function FunnelSection({ ctx }: { ctx: Ctx }) {
  const { t } = useTranslation()
  const { data, money } = ctx

  return (
    <Card data-testid="shared-funnel"><CardBody>
      <h2 className="t-card-title mb-3">{t('revenue.funnel')}</h2>
      <PipelineFunnel steps={(data.funnel ?? []).map((s) => ({ key: s.key, label: t(`revenue.step.${s.key}`), count: s.count, costPer: s.cost != null ? money(s.cost) : null }))} />
    </CardBody></Card>
  )
}

const LEVEL_COLOR: Record<string, string> = {
  hot: 'var(--color-danger)', interested: 'var(--brand)', visit_ready: 'color-mix(in srgb, var(--brand) 60%, white)', reservation_ready: 'var(--color-warning)',
}

/** Who is interested, how much, and whether they were answered in time. */
export function InterestedSection({ ctx }: { ctx: Ctx }) {
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

export function QualificationSection({ ctx }: { ctx: Ctx }) {
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

export function VisitsSection({ ctx }: { ctx: Ctx }) {
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

export function ReservationsSection({ ctx }: { ctx: Ctx }) {
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

export function TrendSection({ ctx, className }: { ctx: Ctx; className?: string }) {
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

export function PlatformsSection({ ctx }: { ctx: Ctx }) {
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

/** Campaigns as a picture first — who brought the most, and how far their leads got — and the table behind “details”. */
export function CampaignsSection({ ctx }: { ctx: Ctx }) {
  const { t } = useTranslation()
  const { data, money, num } = ctx
  const rows = data.campaigns ?? []
  const steps = ['interested', 'visits', 'reservations', 'won'] as const

  if (rows.length === 0) return null

  return (
    <Card data-testid="shared-campaigns"><CardBody>
      <h2 className="t-card-title mb-3">{t('reportView.campaigns')}</h2>
      {rows.length >= 2 && (
        <HeatGrid
          testId="shared-campaign-heat"
          className="mb-3"
          columns={steps.map((s) => t(`revenue.step.${s}`))}
          rows={rows.slice(0, 6).map((c) => ({ key: c.name, label: c.name, cells: steps.map((s) => (c.leads > 0 ? Math.round((c[s] / c.leads) * 100) : null)) }))}
        />
      )}
      <RankBars limit={6} items={rows.map((c) => ({ key: c.name, label: c.name, value: c.leads, note: <span className="tabular-nums" dir="ltr">{c.won} {t('revenue.kpi.won')}</span> }))} />
      <details className="mt-3" data-testid="shared-campaigns-details">
        <summary className="t-small cursor-pointer font-medium">{t('reportView.details')}</summary>
        <TableWrap className="mt-2">
          <table className="w-full border-collapse text-[0.875rem]">
            <thead><tr className="text-[var(--color-ink-muted)]">
              <th className="p-1.5 text-start">{t('revenue.table.campaign')}</th><th>{t('revenue.kpi.leads')}</th><th>{t('revenue.kpi.interested')}</th><th>{t('revenue.kpi.visits')}</th><th>{t('revenue.kpi.reservations')}</th><th>{t('revenue.kpi.won')}</th>
              {rows.some((c) => 'spend' in c) && <th>{t('revenue.kpi.spend')}</th>}
              {rows.some((c) => 'revenue' in c) && <th>{t('revenue.kpi.revenue')}</th>}
            </tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.name} className="border-t border-[var(--color-line)] text-center tabular-nums">
                  <td className="max-w-[14rem] truncate p-1.5 text-start font-medium">{c.name}</td>
                  <td>{num(c.leads)}</td><td>{num(c.interested)}</td><td>{num(c.visits)}</td><td>{num(c.reservations)}</td><td>{num(c.won)}</td>
                  {'spend' in c && <td dir="ltr">{money(c.spend)}</td>}
                  {'revenue' in c && <td dir="ltr">{money(c.revenue)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      </details>
    </CardBody></Card>
  )
}

const MODELS = ['first', 'last', 'linear', 'position'] as const

/** Who gets the credit under each way of counting it, side by side. */
export function AttributionSection({ ctx }: { ctx: Ctx }) {
  const { t } = useTranslation()
  const d = ctx.data.attribution
  const [model, setModel] = useState<(typeof MODELS)[number]>('last')

  if (!d) return null

  return (
    <Card data-testid="shared-attribution"><CardBody>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="t-card-title">{t('reportView.attribution')}</h2>
        <div role="group" className="flex flex-wrap gap-1">
          {MODELS.map((m) => (
            <button key={m} type="button" aria-pressed={model === m} data-testid={`shared-attr-${m}`} onClick={() => setModel(m)}
              className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', model === m ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
              {t(`tracking.models.${m}`)}
            </button>
          ))}
        </div>
      </div>
      <RankBars limit={6} items={(d.models[model] ?? []).map((r) => ({ key: r.key, label: r.label ?? t('tracking.unattributed'), value: Math.round(r.leads * 10) / 10, note: <span className="tabular-nums" dir="ltr">{Math.round(r.reservations * 10) / 10} {t('revenue.kpi.reservations')}</span> }))} />
    </CardBody></Card>
  )
}

export function TeamSection({ ctx }: { ctx: Ctx }) {
  const { t } = useTranslation()

  return (
    <Card data-testid="shared-team"><CardBody>
      <h2 className="t-card-title mb-3">{t('manager.team')}</h2>
      <RankBars limit={8} items={(ctx.data.team ?? []).map((m) => ({ key: m.name, label: m.name, value: m.won, note: <span className="tabular-nums" dir="ltr">{m.leads} · {m.won}</span> }))} />
    </CardBody></Card>
  )
}

export function AutomationSection({ ctx }: { ctx: Ctx }) {
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

export function TrackingHealthSection({ ctx }: { ctx: Ctx }) {
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

export function InsightsSection({ ctx }: { ctx: Ctx }) {
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

export function RecommendationsSection({ ctx }: { ctx: Ctx }) {
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
export function StatementSection({ ctx }: { ctx: Ctx }) {
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
