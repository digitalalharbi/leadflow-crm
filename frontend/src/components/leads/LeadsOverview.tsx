import { useQuery } from '@tanstack/react-query'
import { Clock3, Flame, Target, TrendingUp, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { Card, CardBody, Skeleton } from '@/components/ui'
import { humaniseDuration } from '@/components/charts/duration'
import { platformColor } from '@/components/charts'
import { AreaTrend, Donut, KpiCard, type DonutSegment } from '@/components/visual'
import { CLASSIFICATION_COLOR, CLASSIFICATION_ORDER } from '@/components/visual/classification'
import { formatNumber } from '@/lib/format'
import type { ClassificationKey } from '@/types/api'

interface LeadsOverviewData {
  health: {
    total: number
    hot: number
    qualified: number
    qualified_rate: number | null
    win_rate: number | null
    avg_response_seconds: number | null
  }
  classification: { key: ClassificationKey; count: number }[]
  sources: { key: string; count: number }[]
  trend: { date: string; count: number }[]
}

/**
 * The list, seen from above.
 *
 * Five numbers, where the leads came from, how good they are, and how many a
 * day — before a single row. It takes the same filters as the table beneath it,
 * so picking a project or a date range redraws both, and the table is what you
 * reach for to act on what the picture shows.
 */
export function LeadsOverview({ query }: { query: Record<string, unknown> }) {
  const { t, i18n } = useTranslation()

  // Only the filters that describe *which* leads, never page or sort.
  const { page: _page, per_page: _perPage, sort: _sort, direction: _direction, ...filters } = query

  const { data } = useQuery({
    queryKey: ['leads', 'overview', filters],
    queryFn: () => apiGet<LeadsOverviewData>('/leads/overview', filters),
  })

  if (!data) {
    return (
      <div className="space-y-3" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-28" />)}
        </div>
        <Skeleton className="h-44" />
      </div>
    )
  }

  const { health } = data
  const percent = (value: number | null) => (value === null ? '—' : <span dir="ltr">{value}%</span>)

  const sourceSegments: DonutSegment[] = data.sources.map((source) => ({
    key: source.key,
    label: source.key === 'other' ? t('leadsOverview.other') : t(`platforms.${source.key}`, { defaultValue: source.key }),
    value: source.count,
    color: platformColor(source.key),
  }))

  const qualitySegments: DonutSegment[] = CLASSIFICATION_ORDER.map((key) => ({
    key,
    label: t(`classification.${key}`),
    value: data.classification.find((c) => c.key === key)?.count ?? 0,
    color: CLASSIFICATION_COLOR[key],
    href: `/leads?classification[]=${key}`,
  }))

  return (
    <div className="mb-3 space-y-3" data-testid="leads-overview">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <KpiCard label={t('leadsOverview.total')} icon={Users} value={formatNumber(health.total, i18n.language)} valueTestId="lk-total" />
        <KpiCard label={t('leadsOverview.hot')} icon={Flame} tone="bad" value={formatNumber(health.hot, i18n.language)} valueTestId="lk-hot" />
        <KpiCard label={t('leadsOverview.qualified')} icon={Target} tone="good" value={percent(health.qualified_rate)} valueTestId="lk-qualified" />
        <KpiCard
          label={t('leadsOverview.response')}
          icon={Clock3}
          tone="neutral"
          value={health.avg_response_seconds === null ? '—' : humaniseDuration(health.avg_response_seconds, t)}
          valueTestId="lk-response"
        />
        <KpiCard label={t('leadsOverview.conversion')} icon={TrendingUp} tone="good" value={percent(health.win_rate)} valueTestId="lk-conversion" />
      </div>

      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('leadsOverview.sources')}</h2>
            <Donut size={112} thickness={14} legend="side" centerValue={formatNumber(health.total, i18n.language)} segments={sourceSegments} testId="source-donut" />
          </CardBody>
        </Card>
        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.quality')}</h2>
            <Donut size={112} thickness={14} legend="side" centerValue={formatNumber(health.total, i18n.language)} segments={qualitySegments} testId="lead-quality-donut" />
          </CardBody>
        </Card>
        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('leadsOverview.trend')}</h2>
            <AreaTrend
              testId="lead-trend"
              height={200}
              axes
              valueLabel={t('leadsOverview.trend')}
              points={data.trend.map((p) => ({
                label: new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' }).format(new Date(p.date)),
                value: p.count,
              }))}
            />
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
