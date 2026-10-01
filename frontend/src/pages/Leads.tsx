import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, MessageCircle, Phone, Plug, Plus, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiDownload } from '@/lib/api'
import { fetchAssignableUsers, fetchLeads, fetchProjects, type LeadFilters } from '@/features/leads'
import { can, useAuth } from '@/stores/auth'
import { useDebounced } from '@/hooks/useDebounced'
import { useApiError } from '@/hooks/useApiError'
import { formatDue, telLink, whatsappLink } from '@/lib/format'
import { LeadFiltersBar } from '@/components/leads/LeadFilters'
import { LeadFormModal } from '@/components/leads/LeadFormModal'
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

  const [createOpen, setCreateOpen] = useState(false)
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounced(search)

  // Deep links from the dashboard pre-seed the filters, so every tile on the
  // "today" row lands on exactly the list it names.
  const [filters, setFilters] = useState<LeadFilters>(() => {
    const multi = (key: string) => {
      const values = searchParams.getAll(`${key}[]`)
      return values.length > 0 ? values : undefined
    }

    return {
      page: 1,
      per_page: 25,
      status: multi('status'),
      temperature: multi('temperature'),
      platform: multi('platform'),
      project_id: searchParams.get('project_id') ?? undefined,
      assigned_to: searchParams.get('assigned_to') ?? undefined,
      follow_up: searchParams.get('follow_up') ?? undefined,
      date_from: searchParams.get('date_from') ?? undefined,
      date_to: searchParams.get('date_to') ?? undefined,
      overdue: searchParams.get('overdue') === '1' || undefined,
    }
  })

  const query = useMemo(
    () => ({ ...filters, search: debouncedSearch || undefined }),
    [filters, debouncedSearch],
  )

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
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
    Boolean(filters.platform?.length) ||
    Boolean(filters.project_id) ||
    Boolean(filters.assigned_to) ||
    Boolean(filters.follow_up) ||
    Boolean(filters.overdue)

  function resetFilters() {
    setSearch('')
    setFilters({ page: 1, per_page: 25 })
  }

  return (
    <>
      <PageHeader
        title={t('leads.title')}
        subtitle={t('leads.subtitle')}
        actions={
          <>
            {can.seeAllLeads(user) && (
              <Button
                variant="outline"
                icon={<Download className="size-4" />}
                onClick={() =>
                  void apiDownload('/leads/export', query as Record<string, unknown>, 'leads.csv')
                }
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

      <Card className="overflow-hidden">
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
            <TableWrap className={isFetching ? 'opacity-60 transition-opacity' : undefined}>
              <Table>
                <thead>
                  <tr>
                    {/* The identity column carries name, phone and project
                        together — three columns of one-line facts made the eye
                        travel the full width of the table for one lead. */}
                    <Th className="min-w-56">{t('leads.name')}</Th>
                    <Th className="hidden lg:table-cell">{t('leads.source')}</Th>
                    <Th className="hidden md:table-cell">{t('leads.status')}</Th>
                    <Th className="hidden sm:table-cell">{t('leads.temperature')}</Th>
                    <Th className="hidden xl:table-cell">{t('leads.assignedTo')}</Th>
                    <Th className="hidden lg:table-cell">{t('leads.nextFollowUp')}</Th>
                    <Th className="text-end">{t('common.actions')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {leads.map((lead) => (
                    <Tr key={lead.id}>
                      <Td className="min-w-56">
                        <Link
                          to={`/leads/${lead.id}`}
                          className="block truncate font-medium hover:text-[var(--brand)]"
                        >
                          {lead.full_name}
                        </Link>

                        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                          {lead.phone && (
                            <span className="tabular text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
                              {lead.phone}
                            </span>
                          )}
                          {lead.project?.name && (
                            <span className="t-caption max-w-40 truncate text-[0.75rem]">
                              {lead.project.name}
                            </span>
                          )}
                        </span>

                        {/* Below `sm` the badges have nowhere else to live. */}
                        <span className="mt-1.5 flex flex-wrap items-center gap-1 sm:hidden">
                          <StatusBadge status={lead.status} />
                          <TemperatureBadge temperature={lead.temperature} />
                        </span>
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
                              <span className="block truncate text-[0.8125rem]">
                                {t(`platforms.${lead.platform}`, { defaultValue: lead.platform })}
                              </span>
                              {lead.campaign_name && (
                                <span className="t-caption block max-w-40 truncate text-[0.6875rem]">
                                  {lead.campaign_name}
                                </span>
                              )}
                            </span>
                          </span>
                        ) : (
                          <span className="text-[var(--color-ink-subtle)]">—</span>
                        )}
                      </Td>
                      <Td className="hidden md:table-cell"><StatusBadge status={lead.status} /></Td>
                      <Td className="hidden sm:table-cell"><TemperatureBadge temperature={lead.temperature} /></Td>
                      <Td className="hidden max-w-36 truncate xl:table-cell">
                        {lead.assignee?.name ?? (
                          <span className="text-[var(--color-ink-subtle)]">{t('common.unassigned')}</span>
                        )}
                      </Td>
                      <Td className="hidden whitespace-nowrap lg:table-cell">
                        {lead.next_follow_up_at ? (
                          <span className={lead.is_overdue ? 'font-medium text-[var(--color-status-lost)]' : undefined}>
                            {formatDue(lead.next_follow_up_at, i18n.language, lead.is_overdue)}
                          </span>
                        ) : (
                          '—'
                        )}
                      </Td>
                      <Td>
                        <div className="flex items-center justify-end gap-1">
                          {lead.phone && (
                            <a
                              href={telLink(lead.phone) ?? '#'}
                              aria-label={t('leads.call')}
                              className="grid size-8 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--brand)]"
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
                              className="grid size-8 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)] hover:text-[var(--color-status-won)]"
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

      <LeadFormModal open={createOpen} onClose={() => setCreateOpen(false)} />
    </>
  )
}
