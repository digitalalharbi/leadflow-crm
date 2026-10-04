import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle, CalendarCheck, CalendarClock, Flame, PhoneOff, Target, UserPlus,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { fetchCampaignQuality } from '@/features/conversations'
import { can, useAuth } from '@/stores/auth'
import { OnboardingChecklist } from '@/components/OnboardingChecklist'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber, formatRelative } from '@/lib/format'
import {
  Card, ErrorState, PageHeader, Select, Skeleton, SkeletonCards, StatusBadge, TemperatureBadge,
} from '@/components/ui'
import {
  CampaignMatrix, CampaignQualityChart, ChartCard, ConversionFunnel, DistributionBar, MetricCard,
  PlatformPerformanceChart, ResponseTimeChart, TEMPERATURE_COLOR,
} from '@/components/charts'
import type { DashboardData, LeadListItem } from '@/types/api'

/**
 * The first screen.
 *
 * Ordered by the question it answers rather than by what is easiest to draw:
 * the headline numbers, then where the pipeline leaks, then where the leads
 * come from and how good they are, then what has to happen today. Everything
 * on it is a link to the list it counts — a number nobody can act on is
 * decoration, and a dashboard full of decoration is why people stop opening
 * dashboards.
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const translateError = useApiError()

  const [projectId, setProjectId] = useState('')
  const [assignedTo, setAssignedTo] = useState('')
  const [range, setRange] = useState('30')

  const dateFrom =
    range === 'all'
      ? undefined
      : new Date(Date.now() - Number(range) * 86_400_000).toISOString().slice(0, 10)

  const filters = {
    project_id: projectId || undefined,
    assigned_to: assignedTo || undefined,
    date_from: dateFrom,
  }

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['dashboard', filters],
    queryFn: () => apiGet<DashboardData>('/dashboard', filters),
  })

  /*
   * Campaign performance is reports-level data, and the endpoint enforces that.
   * Asking for it anyway on a rep's dashboard spent a 403 on every load and
   * then drew three cards and a link to a page they cannot open.
   */
  const canViewReports = can.viewReports(user)

  // The campaign and platform sections share one request — the same figures
  // answer both questions, and two endpoints would risk them disagreeing.
  const { data: campaigns, isLoading: campaignsLoading } = useQuery({
    queryKey: ['dashboard-campaigns', filters],
    queryFn: () => fetchCampaignQuality(filters),
    enabled: canViewReports,
  })

  const today = new Date().toISOString().slice(0, 10)
  const comparison = data?.comparison ?? null
  const trend = data?.charts.trend.map((point) => point.count) ?? []

  return (
    <>
      <PageHeader
        title={
          user?.name
            ? t('dashboard.greeting', { name: `، ${user.name.split(' ')[0]}` })
            : t('dashboard.title')
        }
        subtitle={t('dashboard.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="h-9 w-auto min-w-32"
              aria-label={t('common.date')}
              data-testid="range-filter"
            >
              <option value="7">{t('dashboard.lastDays', { count: 7 })}</option>
              <option value="30">{t('dashboard.lastDays', { count: 30 })}</option>
              <option value="90">{t('dashboard.lastDays', { count: 90 })}</option>
              <option value="all">{t('common.all')}</option>
            </Select>

            {(data?.filters.projects.length ?? 0) > 0 && (
              <Select
                value={projectId}
                onChange={(e) => setProjectId(e.target.value)}
                className="h-9 w-auto min-w-36"
                aria-label={t('leads.project')}
                data-testid="project-filter"
              >
                <option value="">{t('dashboard.allProjects')}</option>
                {data?.filters.projects.map((project) => (
                  <option key={project.id} value={project.id}>{project.name}</option>
                ))}
              </Select>
            )}

            {(data?.filters.users.length ?? 0) > 0 && (
              <Select
                value={assignedTo}
                onChange={(e) => setAssignedTo(e.target.value)}
                className="h-9 w-auto min-w-36"
                aria-label={t('leads.assignedTo')}
                data-testid="owner-filter"
              >
                <option value="">{t('dashboard.allOwners')}</option>
                {data?.filters.users.map((owner) => (
                  <option key={owner.id} value={owner.id}>{owner.name}</option>
                ))}
              </Select>
            )}
          </div>
        }
      />

      {isError ? (
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <SkeletonCards count={5} />
          <div className="grid gap-4 lg:grid-cols-2">
            <Skeleton className="h-72" />
            <Skeleton className="h-72" />
          </div>
        </div>
      ) : (
        <div className="space-y-5">
          {/*
            The five figures a sales manager quotes in a meeting. Each carries
            its change against the previous period of equal length — which is
            absent, rather than invented, when no range is set.
          */}
          <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <MetricCard
              label={t('dashboard.totalLeads')}
              value={data.summary.total_leads}
              previous={comparison?.total}
              icon={<UserPlus className="size-4" />}
              tone="brand"
              to="/leads"
              spark={trend}
              testId="kpi-total"
            />
            <MetricCard
              label={t('dashboard.qualified')}
              value={data.summary.qualified}
              previous={comparison?.qualified}
              icon={<Target className="size-4" />}
              to="/leads?status[]=qualified"
              testId="kpi-qualified"
            />
            <MetricCard
              label={t('dashboard.hotLeads')}
              value={data.summary.hot_leads}
              previous={comparison?.hot}
              icon={<Flame className="size-4" />}
              tone={data.summary.hot_leads > 0 ? 'warning' : 'neutral'}
              to="/leads?temperature[]=hot"
              testId="kpi-hot"
            />
            <MetricCard
              label={t('dashboard.appointments')}
              value={data.summary.appointments}
              previous={comparison?.appointments}
              icon={<CalendarCheck className="size-4" />}
              to="/leads?status[]=appointment"
              testId="kpi-appointments"
            />
            <MetricCard
              label={t('dashboard.won')}
              value={data.summary.won}
              previous={comparison?.won}
              icon={<CalendarCheck className="size-4" />}
              tone="success"
              to="/leads?status[]=won"
              testId="kpi-won"
            />
          </section>

          {/*
            A brand-new workspace has nothing to show, so the first thing an
            admin sees should be what to do about that. The checklist takes
            itself off the page for good once the five steps are done.
          */}
          <OnboardingChecklist />

          {/* What has to happen today, and what is already late. */}
          <TodayStrip data={data} today={today} />

          <section className="grid gap-4 lg:grid-cols-2">
            <ChartCard
              title={t('dashboard.funnelTitle')}
              description={t('dashboard.funnelHint')}
              height={290}
              empty={data.summary.total_leads === 0}
              emptyMessage={t('dashboard.noLeadsYet')}
              testId="funnel-card"
            >
              <ConversionFunnel stages={data.funnel} />
            </ChartCard>

            {canViewReports && (
              <ChartCard
                title={t('campaignQuality.title')}
                description={t('campaignQuality.subtitle')}
                height={290}
                loading={campaignsLoading}
                empty={(campaigns?.rows.length ?? 0) === 0}
                emptyMessage={t('campaignQuality.empty')}
                action={
                  <Link to="/reports" className="t-caption hover:text-[var(--brand)]">
                    {t('dashboard.viewReports')}
                  </Link>
                }
                testId="campaign-card"
              >
                <CampaignQualityChart rows={campaigns?.rows ?? []} />
              </ChartCard>
            )}
          </section>

          {canViewReports && (
            <ChartCard
              title={t('campaignQuality.matrixTitle')}
              description={t('campaignQuality.matrixHint')}
              height={270}
              loading={campaignsLoading}
              empty={(campaigns?.rows.length ?? 0) < 2}
              emptyMessage={t('campaignQuality.needMoreCampaigns')}
              testId="matrix-card"
            >
              <CampaignMatrix rows={campaigns?.rows ?? []} />
            </ChartCard>
          )}

          <section className="grid gap-4 lg:grid-cols-2">
            {canViewReports && (
              <ChartCard
                title={t('dashboard.platformsTitle')}
                description={t('dashboard.platformsHint')}
                height={290}
                loading={campaignsLoading}
                empty={(campaigns?.rows.length ?? 0) === 0}
                emptyMessage={t('campaignQuality.empty')}
                testId="platforms-card"
              >
                <PlatformPerformanceChart rows={campaigns?.rows ?? []} />
              </ChartCard>
            )}

            <div className="grid gap-4">
              <ChartCard
                title={t('dashboard.qualityTitle')}
                description={t('dashboard.qualityHint')}
                height={120}
                empty={data.quality.every((segment) => segment.count === 0)}
                emptyMessage={t('dashboard.noOpenLeads')}
                testId="quality-card"
              >
                <DistributionBar
                  testId="quality-distribution"
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

              <ChartCard
                title={t('dashboard.responseTitle')}
                description={t('dashboard.responseHint')}
                height={150}
                empty={data.response_time.contacted === 0 && data.response_time.uncontacted === 0}
                emptyMessage={t('dashboard.noLeadsYet')}
                testId="response-card"
              >
                <ResponseTimeChart data={data.response_time} />
              </ChartCard>
            </div>
          </section>

          <section className="grid gap-4 lg:grid-cols-2">
            <LeadMiniList
              title={t('dashboard.hotLeadsList')}
              leads={data.hot_leads}
              emptyText={t('dashboard.noHotLeads')}
              to="/leads?temperature[]=hot"
            />
            <LeadMiniList
              title={t('dashboard.overdueList')}
              leads={data.overdue_leads}
              emptyText={t('dashboard.noOverdue')}
              to="/leads?overdue=1"
              showDue
            />
          </section>

          {data.awaiting_contact_leads.length > 0 && (
            <LeadMiniList
              title={t('dashboard.awaitingContactList', {
                minutes: data.today.first_contact_target_minutes,
              })}
              leads={data.awaiting_contact_leads}
              emptyText={t('dashboard.noAwaitingContact')}
              to="/leads?awaiting_contact=1"
              tone="warning"
            />
          )}
        </div>
      )}
    </>
  )
}

/**
 * Today's work, as one row of linked counts.
 *
 * Separate from the KPI strip above because these are not measurements — they
 * are a to-do list, and mixing the two makes both harder to read.
 */
function TodayStrip({ data, today }: { data: DashboardData; today: string }) {
  const { t, i18n } = useTranslation()

  const items = [
    {
      key: 'new',
      label: t('dashboard.newToday'),
      value: data.today.new_leads,
      icon: <UserPlus className="size-4" />,
      to: `/leads?status[]=new&date_from=${today}`,
    },
    {
      key: 'due',
      label: t('dashboard.followUpsToday'),
      value: data.today.follow_ups_today,
      icon: <CalendarClock className="size-4" />,
      to: '/follow-ups?bucket=today',
    },
    {
      key: 'overdue',
      label: t('dashboard.followUpsOverdue'),
      value: data.today.follow_ups_overdue,
      icon: <AlertTriangle className="size-4" />,
      to: '/follow-ups?bucket=overdue',
      urgent: data.today.follow_ups_overdue > 0,
    },
    {
      key: 'awaiting',
      label: t('dashboard.awaitingContact'),
      value: data.today.awaiting_first_contact,
      icon: <PhoneOff className="size-4" />,
      to: '/leads?awaiting_contact=1',
      urgent: data.today.awaiting_first_contact > 0,
    },
  ]

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="t-card-title">{t('dashboard.todayTitle')}</h2>
      </div>

      <ul className="grid divide-y divide-[var(--color-line)] sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 [&>li:not(:last-child)]:sm:border-e [&>li]:sm:border-[var(--color-line)]">
        {items.map((item) => (
          <li key={item.key}>
            <Link
              to={item.to}
              data-testid={`today-${item.key}`}
              className="flex items-center gap-3 px-5 py-4 transition-colors hover:bg-[var(--color-surface-muted)]"
            >
              <span
                className={`grid size-9 shrink-0 place-items-center rounded-[var(--radius-control)] ${
                  item.urgent
                    ? 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]'
                    : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]'
                }`}
              >
                {item.icon}
              </span>

              <span className="min-w-0">
                <span className="t-metric-sm block">{formatNumber(item.value, i18n.language)}</span>
                <span className="t-caption block truncate">{item.label}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function LeadMiniList({
  title,
  leads,
  emptyText,
  to,
  showDue = false,
  tone,
}: {
  title: string
  leads: LeadListItem[]
  emptyText: string
  to: string
  showDue?: boolean
  tone?: 'warning'
}) {
  const { t, i18n } = useTranslation()

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-3">
        <h2 className="t-card-title">{title}</h2>
        <Link to={to} className="t-caption hover:text-[var(--brand)]">
          {t('common.showMore')}
        </Link>
      </div>

      {leads.length === 0 ? (
        <p className="t-small px-5 py-8 text-center text-[var(--color-ink-muted)]">{emptyText}</p>
      ) : (
        <ul className="divide-y divide-[var(--color-line)]">
          {leads.map((lead) => (
            <li key={lead.id}>
              <Link
                to={`/leads/${lead.id}`}
                className="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-[var(--color-surface-muted)]"
              >
                <span className="min-w-0">
                  <span className="t-small block truncate font-medium">{lead.full_name}</span>
                  <span className="t-caption block truncate">
                    {lead.project?.name ?? lead.campaign_name ?? lead.phone}
                  </span>
                </span>

                <span className="flex shrink-0 items-center gap-2">
                  {showDue && lead.next_follow_up_at ? (
                    <span
                      className={`t-caption ${tone === 'warning' ? 'text-[var(--color-warning)]' : 'text-[var(--color-danger)]'}`}
                    >
                      {formatRelative(lead.next_follow_up_at, i18n.language)}
                    </span>
                  ) : (
                    <TemperatureBadge temperature={lead.temperature} />
                  )}
                  <StatusBadge status={lead.status} className="hidden sm:inline-flex" />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
