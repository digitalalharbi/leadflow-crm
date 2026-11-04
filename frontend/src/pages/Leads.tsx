import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { LastUpdated } from '@/components/ui/LastUpdated'
import { Download, MessageCircle, Phone, Plug, Plus, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { InterestBadge, RingProgress } from '@/components/visual'
import { ExportsModal } from '@/components/exports/ExportsModal'
import { fetchAssignableUsers, fetchLeads, fetchProjects, type LeadFilters } from '@/features/leads'
import { can, useAuth, useEntitled } from '@/stores/auth'
import { useDebounced } from '@/hooks/useDebounced'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'
import { formatDue, formatRelative, telLink, whatsappLink } from '@/lib/format'
import { CLASSIFICATION_COLOR } from '@/components/visual/classification'
import { LeadFiltersBar } from '@/components/leads/LeadFilters'
import { LeadAvatar } from '@/components/leads/LeadAvatar'
import { LeadsOverview } from '@/components/leads/LeadsOverview'
import { LeadSegments } from '@/components/leads/LeadSegments'
import { NextActionChip } from '@/components/leads/NextActionChip'
import { LeadFormDrawer } from '@/components/leads/LeadFormDrawer'
import {
  Button, Card, EmptyState, ErrorState, PageHeader, Pagination,
  SkeletonTable, StatusBadge, Table, TableWrap, Td, TemperatureBadge, Th, Tr,
} from '@/components/ui'
import { platformColor } from '@/components/charts'

export function LeadsPage() {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const translateError = useApiError()
  const [searchParams] = useSearchParams()

  // `?new=1` is how the command palette's "create a lead" lands here: the form is already open.
  const [createOpen, setCreateOpen] = useState(() => searchParams.get('new') === '1')
  const [exportOpen, setExportOpen] = useState(false)
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounced(search)

  // Deep links from the dashboard pre-seed the filters, so every tile on the
  // "today" row lands on exactly the list it names.
  const [filters, setFilters] = useState<LeadFilters>(() => {
    const multi = (key: string) => {
      // Both spellings: `?status[]=new` from the filters and `?temperature=hot`
      // from the links the command center and dashboard have always written.
      const values = [...searchParams.getAll(`${key}[]`), ...searchParams.getAll(key)]
      return values.length > 0 ? values : undefined
    }

    return {
      page: 1,
      per_page: 25,
      status: multi('status'),
      temperature: multi('temperature'),
      classification: multi('classification'),
      platform: multi('platform'),
      project_id: searchParams.get('project_id') ?? undefined,
      assigned_to: searchParams.get('assigned_to') ?? undefined,
      follow_up: searchParams.get('follow_up') ?? undefined,
      tag: searchParams.get('tag') ?? undefined,
      date_from: searchParams.get('date_from') ?? undefined,
      date_to: searchParams.get('date_to') ?? undefined,
      overdue: searchParams.get('overdue') === '1' || undefined,
      priority: searchParams.get('priority') === '1' || undefined,
      wrong_fit: searchParams.get('wrong_fit') === '1' || undefined,
      /*
       * The drill-down from the data quality screen. Without this the URL
       * carries the filter, the list ignores it, and the page shows every lead
       * under a heading that promised ten — the count and its list disagreeing
       * in the one place a manager went looking for the difference.
       */
      data_issue: searchParams.get('data_issue') ?? undefined,
      // The command center's "stalled" row and the saved lists link here.
      view: searchParams.get('view') ?? undefined,
      unassigned: searchParams.get('unassigned') === 'true' || undefined,
    }
  })

  const query = useMemo(
    () => ({ ...filters, search: debouncedSearch || undefined }),
    [filters, debouncedSearch],
  )

  const { data, isLoading, isError, error, refetch, isFetching, dataUpdatedAt } = useQuery({
    queryKey: ['leads', query],
    queryFn: () => fetchLeads(query),
    placeholderData: (previous) => previous,
  })

  const { data: projects = [] } = useQuery({ queryKey: ['projects'], queryFn: fetchProjects })
  const { data: users = [] } = useQuery({
    queryKey: ['assignable-users'],
    queryFn: fetchAssignableUsers,
    enabled: can.seeAllLeads(user),
  })

  const leads = data?.data ?? []
  const isFiltered =
    Boolean(debouncedSearch) ||
    Boolean(filters.status?.length) ||
    Boolean(filters.temperature?.length) ||
    Boolean(filters.classification?.length) ||
    Boolean(filters.platform?.length) ||
    Boolean(filters.project_id) ||
    Boolean(filters.assigned_to) ||
    Boolean(filters.follow_up) ||
    Boolean(filters.tag) ||
    Boolean(filters.overdue) ||
    Boolean(filters.priority) ||
    Boolean(filters.wrong_fit) ||
    Boolean(filters.data_issue) ||
    Boolean(filters.view) ||
    Boolean(filters.unassigned)

  const exportsOn = useEntitled('exports')

  function resetFilters() {
    setSearch('')
    setFilters({ page: 1, per_page: 25 })
  }

  return (
    <>
      <LastUpdated at={dataUpdatedAt} />
      <PageHeader
        title={t('leads.title')}
        subtitle={t('leads.subtitle')}
        actions={
          <>
            {can.seeAllLeads(user) && exportsOn && (
              <Button
                variant="outline"
                icon={<Download className="size-4" />}
                onClick={() => setExportOpen(true)}
                data-testid="leads-export"
              >
                {t('common.export')}
              </Button>
            )}
            <Button icon={<Plus className="size-4" />} onClick={() => setCreateOpen(true)}>
              {t('leads.addLead')}
            </Button>
          </>
        }
      />

      {/* The picture first; the table is for acting on it. */}
      <LeadsOverview query={query as Record<string, unknown>} />

      <Card className="overflow-hidden">
        {/* Which of these needs me — before a single row is read. */}
        <LeadSegments
          filters={filters}
          onChange={(next) => setFilters((current) => ({ ...current, ...next }))}
          showUnassigned={can.seeAllLeads(user)}
        />

        <LeadFiltersBar
          filters={filters}
          onChange={(next) => setFilters((current) => ({ ...current, ...next }))}
          onReset={resetFilters}
          projects={projects}
          users={users}
          showAssignee={can.seeAllLeads(user)}
          search={search}
          onSearchChange={(value) => {
            setSearch(value)
            setFilters((current) => ({ ...current, page: 1 }))
          }}
        />

        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={8} cols={7} />
        ) : leads.length === 0 ? (
          <EmptyState
            icon={<Users2 className="size-6" />}
            title={isFiltered ? t('leads.emptyFiltered') : t('leads.emptyTitle')}
            body={isFiltered ? undefined : t('leads.emptyBody')}
            actions={
              isFiltered ? (
                <Button variant="outline" onClick={resetFilters}>{t('common.clearFilters')}</Button>
              ) : (
                <>
                  <Button icon={<Plus className="size-4" />} onClick={() => setCreateOpen(true)}>
                    {t('leads.addLead')}
                  </Button>
                  {can.manageIntegrations(user) && (
                    <Link to="/integrations">
                      <Button variant="outline" icon={<Plug className="size-4" />}>
                        {t('leads.connectSource')}
                      </Button>
                    </Link>
                  )}
                </>
              )
            }
          />
        ) : (
          <>
            {/*
              The list scrolls inside itself, so the page does not grow with it.
              Twenty-five leads used to make this screen 1709px tall against a
              768px laptop: the filters, the header and the pager all left the
              viewport, and finding a lead meant scrolling back up to change the
              filter that would have found it.

              Capped from `md` up only — on a phone there is not enough height
              left over for an inner scroller to be anything but a pinhole, and
              the page scrolling normally is the right behaviour there.
            */}
            <TableWrap
              className={cn(
                'md:max-h-[34rem] md:overflow-y-auto',
                isFetching && 'opacity-60 transition-opacity',
              )}
              data-testid="leads-table-scroller"
            >
              <Table stickyHeader>
                <thead>
                  <tr>
                    {/* The identity column carries name, phone and project
                        together — three columns of one-line facts made the eye
                        travel the full width of the table for one lead. */}
                    <Th className="min-w-52">{t('leads.name')}</Th>
                    <Th className="hidden sm:table-cell">{t('leads.score')}</Th>
                    <Th className="hidden md:table-cell">{t('leads.status')}</Th>
                    <Th className="hidden xl:table-cell">{t('leads.project')}</Th>
                    <Th className="hidden lg:table-cell">{t('leads.source')}</Th>
                    <Th className="hidden xl:table-cell">{t('leads.lastContact')}</Th>
                    <Th className="hidden lg:table-cell">{t('leads.nextStep')}</Th>
                    <Th className="text-end">{t('common.actions')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {leads.map((lead) => (
                    <Tr key={lead.id}>
                      <Td className="min-w-56">
                        <div className="flex items-center gap-3">
                          <LeadAvatar name={lead.full_name} temperature={lead.temperature} priority={lead.is_priority} />
                          <div className="min-w-0">
                        <Link
                          to={`/leads/${lead.id}`}
                          className="flex items-center gap-1.5 truncate font-medium hover:text-[var(--brand)]"
                        >
                          {lead.classification && (
                            <span
                              className="size-2 shrink-0 rounded-full"
                              style={{ background: CLASSIFICATION_COLOR[lead.classification.key] }}
                              title={t(`classification.${lead.classification.key}`)}
                              aria-label={t(`classification.${lead.classification.key}`)}
                            />
                          )}
                          <span className="truncate">{lead.full_name}</span>
                        </Link>

                        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                          {lead.phone && (
                            <span className="tabular text-[0.875rem] text-[var(--color-ink-muted)]" dir="ltr">
                              {lead.phone}
                            </span>
                          )}
                          {lead.project?.name && (
                            <span className="t-caption max-w-40 truncate text-[0.875rem] xl:hidden">
                              {lead.project.name}
                            </span>
                          )}
                        </span>

                        {/* Below `sm` the badges have nowhere else to live. */}
                        <span className="mt-1.5 flex flex-wrap items-center gap-1 sm:hidden">
                          <StatusBadge status={lead.status} />
                          <TemperatureBadge temperature={lead.temperature} priority={lead.is_priority} />
                          <NextActionChip action={lead.next_action} />
                        </span>
                          </div>
                        </div>
                      </Td>

                      <Td className="hidden sm:table-cell">
                        {/* The score as a number with its bar: how far into its bucket. */}
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1" data-testid="lead-score">
                          <span className="w-7 text-[1.0625rem] font-bold tabular-nums">{lead.score ?? 0}</span>
                          <span className="block h-1.5 w-14 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]" aria-hidden>
                            <span className="block h-full rounded-full" style={{ width: `${Math.min(100, lead.score ?? 0)}%`, background: `var(--color-temp-${lead.temperature})` }} />
                          </span>
                          {lead.interest_level && lead.interest_level !== 'cold' && <InterestBadge level={lead.interest_level} testId="lead-interest" />}
                          {lead.health && (
                            <span title={t('intel.health')} data-testid="lead-health" data-level={lead.health.level}>
                              <RingProgress value={lead.health.value} size={26} thickness={4} tone={lead.health.level === 'high' ? 'good' : lead.health.level === 'medium' ? 'warn' : 'bad'} label={t('intel.health')} />
                            </span>
                          )}
                        </span>
                      </Td>
                      <Td className="hidden md:table-cell"><StatusBadge status={lead.status} /></Td>
                      <Td className="hidden max-w-36 truncate xl:table-cell text-[0.9375rem]">
                        {lead.project?.name ?? <span className="text-[var(--color-ink-subtle)]">—</span>}
                      </Td>
                      <Td className="hidden lg:table-cell">
                        {lead.platform ? (
                          <span className="flex items-center gap-1.5">
                            <span
                              className="size-2 shrink-0 rounded-full"
                              style={{ background: platformColor(lead.platform) }}
                              aria-hidden
                            />
                            <span className="min-w-0">
                              <span className="block truncate text-[0.9375rem]">
                                {t(`platforms.${lead.platform}`, { defaultValue: lead.platform })}
                              </span>
                              {lead.campaign_name && (
                                <span className="t-caption block max-w-40 truncate text-[0.8125rem]">
                                  {lead.campaign_name}
                                </span>
                              )}
                            </span>
                          </span>
                        ) : (
                          <span className="text-[var(--color-ink-subtle)]">—</span>
                        )}
                      </Td>
                      <Td className="hidden whitespace-nowrap xl:table-cell text-[0.9375rem] text-[var(--color-ink-muted)]">
                        {lead.last_contact_at ? formatRelative(lead.last_contact_at, i18n.language) : <span className="text-[var(--color-ink-subtle)]">{t('leads.neverContacted')}</span>}
                      </Td>
                      <Td className="hidden lg:table-cell">
                        {/* What to do, and when it is due — the two things a rep scans a row for. */}
                        <NextActionChip action={lead.next_action} />
                        {lead.next_follow_up_at && (
                          <span
                            className={cn(
                              'mt-0.5 block whitespace-nowrap text-[0.875rem]',
                              lead.is_overdue ? 'font-medium text-[var(--color-status-lost)]' : 'text-[var(--color-ink-muted)]',
                            )}
                          >
                            {formatDue(lead.next_follow_up_at, i18n.language, lead.is_overdue)}
                          </span>
                        )}
                        {!lead.next_follow_up_at && (!lead.next_action || ['wait', 'none'].includes(lead.next_action.action)) && '—'}
                      </Td>
                      <Td>
                        <div className="flex items-center justify-end gap-1">
                          {lead.phone && (
                            <a
                              href={telLink(lead.phone) ?? '#'}
                              aria-label={t('leads.call')}
                              className="grid size-8 place-items-center rounded-[var(--radius-control)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--brand)]"
                            >
                              <Phone className="size-4" aria-hidden />
                            </a>
                          )}
                          {lead.whatsapp && (
                            <a
                              href={
                                whatsappLink(lead.whatsapp, branding ? undefined : null, {}) ?? '#'
                              }
                              target="_blank"
                              rel="noreferrer"
                              aria-label={t('leads.whatsapp')}
                              className="grid size-8 place-items-center rounded-[var(--radius-control)] text-[var(--color-ink-muted)] hover:bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)] hover:text-[var(--color-status-won)]"
                            >
                              <MessageCircle className="size-4" aria-hidden />
                            </a>
                          )}
                        </div>
                      </Td>
                    </Tr>
                  ))}
                </tbody>
              </Table>
            </TableWrap>

            <Pagination
              meta={data?.meta}
              onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            />
          </>
        )}
      </Card>

      <LeadFormDrawer open={createOpen} onClose={() => setCreateOpen(false)} />
      <ExportsModal open={exportOpen} onClose={() => setExportOpen(false)} filters={query as Record<string, unknown>} />
    </>
  )
}
