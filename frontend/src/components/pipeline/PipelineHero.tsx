import { Link } from 'react-router-dom'
import { Clock3, Flame, Hourglass, Trophy, Wallet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Skeleton } from '@/components/ui'
import { humaniseDuration } from '@/components/charts/duration'
import { Donut, KpiCard, PipelineFunnel, RankBars, StatusPill, type DonutSegment } from '@/components/visual'
import { CLASSIFICATION_COLOR, CLASSIFICATION_ORDER } from '@/components/visual/classification'
import { formatCompact, formatNumber } from '@/lib/format'
import type { PipelineOverviewData, WatchedLead } from './overview'

const OPEN_STAGES = ['new', 'contacted', 'replied', 'qualified', 'appointment', 'negotiation'] as const

/**
 * The pipeline as a picture, above the board.
 *
 * Five numbers, then the shape of the funnel, where leads sit, how good they
 * are, and the two short lists a person acts on — the hottest and the ones
 * going quiet. The board below is for moving leads; this is for knowing
 * whether the pipeline is healthy before touching it.
 */
export function PipelineHero({ data }: { data: PipelineOverviewData | undefined }) {
  const { t, i18n } = useTranslation()

  if (!data) {
    return (
      <div className="space-y-4" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-28" />)}
        </div>
        <Skeleton className="h-64" />
      </div>
    )
  }

  const { health } = data

  const stageSegments: DonutSegment[] = OPEN_STAGES.map((status) => ({
    key: status,
    label: t(`status.${status}`),
    value: data.stages.find((s) => s.status === status)?.total ?? 0,
    color: `var(--color-status-${status})`,
    href: `/leads?status[]=${status}`,
  }))

  const qualitySegments: DonutSegment[] = CLASSIFICATION_ORDER.map((key) => ({
    key,
    label: t(`classification.${key}`),
    value: data.classification.find((c) => c.key === key)?.count ?? 0,
    color: CLASSIFICATION_COLOR[key],
    href: `/leads?classification[]=${key}`,
  }))

  return (
    <div className="space-y-4" data-testid="pipeline-hero">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <KpiCard
          label={t('pipelineBoard.openValue')}
          icon={Wallet}
          value={formatCompact(health.open_value, i18n.language)}
          valueTestId="kpi-open-value"
        />
        <KpiCard
          label={t('pipelineBoard.hot')}
          icon={Flame}
          tone="bad"
          value={formatNumber(health.hot, i18n.language)}
          to="/leads?temperature[]=hot"
          valueTestId="kpi-hot"
        />
        <KpiCard
          label={t('pipelineBoard.stalled')}
          icon={Hourglass}
          tone={health.stalled > 0 ? 'warn' : 'good'}
          value={formatNumber(health.stalled, i18n.language)}
          valueTestId="kpi-stalled"
        />
        <KpiCard
          label={t('pipelineBoard.response')}
          icon={Clock3}
          tone="neutral"
          value={health.avg_response_seconds === null ? '—' : humaniseDuration(health.avg_response_seconds, t)}
          valueTestId="kpi-response"
        />
        <KpiCard
          label={t('pipelineBoard.winRate')}
          icon={Trophy}
          tone="good"
          value={health.win_rate === null ? '—' : <span dir="ltr">{health.win_rate}%</span>}
          valueTestId="kpi-win-rate"
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-12">
        <Card className="lg:col-span-5">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.funnel')}</h2>
            <PipelineFunnel
              testId="pipeline-funnel"
              steps={data.funnel.map((step) => ({
                key: step.key,
                label: t(`status.${step.key}`),
                count: step.count,
                href: `/leads?status[]=${step.key}`,
              }))}
            />
          </CardBody>
        </Card>

        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.stages')}</h2>
            <Donut
              size={132}
              thickness={16}
              legend="side"
              centerValue={formatNumber(health.open, i18n.language)}
              centerLabel={t('pipelineBoard.open')}
              segments={stageSegments}
              testId="stage-donut"
            />
          </CardBody>
        </Card>

        <Card className="lg:col-span-3">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.quality')}</h2>
            <Donut
              size={132}
              thickness={16}
              legend="below"
              centerValue={formatNumber(health.open, i18n.language)}
              segments={qualitySegments}
              testId="quality-donut"
            />
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <WatchCard title={t('pipelineBoard.hotList')} leads={data.hot} testId="watch-hot" />
        <WatchCard title={t('pipelineBoard.stalledList')} leads={data.stalled} stalled testId="watch-stalled" />
        <Card>
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.team')}</h2>
            <RankBars
              testId="team-workload"
              items={data.workload.map((row) => ({
                key: row.user_id ?? 'none',
                label: row.user_id ? row.name : t('pipelineBoard.unassigned'),
                value: row.open,
                note: row.stalled > 0 ? (
                  <span className="inline-flex items-center gap-0.5 text-[var(--color-warning)]" title={t('pipelineBoard.stalled')}>
                    <Hourglass className="size-3" aria-hidden />
                    {row.stalled}
                  </span>
                ) : undefined,
              }))}
            />
          </CardBody>
        </Card>
      </div>
    </div>
  )
}

function WatchCard({ title, leads, stalled = false, testId }: { title: string; leads: WatchedLead[]; stalled?: boolean; testId: string }) {
  const { t } = useTranslation()

  return (
    <Card>
      <CardBody>
        <h2 className="t-card-title mb-3">{title}</h2>
        {leads.length === 0 ? (
          <p className="py-4 text-center text-[0.9375rem] text-[var(--color-ink-subtle)]">{t('pipelineBoard.noneHot')}</p>
        ) : (
          <ul className="space-y-1" data-testid={testId}>
            {leads.map((lead) => (
              <li key={lead.id}>
                <Link
                  to={`/leads/${lead.id}`}
                  className="flex items-center gap-2.5 rounded-[var(--radius-control)] px-1.5 py-1.5 hover:bg-[var(--color-surface-muted)]"
                >
                  <span
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ background: CLASSIFICATION_COLOR[lead.classification] }}
                    aria-hidden
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[0.9375rem] font-medium">{lead.name}</span>
                    {lead.project && <span className="block truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{lead.project}</span>}
                  </span>
                  {stalled ? (
                    <StatusPill status="overdue" iconOnly />
                  ) : (
                    <span className="text-[0.875rem] font-semibold tabular-nums text-[var(--color-ink-muted)]">{lead.score}</span>
                  )}
                  {stalled && (
                    <span className="text-[0.8125rem] tabular-nums text-[var(--color-ink-muted)]">{t('pipelineBoard.idleDays', { count: lead.idle_days })}</span>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
