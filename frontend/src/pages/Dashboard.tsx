import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { AlertTriangle, CalendarClock, Flame, TrendingUp, UserPlus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber, formatRelative } from '@/lib/format'
import {
  Card, CardBody, CardHeader, EmptyState, ErrorState, PageHeader, Select,
  SkeletonCards, StatCard, StatusBadge, TemperatureBadge,
} from '@/components/ui'
import { OnboardingChecklist } from '@/components/OnboardingChecklist'
import type { DashboardData, LeadListItem } from '@/types/api'

const STATUS_COLORS: Record<string, string> = {
  new: '#3b82f6',
  contacted: '#8b5cf6',
  qualified: '#0891b2',
  appointment: '#d97706',
  won: '#059669',
  lost: '#dc2626',
}

export function DashboardPage() {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const translateError = useApiError()

  const [projectId, setProjectId] = useState('')
  const [assignedTo, setAssignedTo] = useState('')
  const [range, setRange] = useState('30')

  const filters = {
    project_id: projectId || undefined,
    assigned_to: assignedTo || undefined,
    date_from:
      range === 'all'
        ? undefined
        : new Date(Date.now() - Number(range) * 86_400_000).toISOString().slice(0, 10),
  }

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['dashboard', filters],
    queryFn: () => apiGet<DashboardData>('/dashboard', filters),
  })

  const isRtl = i18n.dir() === 'rtl'

  return (
    <>
      <PageHeader
        title={`${t('dashboard.title')}`}
        subtitle={user ? `${t('dashboard.subtitle')}` : undefined}
        actions={
          <div className="flex flex-wrap gap-2">
            <Select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="h-9 w-auto min-w-32"
              aria-label={t('common.date')}
            >
              <option value="7">7</option>
              <option value="30">30</option>
              <option value="90">90</option>
              <option value="all">{t('common.all')}</option>
            </Select>

            {(data?.filters.projects.length ?? 0) > 0 && (
              <Select
                value={projectId}
                onChange={(e) => setProjectId(e.target.value)}
                className="h-9 w-auto min-w-40"
                aria-label={t('leads.project')}
              >
                <option value="">{t('leads.project')}: {t('common.all')}</option>
                {data?.filters.projects.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </Select>
            )}

            {(data?.filters.users.length ?? 0) > 0 && (
              <Select
                value={assignedTo}
                onChange={(e) => setAssignedTo(e.target.value)}
                className="h-9 w-auto min-w-40"
                aria-label={t('leads.assignedTo')}
              >
                <option value="">{t('leads.assignedTo')}: {t('common.all')}</option>
                {data?.filters.users.map((u) => (
                  <option key={u.id} value={u.id}>{u.name}</option>
                ))}
              </Select>
            )}
          </div>
        }
      />

      <OnboardingChecklist />

      {isError ? (
        <Card>
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        </Card>
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <SkeletonCards count={4} />
          <SkeletonCards count={4} />
        </div>
      ) : (
        <div className="space-y-5">
          {/* "Your day" — the row a sales rep actually opens the app for. */}
          <section>
            <h2 className="mb-2.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">
              {t('dashboard.todayTitle')}
            </h2>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <StatCard
                label={t('dashboard.newToday')}
                value={formatNumber(data.today.new_leads, i18n.language)}
                icon={<UserPlus className="size-4" />}
                tone="brand"
              />
              <StatCard
                label={t('dashboard.followUpsToday')}
                value={formatNumber(data.today.follow_ups_today, i18n.language)}
                icon={<CalendarClock className="size-4" />}
              />
              <StatCard
                label={t('dashboard.followUpsOverdue')}
                value={formatNumber(data.today.follow_ups_overdue, i18n.language)}
                icon={<AlertTriangle className="size-4" />}
                tone={data.today.follow_ups_overdue > 0 ? 'danger' : 'neutral'}
              />
              <StatCard
                label={t('dashboard.hotLeads')}
                value={formatNumber(data.summary.hot_leads, i18n.language)}
                icon={<Flame className="size-4" />}
                tone={data.summary.hot_leads > 0 ? 'warning' : 'neutral'}
              />
            </div>
          </section>

          {/* Funnel totals */}
          <section>
            <h2 className="mb-2.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">
              {t('dashboard.title')}
            </h2>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
              <StatCard label={t('dashboard.totalLeads')} value={formatNumber(data.summary.total_leads, i18n.language)} />
              <StatCard label={t('status.new')} value={formatNumber(data.summary.new, i18n.language)} />
              <StatCard label={t('dashboard.qualified')} value={formatNumber(data.summary.qualified, i18n.language)} />
              <StatCard label={t('dashboard.appointments')} value={formatNumber(data.summary.appointments, i18n.language)} />
              <StatCard label={t('dashboard.won')} value={formatNumber(data.summary.won, i18n.language)} tone="success" />
              <StatCard
                label={t('dashboard.conversionRate')}
                value={`${data.summary.conversion_rate}%`}
                icon={<TrendingUp className="size-4" />}
                tone="brand"
              />
            </div>
          </section>

          {/* Charts */}
          <section className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title={t('dashboard.trend')} />
              <CardBody className="p-3">
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={data.charts.trend} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                    <CartesianGrid vertical={false} stroke="var(--color-border)" />
                    <XAxis
                      dataKey="key"
                      tickFormatter={(v: string) => v.slice(5)}
                      tick={{ fontSize: 11, fill: 'var(--color-ink-subtle)' }}
                      axisLine={false}
                      tickLine={false}
                      reversed={isRtl}
                    />
                    <YAxis
                      allowDecimals={false}
                      tick={{ fontSize: 11, fill: 'var(--color-ink-subtle)' }}
                      axisLine={false}
                      tickLine={false}
                      orientation={isRtl ? 'right' : 'left'}
                      width={28}
                    />
                    <Tooltip
                      cursor={{ fill: 'var(--color-surface-sunken)' }}
                      contentStyle={{
                        borderRadius: 8,
                        border: '1px solid var(--color-border)',
                        fontSize: 12,
                      }}
                    />
                    <Bar dataKey="count" fill="var(--brand)" radius={[4, 4, 0, 0]} maxBarSize={28} />
                  </BarChart>
                </ResponsiveContainer>
              </CardBody>
            </Card>

            <Card>
              <CardHeader title={t('dashboard.byStatus')} />
              <CardBody className="space-y-3">
                {data.charts.by_status.map((point) => {
                  const total = data.summary.total_leads || 1

                  return (
                    <div key={point.key}>
                      <div className="mb-1 flex items-baseline justify-between gap-3">
                        <span className="text-[0.8125rem]">{t(`status.${point.key}`)}</span>
                        <span className="tabular shrink-0 text-[0.8125rem] font-medium text-[var(--color-ink-muted)]">
                          {formatNumber(point.count, i18n.language)}
                        </span>
                      </div>
                      <div className="h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                        <div
                          className="h-full rounded-full transition-[width] duration-500"
                          style={{
                            width: `${(point.count / total) * 100}%`,
                            background: STATUS_COLORS[point.key] ?? 'var(--brand)',
                          }}
                        />
                      </div>
                    </div>
                  )
                })}
              </CardBody>
            </Card>
          </section>

          {/* Needs attention */}
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

          <section className="grid gap-4 lg:grid-cols-2">
            <BreakdownCard title={t('dashboard.byPlatform')} points={data.charts.by_platform} translateKey="platforms" />
            <BreakdownCard title={t('dashboard.byCampaign')} points={data.charts.by_campaign} />
          </section>
        </div>
      )}
    </>
  )
}

function LeadMiniList({
  title,
  leads,
  emptyText,
  to,
  showDue = false,
}: {
  title: string
  leads: LeadListItem[]
  emptyText: string
  to: string
  showDue?: boolean
}) {
  const { t, i18n } = useTranslation()

  return (
    <Card className="overflow-hidden">
      <CardHeader
        title={title}
        action={
          leads.length > 0 ? (
            <Link to={to} className="text-[0.8125rem] font-medium text-[var(--brand)] hover:underline">
              {t('dashboard.viewAll')}
            </Link>
          ) : undefined
        }
      />
      {leads.length === 0 ? (
        <EmptyState title={emptyText} className="py-10" />
      ) : (
        <ul className="divide-y divide-[var(--color-border)]">
          {leads.map((lead) => (
            <li key={lead.id}>
              <Link
                to={`/leads/${lead.id}`}
                className="flex items-center gap-3 px-5 py-3 hover:bg-[var(--color-surface-muted)]"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[0.875rem] font-medium">{lead.full_name}</p>
                  <p className="tabular mt-0.5 truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
                    {lead.phone ?? '—'}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  {showDue && lead.next_follow_up_at ? (
                    <span className="text-[0.75rem] text-[var(--color-status-lost)]">
                      {formatRelative(lead.next_follow_up_at, i18n.language)}
                    </span>
                  ) : (
                    <TemperatureBadge temperature={lead.temperature} />
                  )}
                  <StatusBadge status={lead.status} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function BreakdownCard({
  title,
  points,
  translateKey,
}: {
  title: string
  points: { key: string; count: number }[]
  translateKey?: string
}) {
  const { t, i18n } = useTranslation()
  const max = Math.max(...points.map((p) => p.count), 1)

  return (
    <Card className="overflow-hidden">
      <CardHeader title={title} />
      {points.length === 0 ? (
        <EmptyState title={t('reports.empty')} className="py-10" />
      ) : (
        <CardBody className="space-y-2.5">
          {points.map((point) => (
            <div key={point.key}>
              <div className="mb-1 flex items-baseline justify-between gap-3">
                <span className="truncate text-[0.8125rem]">
                  {translateKey
                    ? t(`${translateKey}.${point.key}`, { defaultValue: point.key })
                    : point.key}
                </span>
                <span className="tabular shrink-0 text-[0.8125rem] font-medium text-[var(--color-ink-muted)]">
                  {formatNumber(point.count, i18n.language)}
                </span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                <div
                  className="h-full rounded-full"
                  style={{ width: `${(point.count / max) * 100}%`, background: 'var(--brand)' }}
                />
              </div>
            </div>
          ))}
        </CardBody>
      )}
    </Card>
  )
}
