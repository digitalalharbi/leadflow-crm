import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiDownload, apiGet } from '@/lib/api'
import { fetchCampaignQuality, fetchSalesPerformance, fetchSalesTeam } from '@/features/conversations'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, Input, PageHeader, Select, Skeleton,
  SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import {
  CampaignMatrix, CampaignQualityChart, ChartCard, ConversionFunnel, DistributionBar,
  LostReasonsChart, MetricCard, PlatformPerformanceChart, ResponseTimeChart,
  TEMPERATURE_COLOR, formatDuration,
} from '@/components/charts'
import type { DashboardData, Project, Ref, ReportRow } from '@/types/api'
import { cn } from '@/lib/cn'

const TABS = ['overview', 'campaigns', 'platforms', 'team', 'lost'] as const
type Tab = (typeof TABS)[number]

/**
 * Reports, as five questions rather than seven groupings of the same table.
 *
 * The old page offered a type switcher over one shared row shape, which meant
 * every question got the same answer in a different order. Each tab now uses
 * the visualisation its question deserves, and they all read from one filter
 * set at the top so moving between them keeps the period you were looking at.
 */
export function ReportsPage() {
  const { t } = useTranslation()
  const translateError = useApiError()

  const [tab, setTab] = useState<Tab>('overview')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [projectId, setProjectId] = useState('')
  const [platform, setPlatform] = useState('')
  const [assignedTo, setAssignedTo] = useState('')

  const filters = {
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    project_id: projectId || undefined,
    'platform[]': platform || undefined,
    assigned_to: assignedTo || undefined,
  }

  // The dashboard payload already answers the overview questions; a second
  // endpoint would be a second set of numbers to keep in agreement.
  const overview = useQuery({
    queryKey: ['reports-overview', filters],
    queryFn: () => apiGet<DashboardData>('/dashboard', filters),
    enabled: tab === 'overview',
  })

  const campaigns = useQuery({
    queryKey: ['reports-campaigns', filters],
    queryFn: () => fetchCampaignQuality(filters),
    enabled: tab === 'campaigns' || tab === 'platforms',
  })

  const performance = useQuery({
    queryKey: ['reports-performance', filters],
    queryFn: () => fetchSalesPerformance(filters),
    enabled: tab === 'overview' || tab === 'team',
  })

  const team = useQuery({
    queryKey: ['reports-team', filters],
    queryFn: () => fetchSalesTeam(filters),
    enabled: tab === 'team',
  })

  const lost = useQuery({
    queryKey: ['reports-lost', filters],
    queryFn: () =>
      apiGet<{ rows: ReportRow[]; totals: Omit<ReportRow, 'key' | 'label'> }>('/reports', {
        ...filters,
        type: 'lost_reason',
      }),
    enabled: tab === 'lost',
  })

  // The filter options ride along with whichever query is already running.
  const projects: Project[] = (overview.data?.filters.projects ?? []) as unknown as Project[]
  const owners: Ref[] = overview.data?.filters.users ?? []

  const hasFilters = Boolean(dateFrom || dateTo || projectId || platform || assignedTo)

  const clear = () => {
    setDateFrom('')
    setDateTo('')
    setProjectId('')
    setPlatform('')
    setAssignedTo('')
  }

  return (
    <>
      <PageHeader
        title={t('reports.title')}
        subtitle={t('reports.subtitle')}
        actions={
          tab === 'campaigns' ? (
            <Button
              variant="outline"
              icon={<Download className="size-4" />}
              onClick={() => void apiDownload('/reports/campaign-quality/export', filters, 'campaign-quality.csv')}
            >
              {t('reports.exportCsv')}
            </Button>
          ) : undefined
        }
      />

      <Card className="mb-4 overflow-hidden">
        {/* One filter set for every tab, so switching keeps the period. */}
        <div className="scroll-x flex items-center gap-1 border-b border-[var(--color-line)] p-2">
          {TABS.map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setTab(key)}
              data-testid={`tab-${key}`}
              aria-current={tab === key}
              className={cn(
                'rounded-[var(--radius-control)] px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap',
                'transition-colors',
                tab === key
                  ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                  : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]',
              )}
            >
              {t(`reports.tabs.${key}`)}
            </button>
          ))}
        </div>

        <div className="grid gap-2 bg-[var(--color-surface-muted)] p-3 sm:grid-cols-2 lg:grid-cols-5">
          <Input
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            aria-label={t('common.from')}
            data-testid="report-date-from"
          />
          <Input
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            aria-label={t('common.to')}
            data-testid="report-date-to"
          />
          <Select
            value={projectId}
            onChange={(e) => setProjectId(e.target.value)}
            aria-label={t('leads.project')}
            data-testid="report-project"
          >
            <option value="">{t('dashboard.allProjects')}</option>
            {projects.map((project) => (
              <option key={project.id} value={project.id}>{project.name}</option>
            ))}
          </Select>
          <Select
            value={platform}
            onChange={(e) => setPlatform(e.target.value)}
            aria-label={t('leads.platform')}
            data-testid="report-platform"
          >
            <option value="">{t('leads.platform')}</option>
            {['meta', 'snapchat', 'tiktok', 'google_ads', 'linkedin', 'website'].map((key) => (
              <option key={key} value={key}>{t(`platforms.${key}`, { defaultValue: key })}</option>
            ))}
          </Select>
          <div className="flex gap-2">
            <Select
              value={assignedTo}
              onChange={(e) => setAssignedTo(e.target.value)}
              aria-label={t('leads.assignedTo')}
              containerClassName="flex-1"
              data-testid="report-owner"
            >
              <option value="">{t('dashboard.allOwners')}</option>
              {owners.map((owner) => (
                <option key={owner.id} value={owner.id}>{owner.name}</option>
              ))}
            </Select>
            {hasFilters && (
              <Button variant="ghost" onClick={clear} data-testid="report-clear">
                {t('common.clearFilters')}
              </Button>
            )}
          </div>
        </div>
      </Card>

      {tab === 'overview' && (
        <OverviewTab query={overview} performance={performance} translateError={translateError} />
      )}

      {tab === 'campaigns' && (
        <div className="space-y-4">
          <ChartCard
            title={t('campaignQuality.title')}
            description={t('campaignQuality.subtitle')}
            height={320}
            loading={campaigns.isLoading}
            empty={(campaigns.data?.rows.length ?? 0) === 0}
            emptyMessage={t('campaignQuality.empty')}
            testId="report-campaign-quality"
          >
            <CampaignQualityChart rows={campaigns.data?.rows ?? []} limit={12} />
          </ChartCard>

          <ChartCard
            title={t('campaignQuality.matrixTitle')}
            description={t('campaignQuality.matrixHint')}
            height={280}
            loading={campaigns.isLoading}
            empty={(campaigns.data?.rows.length ?? 0) < 2}
            emptyMessage={t('campaignQuality.needMoreCampaigns')}
            testId="report-campaign-matrix"
          >
            <CampaignMatrix rows={campaigns.data?.rows ?? []} />
          </ChartCard>

          <CampaignTable rows={campaigns.data?.rows ?? []} loading={campaigns.isLoading} />
        </div>
      )}

      {tab === 'platforms' && (
        <ChartCard
          title={t('dashboard.platformsTitle')}
          description={t('dashboard.platformsHint')}
          height={400}
          loading={campaigns.isLoading}
          empty={(campaigns.data?.rows.length ?? 0) === 0}
          emptyMessage={t('campaignQuality.empty')}
          testId="report-platforms"
        >
          <PlatformPerformanceChart rows={campaigns.data?.rows ?? []} />
        </ChartCard>
      )}

      {tab === 'team' && <TeamTab query={team} performance={performance} />}

      {tab === 'lost' && (
        <ChartCard
          title={t('reports.byLostReason')}
          description={t('reports.lostHint')}
          height={340}
          loading={lost.isLoading}
          empty={(lost.data?.rows.length ?? 0) === 0}
          emptyMessage={t('reports.noLost')}
          testId="report-lost"
        >
          <LostReasonsChart rows={lost.data?.rows ?? []} />
        </ChartCard>
      )}
    </>
  )
}

/** The same shape as the dashboard, because they answer the same question. */
function OverviewTab({
  query,
  performance,
  translateError,
}: {
  query: ReturnType<typeof useQuery<DashboardData>>
  performance: ReturnType<typeof useQuery>
  translateError: (error: unknown) => string
}) {
  const { t } = useTranslation()
  const data = query.data

  if (query.isError) {
    return <Card><ErrorState message={translateError(query.error)} onRetry={() => void query.refetch()} /></Card>
  }

  if (query.isLoading || !data) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-24" />
        <Skeleton className="h-80" />
      </div>
    )
  }

  const rates = (performance.data as { rates?: Record<string, number> } | undefined)?.rates

  return (
    <div className="space-y-4">
      <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <MetricCard label={t('dashboard.totalLeads')} value={data.summary.total_leads} to="/leads" />
        <MetricCard label={t('campaignQuality.contacted')} value={data.response_time.contacted} />
        <MetricCard label={t('dashboard.qualified')} value={data.summary.qualified} to="/leads?status[]=qualified" />
        <MetricCard label={t('dashboard.hotLeads')} value={data.summary.hot_leads} tone="warning" to="/leads?temperature[]=hot" />
        <MetricCard label={t('dashboard.appointments')} value={data.summary.appointments} to="/leads?status[]=appointment" />
        <MetricCard label={t('dashboard.won')} value={data.summary.won} tone="success" to="/leads?status[]=won" />
      </section>

      {rates && (
        <Card>
          <div className="grid divide-y divide-[var(--color-line)] sm:grid-cols-3 sm:divide-y-0 lg:grid-cols-5 [&>div:not(:last-child)]:sm:border-e [&>div]:sm:border-[var(--color-line)]">
            {([
              ['contact_rate', 'campaignQuality.contactRate'],
              ['reply_rate', 'campaignQuality.replyRate'],
              ['qualification_rate', 'campaignQuality.qualificationRate'],
              ['appointment_rate', 'campaignQuality.appointmentRate'],
              ['win_rate', 'campaignQuality.winRate'],
            ] as const).map(([key, label]) => (
              <div key={key} className="px-5 py-4">
                <p className="t-metric-sm"><bdi dir="ltr">{rates[key]}%</bdi></p>
                <p className="t-caption mt-0.5">{t(label)}</p>
              </div>
            ))}
          </div>
        </Card>
      )}

      <section className="grid gap-4 lg:grid-cols-2">
        <ChartCard title={t('dashboard.funnelTitle')} description={t('dashboard.funnelHint')} height={320}>
          <ConversionFunnel stages={data.funnel} />
        </ChartCard>

        <div className="grid gap-4">
          <ChartCard title={t('dashboard.qualityTitle')} height={120}>
            <DistributionBar
              segments={data.quality.map((segment) => ({
                key: segment.key,
                label: t(`temperature.${segment.key}`),
                count: segment.count,
                share: segment.share,
                color: TEMPERATURE_COLOR[segment.key],
                to: `/leads?temperature[]=${segment.key}`,
              }))}
            />
          </ChartCard>

          <ChartCard title={t('dashboard.responseTitle')} height={160}>
            <ResponseTimeChart data={data.response_time} />
          </ChartCard>
        </div>
      </section>
    </div>
  )
}

/** Per-rep measurements. Deliberately not a league table — see the API. */
function TeamTab({
  query,
  performance,
}: {
  query: ReturnType<typeof useQuery>
  performance: ReturnType<typeof useQuery>
}) {
  const { t, i18n } = useTranslation()
  const rows = (query.data as { rows?: Array<Record<string, number | string | null>> } | undefined)?.rows ?? []
  const timing = (performance.data as { timing?: { avg_first_contact_seconds: number | null } } | undefined)?.timing

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-3">
        <div>
          <h2 className="t-card-title">{t('reports.bySalesRep')}</h2>
          <p className="t-caption mt-0.5">{t('reports.teamHint')}</p>
        </div>
        {timing?.avg_first_contact_seconds != null && (
          <p className="t-caption">
            {t('campaignQuality.avgFirstContact')}{' '}
            <span className="font-semibold text-[var(--color-ink)]">
              {formatDuration(timing.avg_first_contact_seconds, t)}
            </span>
          </p>
        )}
      </div>

      {query.isLoading ? (
        <SkeletonTable rows={5} cols={7} />
      ) : rows.length === 0 ? (
        <EmptyState icon={<BarChart3 className="size-6" />} title={t('reports.empty')} />
      ) : (
        <TableWrap>
          <Table>
            <thead>
              <tr>
                <Th>{t('users.title')}</Th>
                <Th>{t('campaignQuality.leads')}</Th>
                <Th className="hidden sm:table-cell">{t('campaignQuality.contacted')}</Th>
                <Th className="hidden md:table-cell">{t('campaignQuality.replied')}</Th>
                <Th>{t('campaignQuality.qualified')}</Th>
                <Th className="hidden sm:table-cell">{t('campaignQuality.appointments')}</Th>
                <Th>{t('campaignQuality.won')}</Th>
                <Th className="hidden lg:table-cell">{t('campaignQuality.avgFirstContact')}</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <Tr key={String(row.user_id ?? index)}>
                  <Td className="font-medium">{String(row.name)}</Td>
                  <Td className="tabular">{formatNumber(Number(row.leads), i18n.language)}</Td>
                  <Td className="tabular hidden sm:table-cell">{formatNumber(Number(row.contacted), i18n.language)}</Td>
                  <Td className="tabular hidden md:table-cell">{formatNumber(Number(row.replied), i18n.language)}</Td>
                  <Td className="tabular">
                    {formatNumber(Number(row.qualified), i18n.language)}
                    <span className="t-caption ms-1.5 text-[0.6875rem]" dir="ltr">
                      {row.qualification_rate}%
                    </span>
                  </Td>
                  <Td className="tabular hidden sm:table-cell">{formatNumber(Number(row.appointments), i18n.language)}</Td>
                  <Td className="tabular font-medium text-[var(--color-status-won)]">
                    {formatNumber(Number(row.won), i18n.language)}
                  </Td>
                  <Td className="hidden lg:table-cell">
                    {formatDuration(row.avg_first_contact_seconds as number | null, t)}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </TableWrap>
      )}
    </Card>
  )
}

function CampaignTable({ rows, loading }: { rows: Array<Record<string, never>> | unknown[]; loading: boolean }) {
  const { t, i18n } = useTranslation()
  const list = rows as Array<Record<string, string | number>>

  return (
    <Card className="overflow-hidden">
      <div className="border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="t-card-title">{t('reports.byCampaign')}</h2>
      </div>

      {loading ? (
        <SkeletonTable rows={6} cols={6} />
      ) : list.length === 0 ? (
        <EmptyState icon={<BarChart3 className="size-6" />} title={t('campaignQuality.empty')} />
      ) : (
        <TableWrap>
          <Table>
            <thead>
              <tr>
                <Th>{t('campaignQuality.campaign')}</Th>
                <Th className="hidden sm:table-cell">{t('campaignQuality.platform')}</Th>
                <Th>{t('campaignQuality.leads')}</Th>
                <Th className="hidden md:table-cell">{t('campaignQuality.replied')}</Th>
                <Th>{t('campaignQuality.qualified')}</Th>
                <Th className="hidden sm:table-cell">{t('campaignQuality.appointments')}</Th>
                <Th>{t('campaignQuality.won')}</Th>
                <Th>{t('campaignQuality.qualificationRate')}</Th>
              </tr>
            </thead>
            <tbody>
              {list.map((row) => (
                <Tr key={`${row.platform}-${row.campaign}`}>
                  <Td className="max-w-56 truncate font-medium">{row.campaign}</Td>
                  <Td className="hidden sm:table-cell">
                    {t(`platforms.${row.platform}`, { defaultValue: String(row.platform) })}
                  </Td>
                  <Td className="tabular">{formatNumber(Number(row.leads), i18n.language)}</Td>
                  <Td className="tabular hidden md:table-cell">{formatNumber(Number(row.replied), i18n.language)}</Td>
                  <Td className="tabular">{formatNumber(Number(row.qualified), i18n.language)}</Td>
                  <Td className="tabular hidden sm:table-cell">{formatNumber(Number(row.appointments), i18n.language)}</Td>
                  <Td className="tabular font-medium">{formatNumber(Number(row.won), i18n.language)}</Td>
                  <Td
                    className={cn(
                      'tabular font-semibold',
                      Number(row.qualification_rate) >= 30 && 'text-[var(--color-status-won)]',
                      Number(row.qualification_rate) < 10 && 'text-[var(--color-status-lost)]',
                    )}
                    dir="ltr"
                  >
                    {row.qualification_rate}%
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </TableWrap>
      )}
    </Card>
  )
}
