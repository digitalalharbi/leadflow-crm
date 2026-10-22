import { useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, CalendarClock, Gauge, Rocket, Snowflake, Sparkles, Users2 } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ChartCard, LeadFlowChart, type FlowSeries, FLOW_SERIES } from '@/components/charts'
import { Skeleton } from '@/components/ui'
import { useCommandCenter, type AttentionKey, type CommandCenterData } from '@/features/commandCenter'
import { formatClock, formatNumber, initials } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { DashboardData } from '@/types/api'

const ICON: Record<AttentionKey, LucideIcon> = {
  priority: Sparkles,
  stalled: Snowflake,
  wrong_fit: Gauge,
  sla_breached: Gauge,
  overdue_follow_ups: CalendarClock,
  hot: Sparkles,
  appointments_awaiting_outcome: CalendarClock,
  unassigned: Users2,
  automation_failures: Gauge,
  message_failures: Gauge,
}

const URGENCY_DOT: Record<string, string> = {
  critical: 'var(--color-danger)',
  high: 'var(--color-warning)',
  medium: 'var(--brand)',
  low: 'var(--color-border-strong)',
}

/**
 * The dashboard, laid out as the reference lays it out.
 *
 * Three columns and a wide card under two of them: a deep-green hero on the
 * start side, the big chart in the middle, a headline figure with its trend on
 * the end side, a small stat under the hero, and a table under the chart. The
 * grid is named areas, so the arrangement is one readable declaration rather
 * than a column of spans, and it collapses to a single column in the order a
 * person reads it on a phone.
 *
 * Every number on it is a number the product already had — what was a row of
 * tiles and a command panel is now this arrangement of the same data, with the
 * same links and the same test hooks.
 */
export function DashboardBoard({
  data,
  trend,
}: {
  data: DashboardData
  trend: number[]
}) {
  const { t } = useTranslation()
  const { data: command, isLoading } = useCommandCenter()
  const [series, setSeries] = useState<FlowSeries>('leads')

  const comparison = data.comparison

  return (
    <div
      className="grid gap-4 lg:[grid-template-areas:'hero_chart_total''mini_chart_pipe''table_table_pipe'] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.75fr)_minmax(0,1.1fr)]"
      data-testid="dashboard-board"
    >
      <div className="lg:[grid-area:hero]">
        {isLoading || !command ? <Skeleton className="h-60 rounded-[var(--radius-card)]" /> : <HeroCard command={command} />}
      </div>

      <div className="lg:[grid-area:mini]">
        <MiniStat
          label={t('dashboard.hotLeads')}
          value={data.summary.hot_leads}
          previous={comparison?.hot}
          to="/leads?temperature[]=hot"
          testId="kpi-hot"
        />
      </div>

      <div className="lg:[grid-area:chart]">
        <ChartCard
          className="h-full"
          title={t('charts.leadFlow.title')}
          icon
          height={270}
          testId="lead-flow"
          empty={(data.timeline ?? []).every((p) => p.leads + p.qualified + p.won === 0)}
          emptyMessage={t('dashboard.noData')}
          action={<SeriesToggle value={series} onChange={setSeries} />}
          table={{
            head: [
              t('charts.leadFlow.day'),
              t('charts.leadFlow.leads'),
              t('charts.leadFlow.qualified'),
              t('charts.leadFlow.won'),
            ],
            rows: (data.timeline ?? []).map((point) => [point.date, point.leads, point.qualified, point.won]),
          }}
        >
          <LeadFlowChart points={data.timeline ?? []} series={series} height={270} />
        </ChartCard>
      </div>

      <div className="lg:[grid-area:total]">
        <TotalCard
          value={data.summary.total_leads}
          previous={comparison?.total}
          trend={trend}
        />
      </div>

      <div className="lg:[grid-area:pipe]">
        <PipelineCard data={data} command={command} />
      </div>

      <div className="lg:[grid-area:table]">
        <RecommendedTable command={command} loading={isLoading} />
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ hero */

/**
 * What needs a person, on the deep-green card.
 *
 * One big number — how many things are waiting — and underneath, each of them
 * with its own count and its way in. A row appears only when its count is above
 * zero; when nothing is waiting the card says so once, which is the answer
 * somebody opening the product at nine is glad to see.
 */
function HeroCard({ command }: { command: CommandCenterData }) {
  const { t } = useTranslation()
  const total = command.attention.reduce((sum, row) => sum + row.count, 0)

  return (
    <section
      className="flex h-full min-h-60 flex-col justify-between gap-3 rounded-[var(--radius-card)] bg-[var(--chrome-1)] p-5 text-[var(--chrome-ink)]"
      data-testid="command-center"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="t-card-title">
            {command.attention.some((row) => row.severity === 'critical') ? t('commandCenter.needsYou') : t('commandCenter.title')}
          </h2>
          <p className="mt-0.5 text-[0.875rem] text-[var(--chrome-muted)]">
            {command.attention.length === 0
              ? t('commandCenter.allClear')
              : t('commandCenter.subtitle', { count: command.attention.length })}
          </p>
        </div>
        {command.open_leads > 0 && (
          <Link
            to="/work"
            data-testid="start-work"
            aria-label={t('commandCenter.startWork')}
            className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--chrome-active)] text-[var(--chrome-active-ink)] ring-1 ring-[var(--chrome-line)] hover:brightness-110"
          >
            <Rocket className="size-4" aria-hidden />
          </Link>
        )}
      </div>

      <p className="text-[2.5rem] font-bold leading-none tabular-nums text-[var(--accent-on-dark)]" data-testid="attention-total">{formatNumber(total)}</p>

      {command.attention.length > 0 && (
        <ul className="space-y-1 sm:max-h-28 sm:overflow-y-auto sm:pe-1" data-testid="attention-list">
          {command.attention.map((row) => {
            // A key the server adds before this screen learns it must not blank the dashboard.
            const Icon = ICON[row.key] ?? Gauge
            return (
              <li key={row.key}>
                <Link
                  to={row.href}
                  data-testid={`attention-${row.key}`}
                  className="flex items-center gap-2 rounded-full bg-[var(--chrome-2)] px-2.5 py-1 text-[0.875rem] hover:brightness-125"
                >
                  <Icon className="size-3.5 shrink-0 text-[var(--chrome-muted)]" aria-hidden />
                  <span className="min-w-0 flex-1 truncate">
                    {t(`commandCenter.attention.${row.key}`, { minutes: row.minutes })}
                  </span>
                  <span className="font-semibold tabular-nums">{formatNumber(row.count)}</span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

/* ------------------------------------------------------------- small cards */

/** A change against the previous period, in the reference's small green pill. */
function DeltaPill({ value, previous, invert = false }: { value: number; previous?: number | null; invert?: boolean }) {
  if (previous === undefined || previous === null || previous <= 0) return null

  const change = ((value - previous) / previous) * 100
  const good = invert ? change <= 0 : change >= 0

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold tabular-nums',
        // A change is a figure worth noticing, so it wears the copper: dark copper on its soft fill.
        good ? 'bg-[var(--accent-soft)] text-[var(--accent-text)] ring-1 ring-inset ring-[color-mix(in_srgb,var(--accent)_30%,transparent)]' : 'bg-[var(--color-danger)] text-white',
      )}
      data-testid="delta-pill"
      // The sign belongs before the number in either direction: in an RTL line
      // a bare "-84.6%" is reordered into "84.6%-".
      dir="ltr"
    >
      {change >= 0 ? '+' : ''}
      {change.toFixed(1)}%
    </span>
  )
}

function MiniStat({
  label, value, previous, to, testId,
}: {
  label: string
  value: number
  previous?: number | null
  to: string
  testId: string
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-[var(--radius-card)] bg-[var(--color-surface)] px-5 py-4 shadow-[var(--shadow-card)]">
      <Link to={to} data-testid={testId} className="min-w-0">
        <span className="block text-[0.875rem] text-[var(--color-ink-muted)]">{label}</span>
        <span className="mt-0.5 block text-[1.75rem] font-bold leading-tight tabular-nums" data-testid={`${testId}-value`}>{formatNumber(value)}</span>
      </Link>
      <DeltaPill value={value} previous={previous} />
    </div>
  )
}

/** The pill switch above the chart: a grey track, the chosen series filled green. */
function SeriesToggle({ value, onChange }: { value: FlowSeries; onChange: (next: FlowSeries) => void }) {
  const { t } = useTranslation()

  return (
    <div className="flex items-center gap-0.5 rounded-full bg-[var(--color-chrome-fill)] p-1" role="group" data-testid="series-toggle">
      {FLOW_SERIES.map((key) => (
        <button
          key={key}
          type="button"
          aria-pressed={value === key}
          onClick={() => onChange(key)}
          data-testid={`series-${key}`}
          className={cn(
            'rounded-full px-3 py-1.5 text-[0.875rem] transition-colors',
            value === key ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
          )}
        >
          {t(`charts.leadFlow.${key}`)}
        </button>
      ))}
    </div>
  )
}

/* ----------------------------------------------------------------- total */

function TotalCard({ value, previous, trend }: { value: number; previous?: number | null; trend: number[] }) {
  const { t } = useTranslation()

  return (
    <section className="flex h-full flex-col overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]">
      <div className="flex items-start justify-between gap-2 px-5 pt-4">
        <div>
          <h2 className="t-card-title">{t('dashboard.totalLeads')}</h2>
          <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t('dashboard.totalHint')}</p>
        </div>
        <Link to="/leads" className="chrome-circle !size-9" aria-label={t('dashboard.openLeadList')}>
          <ArrowUpRight className="size-4 rtl:-scale-x-100" aria-hidden />
        </Link>
      </div>

      <div className="px-5 pt-3 text-center">
        <Link to="/leads" data-testid="kpi-total" className="inline-block">
          <span className="block text-[0.875rem] text-[var(--color-ink-muted)]">{t('dashboard.totalLeads')}</span>
          <span className="block text-[2.25rem] font-bold leading-tight tabular-nums" data-testid="kpi-total-value">{formatNumber(value)}</span>
        </Link>
        <div className="mt-0.5">
          <DeltaPill value={value} previous={previous} />
        </div>
      </div>

      <div className="mt-auto">
        <AreaTrend values={trend} />
      </div>

      <div className="mx-auto mb-4 flex items-center gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1">
        <Link to="/work" className="rounded-full bg-[var(--brand)] px-5 py-1.5 text-[0.9375rem] text-[var(--brand-contrast)]">
          {t('nav.work')}
        </Link>
        <Link to="/leads" className="rounded-full px-5 py-1.5 text-[0.9375rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">
          {t('dashboard.allLeads')}
        </Link>
      </div>
    </section>
  )
}

/**
 * The shape of the last fortnight: a soft ridge over a fill that fades to
 * nothing at the floor, the same area language as every other trend.
 */
function AreaTrend({ values }: { values: number[] }) {
  const fill = useId()
  const width = 300
  const height = 96

  if (values.length < 2) return <div style={{ height }} aria-hidden />

  const max = Math.max(...values, 1)
  const step = width / (values.length - 1)
  const pts = values.map((v, i) => [i * step, height - 6 - (v / max) * (height - 22)] as const)

  // Smoothed with midpoint curves: the reference's line is a soft ridge, and a
  // polyline of fourteen straight segments reads as an instrument, not a trend.
  const line = pts.reduce((path, [x, y], i) => {
    if (i === 0) return `M${x.toFixed(1)},${y.toFixed(1)}`
    const [px, py] = pts[i - 1]
    const cx = (px + x) / 2
    return `${path} C${cx.toFixed(1)},${py.toFixed(1)} ${cx.toFixed(1)},${y.toFixed(1)} ${x.toFixed(1)},${y.toFixed(1)}`
  }, '')

  return (
    <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="h-24 w-full" role="img" aria-label="trend">
      <defs>
        <linearGradient id={fill} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--brand)" stopOpacity="0.28" />
          <stop offset="100%" stopColor="var(--brand)" stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={`${line} L${width},${height} L0,${height} Z`} fill={`url(#${fill})`} />
      <path d={line} fill="none" stroke="var(--chart-seq-latest)" strokeWidth="2" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

/* -------------------------------------------------------------- pipeline */

function PipelineCard({ data, command }: { data: DashboardData; command?: CommandCenterData }) {
  const { t } = useTranslation()
  const comparison = data.comparison
  const rate = data.summary.conversion_rate
  const [whole, decimal] = rate.toFixed(1).split('.')

  const stages: Array<{ key: string; label: string; value: number; to: string; testId: string }> = [
    { key: 'qualified', label: t('dashboard.qualified'), value: data.summary.qualified, to: '/leads?status[]=qualified', testId: 'kpi-qualified' },
    { key: 'appointments', label: t('dashboard.appointments'), value: data.summary.appointments, to: '/leads?status[]=appointment', testId: 'kpi-appointments' },
    { key: 'won', label: t('dashboard.won'), value: data.summary.won, to: '/leads?status[]=won', testId: 'kpi-won' },
  ]

  return (
    <section className="flex h-full flex-col gap-4 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-card)]">
      <div className="flex items-center gap-3">
        <span className="chrome-circle !size-11" aria-hidden>
          <Gauge className="size-[1.125rem]" />
        </span>
        <div>
          <h2 className="t-card-title">{t('dashboard.conversionTitle')}</h2>
          <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t('dashboard.conversionHint')}</p>
        </div>
      </div>

      <div className="flex items-center gap-3">
        <p className="text-[2.5rem] font-bold leading-none tabular-nums" dir="ltr">
          {whole}
          <span className="font-medium text-[var(--color-ink-muted)]">.{decimal}</span>
          <span className="text-[1.5rem] text-[var(--color-ink-muted)]">%</span>
        </p>
        <DeltaPill value={rate} previous={comparison ? (comparison.total > 0 ? (comparison.won / comparison.total) * 100 : null) : null} />
      </div>

      {/* The funnel's three steps, each its own way into the list it counts. */}
      <div className="grid grid-cols-3 gap-2">
        {stages.map((stage) => (
          <Link
            key={stage.key}
            to={stage.to}
            data-testid={stage.testId}
            className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] px-2.5 py-2 text-center hover:bg-[var(--color-chrome-fill)]"
          >
            <span className="block text-[0.8125rem] text-[var(--color-ink-muted)]">{stage.label}</span>
            <span className="block text-[1.25rem] font-semibold tabular-nums" data-testid={`${stage.testId}-value`}>{formatNumber(stage.value)}</span>
          </Link>
        ))}
      </div>

      {command && (command.workload.length > 0 || command.appointments_today.length > 0) && (
        <div className="mt-auto space-y-3 rounded-[var(--radius-panel)] bg-[var(--color-surface-muted)] p-3.5">
          {command.workload.length > 0 && (
            <div data-testid="team-workload">
              <h3 className="text-[0.9375rem] font-medium">{t('commandCenter.workload')}</h3>
              <div className="mt-2 flex items-center">
                {command.workload.slice(0, 4).map((member) => (
                  <span
                    key={member.id}
                    title={`${member.name} · ${member.open}`}
                    className="-me-2 grid size-9 place-items-center rounded-full border-2 border-[var(--color-surface-muted)] bg-[var(--brand-soft)] text-[0.8125rem] font-semibold text-[var(--brand)]"
                  >
                    {initials(member.name)}
                  </span>
                ))}
                {command.workload.length > 4 && (
                  <span className="ms-3 grid size-9 place-items-center rounded-full bg-[var(--brand)] text-[0.875rem] font-medium text-[var(--brand-contrast)]">
                    +{command.workload.length - 4}
                  </span>
                )}
              </div>
            </div>
          )}

          {command.appointments_today.length > 0 && (
            <div data-testid="appointments-today">
              <h3 className="text-[0.9375rem] font-medium">{t('commandCenter.appointmentsToday')}</h3>
              <ul className="mt-1.5 space-y-1">
                {command.appointments_today.slice(0, 3).map((a) => (
                  <li key={a.id} className="flex items-center gap-2 text-[0.875rem]">
                    <span className="w-11 shrink-0 tabular-nums text-[var(--color-ink-muted)]">{formatClock(a.at)}</span>
                    {a.lead_id ? (
                      <Link to={`/leads/${a.lead_id}`} className="min-w-0 flex-1 truncate hover:underline">{a.lead}</Link>
                    ) : (
                      <span className="min-w-0 flex-1 truncate">{a.lead}</span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

/* ----------------------------------------------------------------- table */

/**
 * "Start with these", as the reference's payment history: a quiet header row,
 * then rows with a name and what is underneath it, a status with its dot, and
 * the action. The ranking is the work queue's own, so this list and
 * «اعمل الآن» cannot disagree about who is first.
 */
function RecommendedTable({ command, loading }: { command?: CommandCenterData; loading: boolean }) {
  const { t } = useTranslation()

  return (
    <section className="rounded-[var(--radius-card)] bg-[var(--color-surface)] px-5 pb-3 pt-4 shadow-[var(--shadow-card)]">
      <div className="flex items-start justify-between gap-2">
        <div>
          <h2 className="t-card-title">{t('commandCenter.recommended')}</h2>
          <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t('commandCenter.recommendedHint')}</p>
        </div>
        <Link to="/work" className="chrome-circle !size-9" aria-label={t('dashboard.openQueue')}>
          <ArrowUpRight className="size-4 rtl:-scale-x-100" aria-hidden />
        </Link>
      </div>

      {loading || !command ? (
        <Skeleton className="mt-3 h-32" />
      ) : command.recommended.length === 0 ? (
        <p className="mt-3 text-[0.9375rem] text-[var(--color-ink-muted)]" data-testid="no-recommendations">
          {t('commandCenter.nothingToDo')}
        </p>
      ) : (
        <>
          <div className="mt-2 hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_7rem_8rem] gap-3 px-2 text-[0.875rem] text-[var(--color-ink-muted)] sm:grid">
            <span>{t('commandCenter.colName')}</span>
            <span>{t('commandCenter.colReason')}</span>
            <span>{t('commandCenter.colStatus')}</span>
            <span>{t('commandCenter.colAction')}</span>
          </div>
          <ul className="mt-1" data-testid="recommended-list">
            {command.recommended.map((item) => (
              <li key={item.id}>
                <Link
                  to={`/leads/${item.id}`}
                  data-testid="recommended-item"
                  className="grid items-center gap-x-3 gap-y-0.5 rounded-[var(--radius-control)] px-2 py-2 text-[0.9375rem] hover:bg-[var(--color-surface-muted)] sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_7rem_8rem]"
                >
                  <span className="flex min-w-0 items-center gap-2.5">
                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[0.8125rem] font-semibold text-[var(--brand)]" aria-hidden>
                      {initials(item.name)}
                    </span>
                    <span className="min-w-0">
                      <span className="block truncate font-medium">{item.name}</span>
                      {item.project && <span className="block truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{item.project}</span>}
                    </span>
                  </span>
                  <span className="truncate text-[var(--color-ink-muted)]">{item.reason}</span>
                  <span className="flex items-center gap-1.5">
                    <span aria-hidden className="size-2 rounded-full" style={{ background: URGENCY_DOT[item.urgency] }} />
                    {t(`work.urgency.${item.urgency}`)}
                  </span>
                  <span className="justify-self-start rounded-full bg-[var(--color-chrome-fill)] px-3 py-1 text-[0.875rem]">
                    {t(`work.action.${item.action}`)}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}
