import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bot, Plug, Zap } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth, can } from '@/stores/auth'
import { formatNumber, formatRelative } from '@/lib/format'
import {
  Badge, Card, CardBody, CardHeader, EmptyState, Skeleton, StatCard, StatusBadge,
  Table, TableWrap, Td, TemperatureBadge, Th, Tr,
} from '@/components/ui'
import { fetchLeads } from '@/features/leads'
import { fetchRules } from '@/features/automation'
import { useCampaigns } from '@/features/campaigns'
import { CampaignCard } from '@/components/campaigns/CampaignCard'
import {
  fetchProjectActivity, fetchProjectReport, fetchProjectSummary, type ReportRow,
} from '@/features/projectWorkspace'

/**
 * One project, seen from inside it.
 *
 * Each tab is an existing question asked of one project rather than of the
 * whole workspace, which is what people mean when they open a project: not
 * "what is the system doing" but "how is this one going". The data is the same
 * data; nothing here is a second source of truth.
 */

/* ---------------------------------------------------------------- overview */

export function ProjectNumbers({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['project-summary', projectId],
    queryFn: () => fetchProjectSummary(projectId),
  })

  if (isLoading) return <Skeleton className="h-20" />

  const s = data?.summary

  return (
    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="project-numbers">
      <StatCard label={t('projects.workspace.total')} value={formatNumber(s?.total_leads ?? 0, i18n.language)} />
      <StatCard label={t('projects.workspace.qualified')} value={formatNumber(s?.qualified ?? 0, i18n.language)} />
      <StatCard label={t('projects.workspace.hot')} value={formatNumber(s?.hot_leads ?? 0, i18n.language)} tone="warning" />
      <StatCard label={t('projects.workspace.appointments')} value={formatNumber(s?.appointments ?? 0, i18n.language)} />
      <StatCard label={t('projects.workspace.won')} value={formatNumber(s?.won ?? 0, i18n.language)} tone="success" />
    </div>
  )
}

/* ------------------------------------------------------------------- leads */

export function ProjectLeads({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['project-leads', projectId],
    queryFn: () => fetchLeads({ project_id: projectId, per_page: 15 }),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const leads = data?.data ?? []

  return (
    <Card>
      <CardHeader
        title={t('projects.workspace.leadsTitle')}
        description={t('projects.workspace.leadsCount', { count: data?.meta?.total ?? leads.length })}
        action={
          <Link
            to={`/leads?project_id=${projectId}`}
            className="t-small text-[var(--brand)] hover:underline"
          >
            {t('projects.workspace.seeAll')}
          </Link>
        }
      />
      {leads.length === 0 ? (
        <CardBody>
          <p className="t-caption" data-testid="project-leads-empty">{t('projects.workspace.noLeads')}</p>
        </CardBody>
      ) : (
        <TableWrap>
          <Table data-testid="project-leads">
            <thead>
              <tr>
                <Th>{t('leads.name')}</Th>
                <Th className="hidden sm:table-cell">{t('leads.status')}</Th>
                <Th className="hidden sm:table-cell">{t('leads.temperature')}</Th>
                <Th className="hidden md:table-cell">{t('leads.nextFollowUp')}</Th>
              </tr>
            </thead>
            <tbody>
              {leads.map((lead) => (
                <Tr key={lead.id}>
                  <Td>
                    <Link to={`/leads/${lead.id}`} className="font-medium hover:text-[var(--brand)]">
                      {lead.full_name}
                    </Link>
                  </Td>
                  <Td className="hidden sm:table-cell"><StatusBadge status={lead.status} /></Td>
                  <Td className="hidden sm:table-cell"><TemperatureBadge temperature={lead.temperature} /></Td>
                  <Td className="hidden md:table-cell t-caption">
                    {lead.next_follow_up_at ? formatRelative(lead.next_follow_up_at, i18n.language) : '—'}
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

/* --------------------------------------------------------------- campaigns */

export function ProjectCampaigns({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['project-campaigns', projectId],
    queryFn: () => fetchProjectReport(projectId, 'campaign'),
  })

  const platforms = useQuery({
    queryKey: ['project-platforms', projectId],
    queryFn: () => fetchProjectReport(projectId, 'platform'),
  })

  // The campaigns as the campaigns screen draws them — cost, quality, a way in
  // to their ads — for the people allowed to see money.
  const user = useAuth((s) => s.user)
  const money = can.viewReports(user)
  const campaigns = useCampaigns(30, '', projectId, money)

  if (isLoading) return <Skeleton className="h-40" />

  return (
    <div className="space-y-3">
      {money && (campaigns.data?.campaigns.length ?? 0) > 0 && (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="project-campaign-cards">
          {campaigns.data!.campaigns.map((campaign) => (
            <CampaignCard key={campaign.id} campaign={campaign} currency={campaigns.data!.currency} />
          ))}
        </div>
      )}
      <RowsCard
        title={t('projects.workspace.campaignsTitle')}
        rows={data?.rows ?? []}
        empty={t('projects.workspace.noCampaigns')}
        lang={i18n.language}
        testId="project-campaigns"
      />
      <RowsCard
        title={t('projects.workspace.platformsTitle')}
        rows={platforms.data?.rows ?? []}
        empty={t('projects.workspace.noPlatforms')}
        lang={i18n.language}
        testId="project-platforms"
        translateLabel={(label) => t(`platforms.${label}`, { defaultValue: label })}
      />
    </div>
  )
}

function RowsCard({
  title, rows, empty, lang, testId, translateLabel,
}: {
  title: string
  rows: ReportRow[]
  empty: string
  lang: string
  testId: string
  translateLabel?: (label: string) => string
}) {
  const { t } = useTranslation()

  return (
    <Card>
      <CardHeader title={title} />
      {rows.length === 0 ? (
        <CardBody><p className="t-caption">{empty}</p></CardBody>
      ) : (
        <TableWrap>
          <Table data-testid={testId}>
            <thead>
              <tr>
                <Th>{t('projects.workspace.name')}</Th>
                <Th>{t('projects.workspace.total')}</Th>
                <Th className="hidden sm:table-cell">{t('projects.workspace.qualified')}</Th>
                <Th className="hidden sm:table-cell">{t('projects.workspace.won')}</Th>
                <Th>{t('projects.workspace.conversion')}</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <Tr key={row.key}>
                  <Td className="font-medium">{translateLabel ? translateLabel(row.label) : row.label}</Td>
                  <Td className="tabular">{formatNumber(row.total, lang)}</Td>
                  <Td className="tabular hidden sm:table-cell">{formatNumber(row.qualified, lang)}</Td>
                  <Td className="tabular hidden sm:table-cell">{formatNumber(row.won, lang)}</Td>
                  <Td className="tabular" dir="ltr">{row.conversion_rate}%</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </TableWrap>
      )}
    </Card>
  )
}

/* ------------------------------------------------------------- automations */

export function ProjectAutomations({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const allowed = can.seeAllLeads(user)

  const { data, isLoading } = useQuery({
    queryKey: ['automation-rules'],
    queryFn: fetchRules,
    // Rules act on everybody's leads, so only people who can see everybody's
    // leads may read them; the API refuses the rest and the tab says so.
    enabled: allowed,
  })

  if (!allowed) {
    return (
      <Card>
        <CardBody><p className="t-caption">{t('projects.workspace.automationsRestricted')}</p></CardBody>
      </Card>
    )
  }

  if (isLoading) return <Skeleton className="h-32" />

  const rules = data?.rules ?? []

  /*
   * A rule belongs to a project when it says so in its conditions. The rest
   * apply to every project, and are counted rather than listed: a project page
   * that named twelve workspace-wide rules would bury the one written for it.
   */
  const scoped = rules.filter((rule) =>
    rule.conditions.some(
      (condition) =>
        condition.field === 'project_id' &&
        [condition.value].flat().map(String).includes(projectId),
    ),
  )

  const general = rules.filter((rule) => !scoped.includes(rule) && rule.is_enabled).length

  return (
    <Card>
      <CardHeader
        title={t('projects.workspace.automationsTitle')}
        description={t('projects.workspace.automationsGeneral', { count: general })}
        action={
          <Link to="/automation" className="t-small text-[var(--brand)] hover:underline">
            {t('projects.workspace.manageRules')}
          </Link>
        }
      />
      <CardBody>
        {scoped.length === 0 ? (
          <p className="t-caption" data-testid="project-rules-empty">{t('projects.workspace.noScopedRules')}</p>
        ) : (
          <ul className="space-y-2" data-testid="project-rules">
            {scoped.map((rule) => (
              <li key={rule.id} className="flex items-center justify-between gap-2">
                <span className="t-small flex items-center gap-2 font-medium">
                  <Zap className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
                  {rule.name}
                </span>
                <Badge tone={rule.is_enabled ? 'success' : 'neutral'}>
                  {rule.is_enabled ? t('projects.workspace.ruleOn') : t('projects.workspace.ruleOff')}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}

/* -------------------------------------------------------------- activities */

export function ProjectActivities({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['project-activity', projectId],
    queryFn: () => fetchProjectActivity(projectId),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const activities = data ?? []

  return (
    <Card>
      <CardHeader title={t('projects.workspace.activitiesTitle')} />
      {activities.length === 0 ? (
        <EmptyState title={t('projects.workspace.noActivity')} />
      ) : (
        <CardBody>
          <ul className="space-y-2.5" data-testid="project-activity">
            {activities.map((activity) => (
              <li key={activity.id} className="flex items-start justify-between gap-3">
                <span className="min-w-0">
                  <span className="t-small font-medium">
                    {t(`activity.${activity.type}`, { defaultValue: activity.type })}
                  </span>
                  {activity.lead && (
                    <>
                      {' · '}
                      <Link to={`/leads/${activity.lead.id}`} className="t-small hover:text-[var(--brand)]">
                        {activity.lead.name}
                      </Link>
                    </>
                  )}
                  <span className="t-caption block">
                    {activity.automated ? (
                      <span className="inline-flex items-center gap-1">
                        <Bot className="size-3.5" aria-hidden />
                        {t('projects.workspace.automated')}
                      </span>
                    ) : (
                      activity.by
                    )}
                  </span>
                </span>
                <span className="t-caption shrink-0">{formatRelative(activity.at, i18n.language)}</span>
              </li>
            ))}
          </ul>
        </CardBody>
      )}
    </Card>
  )
}

/* ----------------------------------------------------------------- reports */

export function ProjectReports({ projectId }: { projectId: string }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-3">
      <ProjectNumbers projectId={projectId} />
      <Card>
        <CardBody className="flex flex-wrap items-center justify-between gap-2">
          <p className="t-caption">{t('projects.workspace.reportsHint')}</p>
          <Link to="/reports" className="t-small text-[var(--brand)] hover:underline">
            {t('projects.workspace.openReports')}
          </Link>
        </CardBody>
      </Card>
    </div>
  )
}

/* ------------------------------------------------------------ integrations */

export function ProjectIntegrations({ projectId }: { projectId: string }) {
  const { t } = useTranslation()

  const platforms = useQuery({
    queryKey: ['project-platforms', projectId],
    queryFn: () => fetchProjectReport(projectId, 'platform'),
  })

  if (platforms.isLoading) return <Skeleton className="h-32" />

  const rows = platforms.data?.rows ?? []

  return (
    <Card>
      <CardHeader
        title={t('projects.workspace.integrationsTitle')}
        description={t('projects.workspace.integrationsHint')}
        action={
          <Link to="/integrations" className="t-small text-[var(--brand)] hover:underline">
            {t('projects.workspace.manageSources')}
          </Link>
        }
      />
      <CardBody>
        {rows.length === 0 ? (
          <p className="t-caption" data-testid="project-sources-empty">{t('projects.workspace.noSources')}</p>
        ) : (
          <ul className="space-y-2" data-testid="project-sources">
            {rows.map((row) => (
              <li key={row.key} className="flex items-center justify-between gap-2">
                <span className="t-small flex items-center gap-2 font-medium">
                  <Plug className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
                  {t(`platforms.${row.label}`, { defaultValue: row.label })}
                </span>
                <span className="t-caption tabular">
                  {t('projects.workspace.leadsFrom', { count: row.total })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
