import { Link, useSearchParams } from 'react-router-dom'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, CalendarCheck, Download, Flame, PhoneCall, ShieldAlert, Target, Trophy, Users } from 'lucide-react'
import { KpiCard, RingProgress } from '@/components/visual'
import { useTranslation } from 'react-i18next'
import { useEntitled } from '@/stores/auth'
import { apiDownload, apiGet } from '@/lib/api'
import { fetchCampaignQuality, fetchSalesPerformance, fetchSalesTeam } from '@/features/conversations'
import { fetchDrivers, type Drivers } from '@/features/drivers'
import { MarketingSalesTab } from '@/components/reports/MarketingSalesTab'
import { WeeklyReportsTab } from '@/components/reports/WeeklyReportsTab'
import { SalesIntelligence } from '@/components/reports/SalesIntelligence'
import { ShareTab } from '@/components/reports/ShareTab'
import { QualityTab } from '@/components/reports/QualityTab'
import { SpendModal } from '@/components/reports/SpendModal'
import { InsightStrip } from '@/components/reports/InsightStrip'
import { useApiError } from '@/hooks/useApiError'
import { formatCurrency, formatDate, formatNumber } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, Input, PageHeader, Select, Skeleton,
  SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import {
  CampaignMatrix, CampaignQualityChart, ChartCard, ConversionDriversChart, ConversionFunnel,
  DistributionBar, TeamComparisonChart, type TeamRow,
  LostReasonsChart, PlatformPerformanceChart, ResponseTimeChart,
  TEMPERATURE_COLOR, formatDuration,
} from '@/components/charts'
import type { CampaignQualityRow, DashboardData, Project, Ref, ReportRow } from '@/types/api'
import { cn } from '@/lib/cn'

const TABS = ['overview', 'marketing', 'quality', 'campaigns', 'platforms', 'team', 'lost', 'drivers', 'weekly', 'share'] as const
type Tab = (typeof TABS)[number]

/** The analyses that come with the advanced-reports feature; the server refuses their data on a plan without it. */
const ADVANCED: readonly Tab[] = ['quality', 'campaigns', 'platforms', 'drivers', 'share']

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

  const [params] = useSearchParams()
  // `?tab=share&new=1` is where the command palette's "create a report" lands: the builder is already open.
  const advanced = useEntitled('advanced_reports')
  const exportsOn = useEntitled('exports')
  const visibleTabs = TABS.filter((key) => advanced || !ADVANCED.includes(key))
  const [tab, setTab] = useState<Tab>(() => (visibleTabs as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview')
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

  /*
   * The feedback half of the reports: a behaviour on one axis, the outcome on
   * the other. It honours the same filters as the rest, so "does speed matter"
   * can be asked of one project rather than only of the whole workspace.
   */
  const drivers = useQuery({
    queryKey: ['reports-drivers', filters],
    queryFn: () => fetchDrivers(filters),
    enabled: tab === 'drivers',
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
          <div className="flex flex-wrap items-center gap-2">
            {/* Data quality moved here from the main menu: it is a report about the data. */}
            <Link to="/data-quality" data-testid="open-data-quality">
              <Button variant="ghost" icon={<ShieldAlert className="size-4" />}>{t('nav.dataQuality')}</Button>
            </Link>
          {tab === 'campaigns' && exportsOn ? (
            <Button
              variant="outline"
              icon={<Download className="size-4" />}
              onClick={() => void apiDownload('/reports/campaign-quality/export', filters, 'campaign-quality.csv')}
            >
              {t('reports.exportCsv')}
            </Button>
          ) : null}
          </div>
        }
      />

      {/* What to read first, before any table. */}
      <InsightStrip />

      <Card className="mb-4 overflow-hidden">
        {/* One filter set for every tab, so switching keeps the period. */}
        <div className="scroll-x flex items-center gap-1 border-b border-[var(--color-line)] p-2">
          {visibleTabs.map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setTab(key)}
              data-testid={`tab-${key}`}
              aria-current={tab === key}
              className={cn(
                'rounded-[var(--radius-control)] px-3 py-1.5 text-[0.9375rem] font-medium whitespace-nowrap',
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

        <div className={cn('grid gap-2 bg-[var(--color-surface-muted)] p-3 sm:grid-cols-2 lg:grid-cols-5', tab === 'share' && 'hidden')}>
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
            error={campaigns.isError ? translateError(campaigns.error) : undefined}
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
            error={campaigns.isError ? translateError(campaigns.error) : undefined}
            empty={(campaigns.data?.rows.length ?? 0) < 2}
            emptyMessage={t('campaignQuality.needMoreCampaigns')}
            testId="report-campaign-matrix"
          >
            <CampaignMatrix rows={campaigns.data?.rows ?? []} />
          </ChartCard>

          <CampaignTable
            rows={campaigns.data?.rows ?? []}
            loading={campaigns.isLoading}
            currency={campaigns.data?.currency ?? 'SAR'}
          />
        </div>
      )}

      {tab === 'platforms' && (
        <ChartCard
          title={t('dashboard.platformsTitle')}
          description={t('dashboard.platformsHint')}
          height={400}
          loading={campaigns.isLoading}
          error={campaigns.isError ? translateError(campaigns.error) : undefined}
          empty={(campaigns.data?.rows.length ?? 0) === 0}
          emptyMessage={t('campaignQuality.empty')}
          testId="report-platforms"
        >
          <PlatformPerformanceChart rows={campaigns.data?.rows ?? []} />
        </ChartCard>
      )}

      {tab === 'team' && <TeamTab query={team} performance={performance} translateError={translateError} dateFrom={dateFrom} projectId={projectId} />}

      {tab === 'lost' && (
        <ChartCard
          title={t('reports.byLostReason')}
          description={t('reports.lostHint')}
          height={340}
          loading={lost.isLoading}
          error={lost.isError ? translateError(lost.error) : undefined}
          empty={(lost.data?.rows.length ?? 0) === 0}
          emptyMessage={t('reports.noLost')}
          testId="report-lost"
        >
          <LostReasonsChart rows={lost.data?.rows ?? []} />
        </ChartCard>
      )}

      {tab === 'drivers' && <DriversTab query={drivers} translateError={translateError} />}

      {tab === 'marketing' && <MarketingSalesTab dateFrom={dateFrom || undefined} dateTo={dateTo || undefined} />}

      {tab === 'quality' && <QualityTab dateFrom={dateFrom || undefined} dateTo={dateTo || undefined} />}

      {tab === 'weekly' && <WeeklyReportsTab />}
      {tab === 'share' && <ShareTab startOpen={params.get('new') === '1'} />}
    </>
  )
}

/**
 * Does any of it actually predict a sale?
 *
 * Two charts rather than one: speed and score are different claims, and a
 * reader who finds that speed matters and the score does not needs to be able
 * to see that, which a single combined chart would hide.
 *
 * Both state the cohort they were cut from. A win rate with no window is a
 * number nobody can argue with, which is the opposite of useful.
 */
function DriversTab({
  query,
  translateError,
}: {
  query: ReturnType<typeof useQuery<Drivers>>
  translateError: (error: unknown) => string
}) {
  const { t, i18n } = useTranslation()
  const data = query.data

  if (query.isError) {
    return <Card><ErrorState message={translateError(query.error)} onRetry={() => void query.refetch()} /></Card>
  }

  const matured = data
    ? t('charts.drivers.matured', { date: formatDate(data.speed.matured_before, i18n.language) })
    : undefined

  /*
   * An empty answer here is almost always "this workspace is younger than the
   * window", not "nothing converts". Saying only "not enough data" sends an
   * admin looking for a fault that is not there.
   */
  const emptyMessage = data
    ? t('charts.drivers.emptyYoung', { date: formatDate(data.speed.matured_before, i18n.language) })
    : t('charts.drivers.empty')

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <ChartCard
        title={t('charts.drivers.speedTitle')}
        description={t('charts.drivers.speedHint')}
        height={300}
        loading={query.isLoading}
        empty={Boolean(data) && data!.speed.buckets.every((bucket) => bucket.leads === 0)}
        emptyMessage={emptyMessage}
        testId="drivers-speed"
        footer={
          <div className="space-y-1">
            <p className="t-caption">{matured}</p>
            {/* Rows dropped for an impossible timestamp are declared, not hidden. */}
            {data && data.speed.excluded > 0 && (
              <p className="t-caption">
                {t('charts.drivers.excluded', { count: data.speed.excluded })}
              </p>
            )}
          </div>
        }
        table={
          data && {
            head: [t('charts.drivers.speedTitle'), t('campaignQuality.leads'), t('campaignQuality.won'), '%'],
            rows: data.speed.buckets.map((bucket) => [
              t(`charts.drivers.${bucket.bucket}`),
              bucket.leads,
              bucket.won,
              bucket.win_rate === null ? '—' : `${bucket.win_rate}%`,
            ]),
          }
        }
      >
        <ConversionDriversChart
          rows={data?.speed.buckets.map((bucket) => ({ ...bucket, key: bucket.bucket })) ?? []}
          label={(key) => t(`charts.drivers.${key}`)}
          testId="drivers-speed-bars"
        />
      </ChartCard>

      <ChartCard
        title={t('charts.drivers.scoreTitle')}
        description={t('charts.drivers.scoreHint')}
        height={300}
        loading={query.isLoading}
        empty={Boolean(data) && data!.score.bands.every((band) => band.leads === 0)}
        emptyMessage={emptyMessage}
        testId="drivers-score"
        footer={<p className="t-caption">{matured}</p>}
        table={
          data && {
            head: [t('charts.drivers.scoreTitle'), t('campaignQuality.leads'), t('campaignQuality.won'), '%'],
            rows: data.score.bands.map((band) => [
              t(`charts.drivers.${band.band}`),
              band.leads,
              band.won,
              band.win_rate === null ? '—' : `${band.win_rate}%`,
            ]),
          }
        }
      >
        <ConversionDriversChart
          rows={data?.score.bands.map((band) => ({ ...band, key: band.band })) ?? []}
          label={(key) => t(`charts.drivers.${key}`)}
          testId="drivers-score-bars"
        />
      </ChartCard>
    </div>
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
  const { t, i18n } = useTranslation()
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

  /** How many leads reached a stage, which is what the funnel and rates mean. */
  const stage = (key: string) => data.funnel.find((row) => row.key === key)?.count ?? 0

  return (
    <div className="space-y-4">
      <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {/*
          Every stage figure here comes from the funnel, because the rates
          directly beneath them are computed the same way: a card reading
          "qualified 31" above a rate of 41.7% of 180 is two measurements
          wearing one word. The dashboard's summary counts leads *sitting in* a
          stage, which is the right question there and the wrong one here.
        */}
        <KpiCard label={t('dashboard.totalLeads')} icon={Users} value={formatNumber(data.summary.total_leads, i18n.language)} to="/leads" />
        <KpiCard label={t('campaignQuality.contacted')} icon={PhoneCall} value={formatNumber(stage('contacted'), i18n.language)} to="/leads?status[]=contacted" />
        <KpiCard label={t('dashboard.qualified')} icon={Target} tone="good" value={formatNumber(stage('qualified'), i18n.language)} to="/leads?status[]=qualified" />
        <KpiCard label={t('dashboard.hotLeads')} icon={Flame} tone="bad" value={formatNumber(data.summary.hot_leads, i18n.language)} to="/leads?temperature[]=hot" />
        <KpiCard label={t('dashboard.appointments')} icon={CalendarCheck} tone="warn" value={formatNumber(stage('appointment'), i18n.language)} to="/leads?status[]=appointment" />
        <KpiCard label={t('dashboard.won')} icon={Trophy} tone="good" value={formatNumber(stage('won'), i18n.language)} to="/leads?status[]=won" />
      </section>

      {/* Each rate as a ring: how much of the stage before made it to this one. */}
      {rates && (
        <Card>
          <div data-testid="report-rates" className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-5">
            {([
              ['contact_rate', 'campaignQuality.contactRate'],
              ['reply_rate', 'campaignQuality.replyRate'],
              ['qualification_rate', 'campaignQuality.qualificationRate'],
              ['appointment_rate', 'campaignQuality.appointmentRate'],
              ['win_rate', 'campaignQuality.winRate'],
            ] as const).map(([key, label]) => (
              <div key={key} className="flex items-center gap-3">
                <RingProgress value={rates[key] ?? 0} size={64} thickness={7} tone={(rates[key] ?? 0) >= 50 ? 'good' : (rates[key] ?? 0) >= 20 ? 'warn' : 'bad'} label={t(label)}>
                  <span className="text-[1rem] font-extrabold tabular-nums" dir="ltr">{rates[key]}%</span>
                </RingProgress>
                <p className="text-[1rem] font-medium">{t(label)}</p>
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
  translateError,
  dateFrom,
  projectId,
}: {
  query: ReturnType<typeof useQuery>
  performance: ReturnType<typeof useQuery>
  translateError: (error: unknown) => string
  dateFrom?: string
  projectId?: string
}) {
  const { t, i18n } = useTranslation()

  /*
   * Before the table's own empty state, which said "لا توجد بيانات كافية لعرض
   * هذا التقرير" to a sales rep who is simply not allowed to see how the team
   * is doing. The data was there; they were refused it, and the product gave
   * them a reason that was not true.
   */
  if (query.isError) {
    return <Card><ErrorState message={translateError(query.error)} onRetry={() => void query.refetch()} /></Card>
  }

  const rows = (query.data as { rows?: Array<Record<string, number | string | null>> } | undefined)?.rows ?? []
  const timing = (performance.data as { timing?: { avg_first_contact_seconds: number | null } } | undefined)?.timing

  return (
    <div className="space-y-4">
      {/* Who is doing what with the leads they were given — fairly, with the volume each carried. */}
      <SalesIntelligence dateFrom={dateFrom} projectId={projectId} />

      {/*
        Above the table, because comparing people is what this tab is for and a
        table is the hardest shape to compare in: reading "is نورة ahead of
        سعد" out of seven numeric columns means holding two figures in your
        head while your eye travels. The table stays underneath as the detail.
      */}
      {rows.length > 0 && (
        <ChartCard
          title={t('charts.team.title')}
          description={t('charts.team.hint')}
          height={240}
          testId="team-comparison-card"
        >
          <TeamComparisonChart rows={rows as unknown as TeamRow[]} />
        </ChartCard>
      )}

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
                    <span className="t-caption ms-1.5 text-[0.8125rem]" dir="ltr">
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
    </div>
  )
}

function CampaignTable({
  rows,
  loading,
  currency,
}: {
  rows: CampaignQualityRow[]
  loading: boolean
  /** The workspace's own, so a cost is never shown in a guessed currency. */
  currency: string
}) {
  const { t, i18n } = useTranslation()
  const [spendOpen, setSpendOpen] = useState(false)
  const list = rows

  return (
    <Card className="overflow-hidden" data-testid="campaign-table">
      <div className="flex items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="t-card-title">{t('reports.byCampaign')}</h2>
        {/*
          The cost columns are a dash until somebody enters what the ads cost,
          so the way to enter it sits on the table that shows the dashes.
        */}
        <Button variant="outline" size="sm" onClick={() => setSpendOpen(true)} data-testid="open-spend">
          {t('campaignQuality.addSpend')}
        </Button>
      </div>

      <SpendModal open={spendOpen} onClose={() => setSpendOpen(false)} campaigns={list} />

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
                {/*
                  The two columns that turn volume into a decision. Hidden on a
                  phone because the table is already at its limit there, and a
                  cost per lead nobody can read is worse than one they scroll to.
                */}
                <Th className="hidden lg:table-cell">{t('campaignQuality.costPerLead')}</Th>
                <Th className="hidden lg:table-cell">{t('campaignQuality.costPerQualified')}</Th>
                <Th className="hidden xl:table-cell">{t('campaignQuality.roas')}</Th>
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
                  {/*
                    A dash, never a zero. A campaign nobody has costed is not
                    free, and a campaign that qualified nobody has no cost per
                    qualified lead — the whole budget is not the price of one.
                  */}
                  <Td className="tabular hidden lg:table-cell" dir="ltr">
                    {row.cost_per_lead === null
                      ? '—'
                      : formatCurrency(row.cost_per_lead, currency, i18n.language)}
                  </Td>
                  <Td className="tabular hidden lg:table-cell font-medium" dir="ltr">
                    {row.cost_per_qualified === null
                      ? '—'
                      : formatCurrency(row.cost_per_qualified, currency, i18n.language)}
                  </Td>
                  {/*
                    A dash and a zero mean opposite things here. Zero is "this
                    campaign sold nothing" — a finding. A dash is "its sales
                    were never valued", which is a gap in the data rather than
                    a verdict on the campaign.
                  */}
                  <Td
                    className={cn(
                      'tabular hidden xl:table-cell font-semibold',
                      row.roas !== null && row.roas >= 1 && 'text-[var(--color-status-won)]',
                      row.roas !== null && row.roas < 1 && 'text-[var(--color-status-lost)]',
                    )}
                    dir="ltr"
                  >
                    {row.roas === null ? '—' : `${row.roas}×`}
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
