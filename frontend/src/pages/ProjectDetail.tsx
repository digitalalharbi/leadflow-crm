import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, GitBranch, GitMerge, GitPullRequest, Rocket, Tag } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, EmptyState, ErrorState, Input,
  PageHeader, Skeleton, Table, TableWrap, Tabs, Td, Th, Tr,
} from '@/components/ui'
import {
  fetchDevelopment, fetchRepositories, linkRepository, syncGitHub,
  type DevelopmentActivity, type DevelopmentDeployment, type DevelopmentRun,
} from '@/features/development'
import { ProjectFiles } from '@/components/projects/ProjectFiles'
import {
  ProjectActivities, ProjectAutomations, ProjectCampaigns, ProjectIntegrations,
  ProjectLeads, ProjectNumbers, ProjectReports,
} from '@/components/projects/ProjectWorkspace'
import type { Project } from '@/types/api'

/**
 * One project, and what is happening to it.
 *
 * Two tabs today and no pretend ones: what the project is, and what its code is
 * doing. A row of tabs that mostly lead to "coming soon" teaches people not to
 * click tabs.
 */
/**
 * The project's workspace, in the order somebody works through it: the numbers,
 * the people, the material, where the leads come from, what acts on them, what
 * happened, how it is going — and, last, how the thing is being built, which is
 * for a different reader and was the only tab there was.
 */
const TABS = [
  'overview', 'leads', 'files', 'campaigns', 'automations',
  'activities', 'reports', 'integrations', 'development',
] as const

type ProjectTab = (typeof TABS)[number]

export function ProjectDetailPage() {
  const { id = '' } = useParams()
  const { t } = useTranslation()
  /*
   * Overview, which is both the first tab in the row and the one this screen is
   * about. It opened on Development — a convenience from the week that view was
   * built, left in: a sales manager clicking a project landed on repositories,
   * CI runs and deployments, with the tab row pointing at the tab they were not
   * on.
   */
  const [tab, setTab] = useState<ProjectTab>('overview')

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', id],
    queryFn: () => apiGet<{ project: Project }>(`/projects/${id}`).then((r) => r.project),
  })

  return (
    <>
      <PageHeader
        title={project?.name ?? t('projects.title')}
        subtitle={project?.location ?? undefined}
      />

      <Tabs
        className="mb-3"
        testId="project-tabs"
        value={tab}
        onChange={setTab}
        items={TABS.map((key) => ({ key, label: t(`projects.tabs.${key}`) }))}
      />

      {tab === 'overview' && (
        <div className="space-y-3">
          <ProjectNumbers projectId={id} />

          <Card>
            <CardBody>
              {isLoading ? (
                <Skeleton className="h-24" />
              ) : (
                <dl className="grid gap-4 sm:grid-cols-3">
                  <Fact label={t('projects.location')} value={project?.location ?? '—'} />
                  <Fact label={t('projects.developer')} value={project?.developer_name ?? '—'} />
                  <Fact
                    label={t('common.status')}
                    value={project ? t(`projects.status.${project.status}`, { defaultValue: project.status }) : '—'}
                  />
                </dl>
              )}
            </CardBody>
          </Card>
        </div>
      )}

      {tab === 'leads' && <ProjectLeads projectId={id} />}
      {tab === 'files' && <ProjectFiles projectId={id} />}
      {tab === 'campaigns' && <ProjectCampaigns projectId={id} />}
      {tab === 'automations' && <ProjectAutomations projectId={id} />}
      {tab === 'activities' && <ProjectActivities projectId={id} />}
      {tab === 'reports' && <ProjectReports projectId={id} />}
      {tab === 'integrations' && <ProjectIntegrations projectId={id} />}
      {tab === 'development' && <Development projectId={id} />}
    </>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="t-caption">{label}</dt>
      <dd className="t-small font-medium">{value}</dd>
    </div>
  )
}

/**
 * The development view.
 *
 * Five questions, in the order a project manager asks them: is this wired up,
 * is the build green, did it reach the server, what version is live, and what
 * changed. Anything that does not answer one of them is noise that makes the
 * five harder to find — which is why this is not a GitHub dashboard.
 */
function Development({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['development', projectId],
    queryFn: () => fetchDevelopment(projectId),
  })

  const sync = useMutation({
    mutationFn: () => syncGitHub(data?.repository?.id),
    onSuccess: () => toast(t('development.sweepQueued'), 'success'),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-64" />
  if (isError) return <ErrorState message={translateError(error)} onRetry={() => void refetch()} />

  if (!data?.repository) {
    return <LinkRepository projectId={projectId} onLinked={() => void queryClient.invalidateQueries({ queryKey: ['development', projectId] })} />
  }

  const repository = data.repository

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={repository.full_name}
          action={
            <div className="flex items-center gap-2">
              {can.manageProjects(user) && (
                <Button variant="outline" size="sm" loading={sync.isPending} onClick={() => sync.mutate()}>
                  {t('development.sync')}
                </Button>
              )}
              <a
                href={repository.url}
                target="_blank"
                rel="noreferrer noopener"
                className="t-small inline-flex items-center gap-1 hover:underline"
              >
                GitHub <ExternalLink className="size-3.5" />
              </a>
            </div>
          }
        />
        <CardBody>
          <div className="flex flex-wrap items-center gap-3">
            <ReadinessBadge readiness={repository.readiness} />
            <span className="t-caption inline-flex items-center gap-1">
              <GitBranch className="size-3.5" />
              <bdi dir="ltr">{repository.production_branch ?? repository.default_branch}</bdi>
            </span>
            {repository.develop_branch && (
              <span className="t-caption inline-flex items-center gap-1">
                <GitBranch className="size-3.5" />
                <bdi dir="ltr">{repository.develop_branch}</bdi>
              </span>
            )}
            <span className="t-caption">
              {repository.last_webhook_at
                ? t('development.lastHeard', { when: formatLastSeen(repository.last_webhook_at, i18n.language) })
                : t('development.neverHeard')}
            </span>
          </div>
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        <RunCard title={t('development.staging')} run={data.ci?.staging ?? null} />
        <RunCard title={t('development.production')} run={data.ci?.production ?? null} />
        <ReleaseCard release={data.release ?? null} />
      </div>

      {(data.deployments ?? []).length > 0 && (
        <Card>
          <CardHeader title={t('development.deployments')} />
          <CardBody>
            <ul className="space-y-2" data-testid="deployments">
              {data.deployments?.map((deployment) => (
                <DeploymentRow key={deployment.environment} deployment={deployment} />
              ))}
            </ul>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title={t('development.pullRequests')} />
        {(data.pull_requests ?? []).length === 0 ? (
          <CardBody>
            <p className="t-caption">{t('development.noPullRequests')}</p>
          </CardBody>
        ) : (
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('development.pr')}</Th>
                  <Th className="hidden md:table-cell">{t('development.author')}</Th>
                  <Th className="hidden lg:table-cell">{t('development.branch')}</Th>
                  <Th>{t('common.status')}</Th>
                </tr>
              </thead>
              <tbody>
                {data.pull_requests?.map((pr) => (
                  <Tr key={pr.number}>
                    <Td>
                      <a href={pr.url} target="_blank" rel="noreferrer noopener" className="font-medium hover:underline">
                        <bdi dir="ltr">#{pr.number}</bdi> {pr.title}
                      </a>
                    </Td>
                    <Td className="hidden md:table-cell">
                      <bdi dir="ltr">{pr.author ?? '—'}</bdi>
                    </Td>
                    <Td className="t-caption hidden lg:table-cell">
                      <bdi dir="ltr">{pr.head_branch} → {pr.base_branch}</bdi>
                    </Td>
                    <Td>
                      <Badge tone={PR_TONE[pr.readiness]} testId={`pr-${pr.number}-readiness`}>
                        {t(`development.readiness.${pr.readiness}`)}
                      </Badge>
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          </TableWrap>
        )}
      </Card>

      <Card>
        <CardHeader title={t('development.activity')} />
        <CardBody>
          {(data.activity ?? []).length === 0 ? (
            <p className="t-caption">{t('development.noActivity')}</p>
          ) : (
            <ol className="space-y-3" data-testid="development-activity">
              {data.activity?.map((row) => (
                <ActivityRow key={row.id} row={row} />
              ))}
            </ol>
          )}
        </CardBody>
      </Card>
    </div>
  )
}

const PR_TONE: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  ready_to_merge: 'success',
  merged: 'success',
  ci_failed: 'danger',
  changes_requested: 'warning',
  waiting_on_ci: 'info',
  draft: 'neutral',
  closed: 'neutral',
  open: 'neutral',
}

function ReadinessBadge({ readiness }: { readiness: { state: string; reasons: string[] } }) {
  const { t } = useTranslation()

  return (
    <Badge
      tone={readiness.state === 'ready' ? 'success' : readiness.state === 'partial' ? 'warning' : 'danger'}
      testId="repository-readiness"
    >
      {t(`development.repoState.${readiness.state}`)}
      {readiness.reasons.length > 0 && (
        <span className="opacity-80">
          {' '}· {readiness.reasons.map((reason) => t(`development.reason.${reason}`, { defaultValue: reason })).join('، ')}
        </span>
      )}
    </Badge>
  )
}

function RunCard({ title, run }: { title: string; run: DevelopmentRun | null }) {
  const { t, i18n } = useTranslation()

  return (
    <Card>
      <CardHeader title={title} />
      <CardBody>
        {!run ? (
          <p className="t-caption">{t('development.noRuns')}</p>
        ) : (
          <div className="space-y-1.5">
            <Badge tone={RUN_TONE[run.outcome] ?? 'neutral'} testId={`run-${title}`}>
              {t(`development.outcome.${run.outcome}`)}
            </Badge>
            <p className="t-small font-medium">{run.name}</p>
            <p className="t-caption">
              {run.completed_at ? formatLastSeen(run.completed_at, i18n.language) : '—'}
            </p>
          </div>
        )}
      </CardBody>
    </Card>
  )
}

const RUN_TONE: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  passed: 'success',
  failed: 'danger',
  running: 'info',
  queued: 'info',
  cancelled: 'neutral',
  // Not success. A skipped job reports success and shipped nothing — the exact
  // shape of the pipeline that showed green while the server was untouched.
  skipped: 'warning',
  unknown: 'neutral',
}

function ReleaseCard({ release }: { release: { tag: string; name: string | null; url: string; published_at: string | null } | null }) {
  const { t, i18n } = useTranslation()

  return (
    <Card>
      <CardHeader title={t('development.release')} />
      <CardBody>
        {!release ? (
          <p className="t-caption">{t('development.noRelease')}</p>
        ) : (
          <div className="space-y-1.5">
            <a href={release.url} target="_blank" rel="noreferrer noopener" className="t-h3 inline-flex items-center gap-1.5 hover:underline">
              <Tag className="size-4" />
              <bdi dir="ltr">{release.tag}</bdi>
            </a>
            <p className="t-caption">
              {release.published_at ? formatLastSeen(release.published_at, i18n.language) : '—'}
            </p>
          </div>
        )}
      </CardBody>
    </Card>
  )
}

/**
 * The row the whole feature exists for.
 *
 * `reported` and `verified` are shown as different things on purpose: GitHub's
 * success means the deploy job finished, and nobody has asked the application
 * whether it answers.
 */
function DeploymentRow({ deployment }: { deployment: DevelopmentDeployment }) {
  const { t, i18n } = useTranslation()

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] pb-2 last:border-0 last:pb-0">
      <span className="t-small inline-flex items-center gap-1.5 font-medium">
        <Rocket className="size-4" />
        {deployment.environment}
      </span>
      <div className="flex items-center gap-2">
        <Badge
          tone={deployment.truth === 'verified' ? 'success' : deployment.truth === 'failed' ? 'danger' : 'warning'}
          testId={`deployment-${deployment.environment}`}
        >
          {t(`development.truth.${deployment.truth}`)}
        </Badge>
        <span className="t-caption">{deployment.at ? formatLastSeen(deployment.at, i18n.language) : '—'}</span>
      </div>
    </li>
  )
}

const ACTIVITY_ICON: Record<string, typeof GitMerge> = {
  pr_merged: GitMerge,
  pr_opened: GitPullRequest,
  pr_closed: GitPullRequest,
  released: Tag,
  deployed: Rocket,
  deploy_failed: Rocket,
}

function ActivityRow({ row }: { row: DevelopmentActivity }) {
  const { t, i18n } = useTranslation()
  const Icon = ACTIVITY_ICON[row.kind] ?? GitBranch

  return (
    <li className="flex items-start gap-3">
      <Icon className="mt-0.5 size-4 shrink-0 text-[var(--color-ink-muted)]" />
      <div className="min-w-0">
        <p className="t-small">
          <span className="font-medium">{t(`development.kind.${row.kind}`)}</span>{' '}
          {row.url ? (
            <a href={row.url} target="_blank" rel="noreferrer noopener" className="hover:underline">
              {row.title}
            </a>
          ) : (
            row.title
          )}
        </p>
        <p className="t-caption">
          {[row.actor, row.ref, row.at ? formatLastSeen(row.at, i18n.language) : null]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </div>
    </li>
  )
}

/** Nothing linked yet. */
function LinkRepository({ projectId, onLinked }: { projectId: string; onLinked: () => void }) {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [search, setSearch] = useState('')

  const { data, isError } = useQuery({
    queryKey: ['github-repositories', search],
    queryFn: () => fetchRepositories(search),
    enabled: can.manageProjects(user),
    retry: false,
  })

  const link = useMutation({
    mutationFn: (fullName: string) => linkRepository(projectId, { full_name: fullName }),
    onSuccess: () => {
      toast(t('development.linked'), 'success')
      onLinked()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (!can.manageProjects(user)) {
    return <EmptyState title={t('development.notLinked')} body={t('development.notLinkedHint')} />
  }

  if (isError) {
    return (
      <EmptyState
        title={t('development.notConnected')}
        body={t('development.notConnectedHint')}
        actions={<Link to="/integrations" className="underline">{t('nav.integrations')}</Link>}
      />
    )
  }

  return (
    <Card>
      <CardHeader title={t('development.chooseRepository')} />
      <CardBody>
        <Input
          dir="ltr"
          label={t('common.search')}
          value={search}
          data-testid="repository-search"
          onChange={(event) => setSearch(event.target.value)}
        />

        <ul className="mt-3 space-y-1.5" data-testid="repository-choices">
          {data?.repositories.map((repository) => (
            <li key={repository.id} className="flex items-center justify-between gap-3">
              <span className="t-small">
                <bdi dir="ltr">{repository.full_name}</bdi>
              </span>
              <Button
                size="sm"
                variant="outline"
                loading={link.isPending}
                data-testid={`link-${repository.full_name}`}
                onClick={() => link.mutate(repository.full_name)}
              >
                {t('development.link')}
              </Button>
            </li>
          ))}
        </ul>
      </CardBody>
    </Card>
  )
}
