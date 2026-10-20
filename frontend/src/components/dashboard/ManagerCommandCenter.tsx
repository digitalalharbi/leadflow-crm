import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BadgeCheck, BellRing, CalendarCheck, Clock, Coins, LifeBuoy, Snowflake, Sparkles, Target, TrendingUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Skeleton } from '@/components/ui'
import { platformColor, TEMPERATURE_COLOR } from '@/components/charts'
import { AreaTrend, Donut, KpiCard, PipelineFunnel, RankBars, RingProgress } from '@/components/visual'
import { InterestBadge } from '@/components/visual/InterestBadge'
import { fetchManager, type ManagerData } from '@/features/manager'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const PERIODS = [7, 30, 90] as const

/**
 * The manager's screen, in one glance: nine figures, the SLA ring, the funnel,
 * who is carrying what, where leads come from, and the last weeks as a line.
 * Every tile links to the list it counts.
 */
export function ManagerCommandCenter() {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState<number>(30)

  const { data } = useQuery({ queryKey: ['manager-center', days], queryFn: () => fetchManager(days), refetchInterval: 120_000 })

  const duration = (seconds: number | null) => {
    if (seconds === null) return '—'
    if (seconds < 90) return `${seconds}${t('manager.unit.s')}`
    if (seconds < 5400) return `${Math.round(seconds / 60)}${t('manager.unit.m')}`
    if (seconds < 129_600) return `${Math.round(seconds / 3600)}${t('manager.unit.h')}`

    return `${Math.round(seconds / 86_400)}${t('manager.unit.d')}`
  }

  return (
    <section data-testid="manager-center" aria-label={t('manager.title')} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-[1.25rem] font-extrabold">{t('manager.title')}</h2>
        <div role="group" aria-label={t('manager.period')} className="inline-flex gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1">
          {PERIODS.map((period) => (
            <button
              key={period}
              type="button"
              aria-pressed={days === period}
              data-testid={`manager-period-${period}`}
              onClick={() => setDays(period)}
              className={cn('pressable rounded-full px-3 py-1 text-[0.9375rem] font-semibold', days === period ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]')}
            >
              {t('dashboard.lastDays', { count: period })}
            </button>
          ))}
        </div>
      </div>

      {!data ? (
        // As tall as what replaces it, so the page below does not jump when it arrives.
        <Skeleton className="h-[88rem] lg:h-[64rem]" />
      ) : (
        <>
          <ManagerTiles data={data} duration={duration} money={(v) => formatMoneyCompact(v, 'SAR', i18n.language)} />

          <div className="grid gap-3 lg:grid-cols-12">
            <SlaCard data={data} duration={duration} />

            <Card className="lg:col-span-4" data-testid="manager-funnel">
              <CardBody>
                <h3 className="t-card-title mb-3">{t('manager.funnel')}</h3>
                <PipelineFunnel steps={data.funnel.map((step) => ({ key: step.key, label: t(`campaigns.stage.${step.key}`), count: step.count }))} />
              </CardBody>
            </Card>

            <Card className="lg:col-span-4" data-testid="manager-team">
              <CardBody>
                <h3 className="t-card-title mb-3">{t('manager.team')}</h3>
                {data.team.length === 0 ? (
                  <p className="t-caption">{t('manager.noTeam')}</p>
                ) : (
                  <RankBars
                    limit={6}
                    items={data.team.map((member) => ({
                      key: member.id,
                      label: member.name,
                      value: member.won,
                      note: (
                        <span className="tabular-nums" dir="ltr">
                          {member.open} {t('manager.open')}
                          {member.overdue > 0 && <span className="ms-1.5 font-bold text-[var(--color-danger)]">· {member.overdue} {t('manager.late')}</span>}
                          {(member.sla_late ?? 0) > 0 && <span className="ms-1.5 font-bold text-[var(--color-danger)]" data-testid={`team-sla-late-${member.id}`}>· {member.sla_late} {t('manager.slaLate')}</span>}
                        </span>
                      ),
                    }))}
                  />
                )}
              </CardBody>
            </Card>
          </div>

          {data.interest && <InterestWaitingRow interest={data.interest} />}

          <AutomationRow data={data} duration={duration} money={(v) => formatMoneyCompact(v, 'SAR', i18n.language)} />

          <div className="grid gap-3 lg:grid-cols-12">
            <Card className="lg:col-span-4" data-testid="manager-temperature">
              <CardBody>
                <h3 className="t-card-title mb-3">{t('manager.temperature')}</h3>
                <Donut
                  size={132}
                  thickness={16}
                  legend="side"
                  segments={data.temperature.map((s) => ({ key: s.key, label: t(`temperature.${s.key}`), value: s.count, color: TEMPERATURE_COLOR[s.key], href: `/leads?temperature[]=${s.key}` }))}
                />
              </CardBody>
            </Card>

            <Card className="lg:col-span-4" data-testid="manager-sources">
              <CardBody>
                <h3 className="t-card-title mb-3">{t('manager.sources')}</h3>
                <Donut
                  size={132}
                  thickness={16}
                  legend="side"
                  segments={data.sources.map((s) => ({ key: s.key, label: t(`platforms.${s.key}`, { defaultValue: s.key }), value: s.count, color: platformColor(s.key) }))}
                />
              </CardBody>
            </Card>

            <Card className="lg:col-span-4" data-testid="manager-trend">
              <CardBody>
                <h3 className="t-card-title mb-1">{t('manager.trend')}</h3>
                <AreaTrend
                  height={132}
                  axes
                  valueLabel={t('manager.leadsPerDay')}
                  points={data.trend.map((point) => ({ label: point.date.slice(5), value: point.leads }))}
                />
              </CardBody>
            </Card>
          </div>
        </>
      )}
    </section>
  )
}

function ManagerTiles({
  data, duration, money,
}: { data: ManagerData; duration: (s: number | null) => string; money: (v: number) => string }) {
  const { t, i18n } = useTranslation()
  const k = data.kpis
  const pct = (v: number | null) => (v === null ? '—' : `${formatNumber(v, i18n.language)}%`)
  const trend = (key: 'leads' | 'qualified' | 'won') => data.trend.map((point) => point[key])

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" data-testid="manager-kpis">
      <KpiCard testId="kpi-leads-today" valueTestId="kpi-leads-today-value" label={t('manager.kpi.leadsToday')} value={formatNumber(k.leads_today, i18n.language)} icon={Sparkles} spark={trend('leads')} to="/leads?view=new_today" />
      <KpiCard testId="kpi-qualified" valueTestId="kpi-qualified-value" label={t('manager.kpi.qualified')} value={formatNumber(k.qualified, i18n.language)} icon={BadgeCheck} spark={trend('qualified')} to="/leads?view=qualified" />
      <KpiCard testId="kpi-overdue" valueTestId="kpi-overdue-value" label={t('manager.kpi.overdue')} value={formatNumber(k.overdue_follow_ups, i18n.language)} icon={BellRing} tone={k.overdue_follow_ups > 0 ? 'bad' : 'good'} to="/leads?overdue=1" />
      <KpiCard testId="kpi-stalled" valueTestId="kpi-stalled-value" label={t('manager.kpi.stalled')} value={formatNumber(k.stalled, i18n.language)} icon={Snowflake} tone={k.stalled > 0 ? 'warn' : 'good'} to="/leads?view=stalled" />
      <KpiCard testId="kpi-response" valueTestId="kpi-response-value" label={t('manager.kpi.response')} value={duration(k.avg_response_seconds)} icon={Clock} />
      <KpiCard testId="kpi-appointments" valueTestId="kpi-appointments-value" label={t('manager.kpi.appointmentRate')} value={pct(k.appointment_rate)} icon={CalendarCheck} />
      <KpiCard testId="kpi-conversion" valueTestId="kpi-conversion-value" label={t('manager.kpi.conversion')} value={pct(k.conversion_rate)} icon={TrendingUp} spark={trend('won')} />
      <KpiCard testId="kpi-revenue" valueTestId="kpi-revenue-value" label={t('manager.kpi.revenue')} value={money(k.revenue)} icon={Coins} spark={trend('won')} />
      <KpiCard testId="kpi-period" valueTestId="kpi-period-value" label={t('manager.kpi.period', { days: data.days })} value={formatNumber(k.leads_period, i18n.language)} icon={Target} spark={trend('leads')} />
    </div>
  )
}

/** The response promise: a ring for how many were reached in time, and the numbers beside it. */
function SlaCard({ data, duration }: { data: ManagerData; duration: (s: number | null) => string }) {
  const { t } = useTranslation()
  const sla = data.sla
  const percent = sla.compliance.percent

  return (
    <Card className="lg:col-span-4" data-testid="manager-sla">
      <CardBody className="space-y-3">
        <h3 className="t-card-title">{t('manager.sla.title', { minutes: sla.target_minutes })}</h3>
        <div className="flex items-center gap-4">
          <RingProgress value={percent ?? 0} size={96} thickness={10} tone={percent === null ? 'neutral' : percent >= 80 ? 'good' : percent >= 50 ? 'warn' : 'bad'} label={t('manager.sla.ring')} testId="sla-ring">
            <span className="text-[1.375rem] font-extrabold tabular-nums" data-testid="sla-percent">{percent === null ? '—' : `${percent}%`}</span>
          </RingProgress>
          <dl className="grid flex-1 grid-cols-2 gap-x-3 gap-y-1.5 text-[0.9375rem]">
            <dt className="text-[var(--color-ink-muted)]">{t('manager.sla.overdue')}</dt>
            <dd className="text-end font-bold tabular-nums" data-testid="sla-overdue">{sla.overdue_follow_ups}</dd>
            <dt className="text-[var(--color-ink-muted)]">{t('manager.sla.breached')}</dt>
            <dd className="text-end font-bold tabular-nums" data-testid="sla-breached">{sla.breached_first_contact}</dd>
            <dt className="text-[var(--color-ink-muted)]">{t('manager.sla.automated')}</dt>
            <dd className="text-end font-bold tabular-nums">{duration(sla.first_automated.median_seconds)}</dd>
            <dt className="text-[var(--color-ink-muted)]">{t('manager.sla.human')}</dt>
            <dd className="text-end font-bold tabular-nums">{duration(sla.first_human.median_seconds)}</dd>
          </dl>
        </div>

        <div data-testid="sla-stalled">
          <p className="mb-1 flex items-center justify-between text-[0.9375rem] font-semibold">
            <span>{t('manager.sla.stalled')}</span>
            <Link to="/leads?view=stalled" className="rounded-full bg-[color-mix(in_srgb,var(--color-warning)_18%,transparent)] px-2 tabular-nums text-[var(--color-warning)]">{sla.stalled.count}</Link>
          </p>
          <ul className="space-y-0.5">
            {sla.stalled.leads.slice(0, 3).map((lead) => (
              <li key={lead.id} className="flex items-center justify-between gap-2 text-[0.9375rem]">
                <Link to={`/leads/${lead.id}`} className="min-w-0 truncate hover:underline">{lead.name}</Link>
                <span className="shrink-0 text-[var(--color-ink-subtle)] tabular-nums">{t('intel.days', { count: lead.days })}</span>
              </li>
            ))}
          </ul>
        </div>
      </CardBody>
    </Card>
  )
}

/** Higher is better for coverage; lower is better for human work and for silent losses. */
const toneUp = (v: number | null) => (v === null ? 'neutral' : v >= 70 ? 'good' : v >= 40 ? 'warn' : 'bad') as 'good' | 'warn' | 'bad' | 'neutral'
const toneDown = (v: number | null) => (v === null ? 'neutral' : v <= 30 ? 'good' : v <= 60 ? 'warn' : 'bad') as 'good' | 'warn' | 'bad' | 'neutral'

/**
 * What automation is doing for the team: how many leads it reached, how many still needed a
 * person, how fast they qualify, what it rescued, what was lost to silence — and what each
 * kind of lead is worth. Rings first, numbers second.
 */
function AutomationRow({ data, duration, money }: { data: ManagerData; duration: (s: number | null) => string; money: (v: number) => string }) {
  const { t, i18n } = useTranslation()
  const a = data.automation
  const pct = (v: number | null) => (v === null ? '—' : `${formatNumber(v, i18n.language)}%`)
  const revenue = a.revenue_by_quality.filter((row) => row.revenue > 0)
  const COLOR: Record<string, string> = { ...TEMPERATURE_COLOR, unrated: 'var(--color-ink-subtle)' }

  const ring = (key: string, value: number | null, tone: 'good' | 'warn' | 'bad' | 'neutral', label: string, hint?: string) => (
    <div className="flex flex-col items-center gap-1 text-center" data-testid={`automation-${key}`} title={hint}>
      <RingProgress value={value ?? 0} size={84} thickness={9} tone={tone} label={label} testId={`ring-${key}`}>
        <span className="text-[1.125rem] font-extrabold tabular-nums" data-testid={`ring-${key}-value`}>{pct(value)}</span>
      </RingProgress>
      <span className="text-[0.9375rem] font-semibold">{label}</span>
    </div>
  )

  return (
    <div className="grid gap-3 lg:grid-cols-12">
      <Card className="lg:col-span-8" data-testid="manager-automation">
        <CardBody className="space-y-3">
          <h3 className="t-card-title">{t('manager.automation.title')}</h3>

          <div className="grid grid-cols-2 items-start gap-3 sm:grid-cols-4">
            {ring('coverage', a.coverage_percent, toneUp(a.coverage_percent), t('manager.automation.coverage'))}
            {ring('human', a.human_percent, toneDown(a.human_percent), t('manager.automation.human'))}
            {ring('lost', a.lost.percent, toneDown(a.lost.percent), t('manager.automation.lost'), t('manager.automation.lostHint', { count: a.lost.no_response, total: a.lost.total }))}
            <div className="flex flex-col items-center gap-1 text-center" data-testid="automation-qualify">
              <span className="grid size-[84px] place-items-center rounded-full bg-[var(--color-surface-muted)]">
                <span className="text-[1.25rem] font-extrabold tabular-nums" data-testid="automation-qualify-value">{duration(a.avg_seconds_to_qualify)}</span>
              </span>
              <span className="text-[0.9375rem] font-semibold">{t('manager.automation.qualify')}</span>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2" data-testid="automation-recovered">
            <LifeBuoy className="size-5 text-[var(--color-success)]" aria-hidden />
            <span className="text-[1rem] font-semibold">{t('manager.automation.recovered')}</span>
            <span className="rounded-full bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] px-2.5 py-0.5 text-[1rem] font-extrabold tabular-nums text-[var(--color-success)]" data-testid="automation-recovered-value">{formatNumber(a.recovered, i18n.language)}</span>
            {a.needs_human > 0 && (
              <span className="text-[0.9375rem] text-[var(--color-ink-muted)]" data-testid="automation-needs-human">· {t('manager.automation.needsHuman', { count: a.needs_human })}</span>
            )}
          </div>

          <div data-testid="automation-trend">
            <AreaTrend
              height={96}
              axes
              valueLabel={t('manager.automation.trend')}
              points={a.trend.map((point) => ({ label: point.date.slice(5), value: point.automated }))}
            />
          </div>
        </CardBody>
      </Card>

      <Card className="lg:col-span-4" data-testid="manager-revenue-quality">
        <CardBody>
          <h3 className="t-card-title mb-3">{t('manager.automation.revenueByQuality')}</h3>
          {revenue.length === 0 ? (
            <p className="t-caption" data-testid="revenue-quality-empty">{t('manager.automation.noRevenue')}</p>
          ) : (
            <Donut
              size={132}
              thickness={16}
              legend="side"
              segments={revenue.map((row) => ({
                key: row.key,
                label: `${row.key === 'unrated' ? t('manager.automation.unrated') : t(`temperature.${row.key}`)} · ${money(row.revenue)}`,
                value: row.revenue,
                color: COLOR[row.key],
              }))}
            />
          )}
        </CardBody>
      </Card>
    </div>
  )
}

/**
 * Interested leads right now, by how far along they are — and how many are waiting longer than
 * their window. The ring is the share still inside it: a manager reads one shape, not a table.
 */
function InterestWaitingRow({ interest }: { interest: NonNullable<ManagerData['interest']> }) {
  const { t } = useTranslation()
  const total = Object.values(interest.levels).reduce((sum, n) => sum + n, 0)
  const onTime = total > 0 ? Math.round(((total - interest.late) / total) * 100) : 100

  return (
    <Card data-testid="manager-interest">
      <CardBody className="flex flex-wrap items-center gap-x-5 gap-y-3">
        <div className="flex items-center gap-3">
          <RingProgress value={onTime} size={56} thickness={6} tone={interest.late > 0 ? 'bad' : 'good'} label={t('manager.interestTitle')} testId="manager-interest-ring">
            <span className="text-[0.875rem] font-extrabold tabular-nums" dir="ltr">{onTime}%</span>
          </RingProgress>
          <div className="leading-tight">
            <h3 className="t-card-title">{t('manager.interestTitle')}</h3>
            <p className="t-caption tabular-nums">
              <span className={cn('font-bold', interest.late > 0 && 'text-[var(--color-danger)]')} data-testid="manager-interest-late">{interest.late}</span> {t('manager.interestLate')}
              {interest.due_soon > 0 && <> · <span className="font-bold text-[var(--color-warning)]">{interest.due_soon}</span> {t('manager.interestDueSoon')}</>}
            </p>
          </div>
        </div>
        <ul className="flex flex-wrap gap-2">
          {(['reservation_ready', 'visit_ready', 'hot', 'interested'] as const).map((level) => (
            <li key={level} className="flex items-center gap-1.5">
              <InterestBadge level={level} testId={`manager-interest-${level}`} />
              <span className="text-[1.0625rem] font-bold tabular-nums">{interest.levels[level]}</span>
            </li>
          ))}
        </ul>
      </CardBody>
    </Card>
  )
}
