import {
  AlertTriangle,
  CalendarCheck,
  CircleSlash,
  Repeat,
  Send,
  ShieldCheck,
  UserRound,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import {
  AreaTrend,
  Donut,
  PipelineFunnel,
  RingProgress,
  type DonutSegment,
} from '@/components/visual'
import type { AutomationSummary, HistoryResult } from '@/features/automation'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

type CardKey = keyof AutomationSummary['cards']

/**
 * Eight numbers and what they mean next to last period. Each one opens the
 * rows behind it — the Action History filtered to that result, or the list of
 * rules nobody is using — so a number is never a dead end.
 */
type Drill = HistoryResult | 'rules' | 'leads' | ''

const CARDS: Array<{
  key: CardKey
  icon: LucideIcon
  tone: 'brand' | 'warn' | 'bad' | 'neutral'
  drill: Drill
}> = [
  { key: 'all_actions', icon: Zap, tone: 'brand', drill: '' },
  { key: 'auto_contacted', icon: Send, tone: 'brand', drill: 'completed' },
  { key: 'follow_ups', icon: Repeat, tone: 'brand', drill: 'completed' },
  { key: 'qualified', icon: ShieldCheck, tone: 'brand', drill: 'leads' },
  { key: 'appointments', icon: CalendarCheck, tone: 'brand', drill: 'leads' },
  { key: 'needs_human', icon: UserRound, tone: 'warn', drill: 'needs_human' },
  { key: 'failed', icon: AlertTriangle, tone: 'bad', drill: 'failed' },
  { key: 'inactive_rules', icon: CircleSlash, tone: 'neutral', drill: 'rules' },
]

const TINT = {
  brand: 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]',
  warn: 'bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]',
  bad: 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
  neutral: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

export function CentreSummary({
  data,
  onDrill,
}: {
  data: AutomationSummary
  onDrill: (target: Drill) => void
}) {
  const { t, i18n } = useTranslation()

  const day = (date: string) =>
    new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', {
      day: 'numeric',
      month: 'short',
    }).format(new Date(date))

  const qualification: DonutSegment[] = [
    {
      key: 'qualified',
      label: t('qualification.status.qualified'),
      value: data.qualification.qualified ?? 0,
      color: 'var(--brand)',
    },
    {
      key: 'partially_qualified',
      label: t('qualification.status.partially_qualified'),
      value: data.qualification.partially_qualified ?? 0,
      color: 'var(--color-warning)',
    },
    {
      key: 'unqualified',
      label: t('qualification.status.unqualified'),
      value: data.qualification.unqualified ?? 0,
      color: 'var(--color-ink-subtle)',
    },
  ]

  return (
    <div className="space-y-3" data-testid="automation-kpis">
      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-3">
          <CardBody
            className="flex h-full flex-col items-center justify-center gap-2 text-center"
            data-testid="automation-success"
          >
            <h2 className="t-card-title self-start">{t('automation.chart.success')}</h2>
            <RingProgress
              value={data.success_rate ?? 0}
              size={128}
              thickness={12}
              tone={(data.success_rate ?? 100) >= 90 ? 'good' : 'warn'}
              label={t('automation.chart.success')}
            >
              <span className="text-[1.75rem] font-extrabold tabular-nums" dir="ltr">
                {data.success_rate === null ? '—' : `${data.success_rate}%`}
              </span>
            </RingProgress>
            <p className="t-caption">{t('automation.chart.successHint')}</p>
          </CardBody>
        </Card>

        <Card className="lg:col-span-6">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('automation.chart.qualification')}</h2>
            <Donut
              size={124}
              thickness={14}
              legend="side"
              segments={qualification}
              testId="automation-qualification"
            />
          </CardBody>
        </Card>

        {/* What did not work, as large as what did: a failure is the number to act on. */}
        <Card className="lg:col-span-3">
          <button
            type="button"
            onClick={() => onDrill('failed')}
            className="flex h-full w-full flex-col items-center justify-center gap-2 p-5 text-center"
            data-testid="automation-failed"
          >
            <h2 className="t-card-title self-start">{t('automation.chart.failed')}</h2>
            <RingProgress
              value={
                data.cards.all_actions.value > 0
                  ? Math.round((data.cards.failed.value / data.cards.all_actions.value) * 100)
                  : 0
              }
              size={128}
              thickness={12}
              tone={data.cards.failed.value > 0 ? 'bad' : 'good'}
              label={t('automation.chart.failed')}
            >
              <span
                className={cn(
                  'text-[1.75rem] font-extrabold tabular-nums',
                  data.cards.failed.value > 0 && 'text-[var(--color-danger)]',
                )}
              >
                {formatNumber(data.cards.failed.value, i18n.language)}
              </span>
            </RingProgress>
            <p className="t-caption">{t('automation.chart.failedHint')}</p>
          </button>
        </Card>
      </div>

      <div className="grid gap-3 lg:grid-cols-3">
        <Card>
          <CardBody>
            <div className="mb-2 flex items-center justify-between gap-2">
              <h2 className="t-card-title">{t('automation.chart.humanVsAuto')}</h2>
              <span className="flex items-center gap-3 text-[0.6875rem] text-[var(--color-ink-muted)]">
                <span className="flex items-center gap-1">
                  <span className="h-0.5 w-3 rounded bg-[var(--brand)]" />
                  {t('automation.chart.automated')}
                </span>
                <span className="flex items-center gap-1">
                  <span className="h-0.5 w-3 border-t border-dashed border-[var(--color-ink-subtle)]" />
                  {t('automation.chart.human')}
                </span>
              </span>
            </div>
            <AreaTrend
              testId="automation-human-vs-auto"
              height={260}
              axes
              valueLabel={t('automation.chart.automated')}
              previousLabel={t('automation.chart.human')}
              points={data.human_vs_automated.map((p) => ({
                label: day(p.date),
                value: p.automated,
                previous: p.human,
              }))}
            />
          </CardBody>
        </Card>

        <Card>
          <CardBody>
            <h2 className="t-card-title mb-2">{t('automation.chart.followUps')}</h2>
            <AreaTrend
              testId="automation-follow-ups"
              height={260}
              axes
              valueLabel={t('automation.chart.followUps')}
              points={data.follow_up_trend.map((p) => ({ label: day(p.date), value: p.value }))}
            />
          </CardBody>
        </Card>
        {/*
          The point of the whole product, as one picture: everything that
          arrived, and how few of them needed a person.
        */}
        <Card>
          <CardBody>
            <h2 className="t-card-title mb-1">{t('automation.chart.funnel')}</h2>
            <p className="t-caption mb-3">{t('automation.chart.funnelHint')}</p>
            <PipelineFunnel
              testId="automation-funnel"
              steps={data.funnel.map((step) => ({
                key: step.key,
                label: t(`automation.funnel.${step.key}`),
                count: step.count,
              }))}
            />
          </CardBody>
        </Card>
      </div>

      <div className="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-8">
        {CARDS.map(({ key, icon: Icon, tone, drill }) => {
          const card = data.cards[key]
          const up = (card.change ?? 0) > 0
          // More failures or more people needed is the wrong direction.
          const bad = key === 'failed' || key === 'needs_human' ? up : (card.change ?? 0) < 0

          return (
            <button
              key={key}
              type="button"
              onClick={() => onDrill(drill)}
              className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 text-start shadow-[var(--shadow-card)] transition-shadow hover:shadow-[var(--shadow-raised)]"
              data-testid={`card-${key}`}
            >
              <div className="flex items-start justify-between gap-2">
                <span
                  className={cn('grid size-8 place-items-center rounded-full', TINT[tone])}
                  aria-hidden
                >
                  <Icon className="size-4" />
                </span>
                {card.change !== null && card.change !== 0 && (
                  <span
                    dir="ltr"
                    className={cn(
                      'rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold tabular-nums',
                      bad
                        ? 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]'
                        : 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]',
                    )}
                  >
                    {up ? '+' : ''}
                    {card.change}
                  </span>
                )}
              </div>
              <p className="mt-2 truncate text-[0.75rem] text-[var(--color-ink-muted)]">
                {t(`automation.card.${key}`)}
              </p>
              <p className="text-[1.625rem] font-extrabold leading-tight tabular-nums">
                {formatNumber(card.value, i18n.language)}
              </p>
              {card.previous !== null && (
                <p className="truncate text-[0.6875rem] text-[var(--color-ink-subtle)] tabular-nums">
                  {t('automation.card.previous', { count: card.previous })}
                </p>
              )}
            </button>
          )
        })}
      </div>
    </div>
  )
}
