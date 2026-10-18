import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle, CalendarClock, ChevronDown, PhoneOff, UserPlus,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { fetchCampaignQuality } from '@/features/conversations'
import { can, useAuth } from '@/stores/auth'
import { OnboardingChecklist } from '@/components/OnboardingChecklist'
import { DashboardBoard } from '@/components/dashboard/DashboardBoard'
import { HoldingOverview } from '@/components/company/HoldingOverview'
import { useApiError } from '@/hooks/useApiError'
import { formatDue, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import {
  Card, ErrorState, PageHeader, Select, Skeleton, SkeletonCards, StatusBadge, TemperatureBadge,
} from '@/components/ui'
import {
  CampaignMatrix, CampaignQualityChart, ChartCard, ConversionFunnel, DistributionBar,
  PlatformPerformanceChart, ResponseTimeChart, TEMPERATURE_COLOR,
  VelocityChart,
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
    /*
     * Two minutes. This is the screen left open on a wall or a second monitor,
     * and the whole point of it is that nobody has to go and ask. Slower than
     * the work queue because a funnel does not change by the minute, and
     * because this is the most expensive query in the product — every manager
     * polling it at thirty seconds would be the first thing to fall over.
     */
    refetchInterval: 120_000,
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

  // Open by default: on a wide screen the class that hides it never applies,
  // so this only decides what a phone shows before the first tap.
  const [analyticsOpen, setAnalyticsOpen] = useState(false)

  const today = new Date().toISOString().slice(0, 10)
  const trend = data?.charts.trend.map((point) => point.count) ?? []

  return (
    <>
      <PageHeader
        title={user?.name ? t('dashboard.welcomeBack') : t('dashboard.title')}
        titleMuted={user?.name ? user.name.split(' ')[0] : undefined}
        actions={
          <div className="flex flex-wrap gap-2">
            <Select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="filter-pill min-w-36"
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
                className="filter-pill min-w-40"
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
                className="filter-pill min-w-40"
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
        <div className="space-y-3">
          <SkeletonCards count={5} />
          <div className="grid gap-3 lg:grid-cols-2">
            <Skeleton className="h-72" />
            <Skeleton className="h-72" />
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          {/* Holding → company → project, when the workspace has companies at all. */}
          <HoldingOverview />

          {/*
            The reference arrangement: what needs a person (the green card), the
            month as bars, the headline figure, the funnel, and who to start
            with. Everything on it is the same data as before, in the same
            places the tests and the links expect it.
          */}
          <DashboardBoard data={data} trend={trend} />

          {/*
            A brand-new workspace has nothing to show, so the first thing an
            admin sees should be what to do about that. The checklist takes
            itself off the page for good once the five steps are done.
          */}
          <OnboardingChecklist />

          {/* What has to happen today, and what is already late. */}
          <TodayStrip data={data} today={today} />

          {/*
            The six analytics cards stack to roughly sixteen screens on a phone
            and four on a laptop, which buries the two lists at the bottom that
            a rep actually opens the app for.

            They now fold at every size, not only below `sm`. A manager reading
            the funnel opens it deliberately; a rep opening the app at 9am wants
            the numbers and the two lists, and should not scroll past eight
            charts to reach them. One press, and it stays open for the session.
          */}
          <button
            type="button"
            onClick={() => setAnalyticsOpen((open) => !open)}
            aria-expanded={analyticsOpen}
            data-testid="toggle-analytics"
            className={cn(
              'pressable flex w-full items-center justify-between gap-2',
              'rounded-[var(--radius-card)] border border-[var(--color-line)]',
              'bg-[var(--color-surface)] px-3 py-2 text-[0.8125rem] font-medium',
            )}
          >
            {t('dashboard.analytics')}
            <ChevronDown
              className={cn('size-4 transition-transform', analyticsOpen && 'rotate-180')}
              aria-hidden
            />
          </button>

          <div className={cn('space-y-3', !analyticsOpen && 'hidden')}>
          <section className="grid gap-3 lg:grid-cols-2">
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

            {/*
              Beside the funnel, because it is the question the funnel provokes.
              The funnel says ten of forty reached an appointment; this says the
              ones that did waited three weeks for a first call — and that is
              the half somebody can act on this afternoon.
            */}
            <ChartCard
              title={t('charts.velocity.title')}
              description={t('charts.velocity.hint')}
              height={290}
              testId="velocity-card"
              empty={(data.velocity ?? []).every((step) => step.count === 0)}
              emptyMessage={t('dashboard.noLeadsYet')}
              table={{
                head: [
                  t('charts.velocity.step'),
                  t('charts.velocity.median'),
                  t('charts.velocity.sample'),
                ],
                rows: (data.velocity ?? []).map((step) => [
                  t(`charts.velocity.${step.step}`),
                  step.median_hours === null ? '—' : `${step.median_hours}h`,
                  step.count,
                ]),
              }}
            >
              <VelocityChart steps={data.velocity ?? []} />
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

          <section className="grid gap-3 lg:grid-cols-2">
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

            <div className="grid gap-3">
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
          </div>

          <section className="grid gap-3 lg:grid-cols-2">
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
                count: data.today.first_contact_target_minutes,
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
        {/* A link is not readable on paper; the list under it is. */}
        <Link to={to} className="t-caption hover:text-[var(--brand)]" data-print="hide">
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
                      {/*
                        In the overdue panel a bare clock is the bug `formatDue`
                        exists for: "21:24" under a heading that says "late" is a
                        time, not a warning. In the due-today panel the day is
                        already established, so the clock is the useful part.
                      */}
                      {formatDue(lead.next_follow_up_at, i18n.language, tone !== 'warning')}
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
