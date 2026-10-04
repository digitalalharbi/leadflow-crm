import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Check, Copy, Plus, Search, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ApiError, apiGet, apiGetPaginated, apiPatch, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { useDebounced } from '@/hooks/useDebounced'
import { formatDate, formatNumber, formatRelative, initials } from '@/lib/format'
import { CURRENCIES, TIMEZONES } from '@/lib/locales'
import { MetricCard, DistributionBar, SERIES } from '@/components/charts'
import {
  Badge, Button, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Pagination, Select, SkeletonCards, SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import { cn } from '@/lib/cn'
import type { PaginationMeta, TenantSummary, UserRole } from '@/types/api'

interface PlatformStats {
  tenants: number
  active_tenants: number
  trialing: number
  users: number
  leads: number
  leads_last_30_days: number
  trials_ending_soon: number
  suspended: number
  by_plan: Array<{ key: string; count: number }>
}

interface TenantUser {
  id: string
  name: string
  email: string
  role: UserRole | null
  is_active: boolean
  last_login_at: string | null
}

type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'cancelled'

const SUBSCRIPTION_TONE: Record<SubscriptionStatus, 'info' | 'success' | 'warning' | 'danger'> = {
  trialing: 'info',
  active: 'success',
  past_due: 'warning',
  cancelled: 'danger',
}

/*
 * Everything a client's identity is made of, set here once. Onboarding a
 * customer is this form and nothing else — no code change, no migration, no
 * deploy. The tenant's admin can change any of it later in their own settings.
 */
const EMPTY_FORM = {
  company_name: '',
  system_name: '',
  subdomain: '',
  logo_url: '',
  primary_color: '#12a594',
  locale: 'ar',
  currency: 'SAR',
  timezone: 'Asia/Riyadh',
  admin_name: '',
  admin_email: '',
  admin_password: '',
}

export function PlatformPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [created, setCreated] = useState<{ url: string; secret: string } | null>(null)
  const [inspecting, setInspecting] = useState<TenantSummary | null>(null)

  const debouncedSearch = useDebounced(search)

  const { data: stats } = useQuery({
    queryKey: ['platform-stats'],
    queryFn: () => apiGet<PlatformStats>('/super-admin/stats'),
  })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['platform-tenants', page, debouncedSearch, status],
    queryFn: () =>
      apiGetPaginated<TenantSummary[]>('/super-admin/tenants', {
        page,
        per_page: 25,
        search: debouncedSearch || undefined,
        status: status || undefined,
      }) as Promise<{ data: TenantSummary[]; meta: PaginationMeta | undefined }>,
    placeholderData: (previous) => previous,
  })

  const create = useMutation({
    mutationFn: () =>
      apiPost<{ webhook: { url: string; secret: string } }>('/super-admin/tenants', {
        ...form,
        system_name: form.system_name || undefined,
        subdomain: form.subdomain || undefined,
        logo_url: form.logo_url || undefined,
      }),
    onSuccess: (result) => {
      setOpen(false)
      setForm(EMPTY_FORM)
      setCreated({ url: result.webhook.url, secret: result.webhook.secret })
      void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-stats'] })
      toast(t('superAdmin.created'), 'success')
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        const next: Record<string, string> = {}
        for (const [field, messages] of Object.entries(caught.errors)) {
          if (messages[0]) next[field] = messages[0]
        }
        setFieldErrors(next)
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    create.mutate()
  }

  const tenants = data?.data ?? []
  const filtered = Boolean(debouncedSearch || status)

  // Usage bars are read against the busiest account on the page, not against a
  // platform-wide maximum — otherwise every small tenant is an empty bar and
  // the column says nothing.
  const busiest = Math.max(1, ...tenants.map((tenant) => tenant.leads_last_30_days ?? 0))

  return (
    <>
      <PageHeader
        title={t('superAdmin.title')}
        subtitle={t('superAdmin.subtitle')}
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>
            {t('superAdmin.createTenant')}
          </Button>
        }
      />

      {/*
       * Four numbers, each already judged. "12 tenants" needs a reader to
       * remember what normal is; "12 active of 14, 2 suspended" does not.
       */}
      {stats ? (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <MetricCard
            label={t('superAdmin.activeTenants')}
            value={stats.active_tenants}
            tone="brand"
            hint={
              stats.suspended > 0
                ? t('superAdmin.ofTotalSuspended', { total: stats.tenants, suspended: stats.suspended })
                : t('superAdmin.ofTotal', { total: stats.tenants })
            }
            testId="platform-active-tenants"
          />
          <MetricCard
            label={t('superAdmin.trialing')}
            value={stats.trialing}
            tone={stats.trials_ending_soon > 0 ? 'warning' : 'neutral'}
            hint={
              stats.trials_ending_soon > 0
                ? t('superAdmin.endingSoon', { count: stats.trials_ending_soon })
                : t('superAdmin.noneEndingSoon')
            }
            testId="platform-trialing"
          />
          <MetricCard
            label={t('superAdmin.totalUsers')}
            value={stats.users}
            hint={t('superAdmin.acrossTenants', { count: stats.tenants })}
          />
          <MetricCard
            label={t('superAdmin.leadsLast30')}
            value={stats.leads_last_30_days}
            hint={t('superAdmin.ofAllTime', { total: formatNumber(stats.leads, i18n.language) })}
            testId="platform-leads-30"
          />
        </div>
      ) : (
        <div className="mb-5"><SkeletonCards count={4} /></div>
      )}

      {/* Which plans the book of business actually sits in. */}
      {stats && stats.by_plan.length > 1 && (
        <Card className="mb-5">
          <div className="border-b border-[var(--color-line)] px-4 py-3">
            <h2 className="t-card-title">{t('superAdmin.byPlan')}</h2>
          </div>
          <DistributionBar
            testId="platform-plan-split"
            segments={stats.by_plan.map((row, index) => ({
              key: row.key,
              label: row.key,
              count: row.count,
              share: Math.round((row.count / Math.max(stats.tenants, 1)) * 100),
              color: SERIES[index % SERIES.length],
            }))}
          />
        </Card>
      )}

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--color-line)] p-3">
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <Search
              className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-ink-subtle)]"
              aria-hidden
            />
            <Input
              className="ps-9"
              placeholder={t('superAdmin.searchPlaceholder')}
              aria-label={t('superAdmin.searchPlaceholder')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </div>

          <Select
            className="w-auto"
            aria-label={t('superAdmin.subscription')}
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPage(1)
            }}
          >
            <option value="">{t('superAdmin.allSubscriptions')}</option>
            <option value="trialing">{t('superAdmin.status.trialing')}</option>
            <option value="active">{t('superAdmin.status.active')}</option>
            <option value="past_due">{t('superAdmin.status.past_due')}</option>
            <option value="cancelled">{t('superAdmin.status.cancelled')}</option>
          </Select>

          <span className="t-caption ms-auto tabular">
            {t('superAdmin.tenantCount', { count: data?.meta?.total ?? tenants.length })}
          </span>
        </div>

        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={6} cols={5} />
        ) : tenants.length === 0 ? (
          <EmptyState
            icon={<Building2 className="size-6" />}
            title={filtered ? t('superAdmin.noMatches') : t('superAdmin.tenants')}
            body={filtered ? t('superAdmin.noMatchesBody') : undefined}
            actions={
              filtered ? (
                <Button
                  variant="ghost"
                  onClick={() => {
                    setSearch('')
                    setStatus('')
                  }}
                >
                  {t('leads.clearFilters')}
                </Button>
              ) : (
                <Button onClick={() => setOpen(true)}>{t('superAdmin.createTenant')}</Button>
              )
            }
          />
        ) : (
          <>
            <TableWrap>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('superAdmin.tenant')}</Th>
                    <Th>{t('superAdmin.subscription')}</Th>
                    <Th className="hidden md:table-cell">{t('superAdmin.usage')}</Th>
                    <Th className="hidden lg:table-cell">{t('superAdmin.size')}</Th>
                    <Th className="text-end">{t('common.status')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {tenants.map((tenant) => (
                    <Tr
                      key={tenant.id}
                      className="cursor-pointer"
                      onClick={() => setInspecting(tenant)}
                      data-testid={`tenant-row-${tenant.slug}`}
                    >
                      <Td>
                        <TenantIdentity tenant={tenant} />
                      </Td>

                      {/* Plan and the date that decides whether it keeps paying. */}
                      <Td>
                        <Badge tone={SUBSCRIPTION_TONE[tenant.subscription_status as SubscriptionStatus] ?? 'neutral'}>
                          {t(`superAdmin.status.${tenant.subscription_status}`, tenant.subscription_status)}
                        </Badge>
                        {/* Plan and expiry are two facts, so they get two
                            lines rather than one that breaks mid-phrase. */}
                        <span className="t-caption mt-1 block whitespace-nowrap">{tenant.plan}</span>
                        {tenant.trial_ends_at && (
                          <span className="t-caption block whitespace-nowrap">
                            {t('superAdmin.trialEnds', { date: formatDate(tenant.trial_ends_at, i18n.language) })}
                          </span>
                        )}
                      </Td>

                      <Td className="hidden md:table-cell">
                        <UsageBar value={tenant.leads_last_30_days ?? 0} peak={busiest} />
                        <span className="t-caption mt-1 block whitespace-nowrap">
                          {tenant.last_lead_at
                            ? t('superAdmin.lastLead', { when: formatRelative(tenant.last_lead_at, i18n.language) })
                            : t('superAdmin.noLeadsYet')}
                        </span>
                      </Td>

                      <Td className="hidden lg:table-cell">
                        <span className="tabular block whitespace-nowrap text-[0.8125rem]">
                          {t('superAdmin.usersCount', { count: tenant.users_count ?? 0 })}
                        </span>
                        <span className="t-caption tabular mt-1 block whitespace-nowrap">
                          {t('superAdmin.leadsCount', { count: tenant.leads_count ?? 0 })}
                        </span>
                      </Td>

                      <Td className="text-end">
                        <Badge tone={tenant.is_active ? 'success' : 'danger'}>
                          {tenant.is_active ? t('common.active') : t('superAdmin.suspended')}
                        </Badge>
                      </Td>
                    </Tr>
                  ))}
                </tbody>
              </Table>
            </TableWrap>

            <Pagination meta={data?.meta} onPageChange={setPage} />
          </>
        )}
      </Card>

      <TenantPanel tenant={inspecting} onClose={() => setInspecting(null)} />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={t('superAdmin.createTenant')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
            <Button type="submit" form="tenant-form" loading={create.isPending}>{t('common.create')}</Button>
          </>
        }
      >
        <form id="tenant-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Input
            required
            label={t('superAdmin.companyName')}
            value={form.company_name}
            error={fieldErrors.company_name}
            onChange={(e) => setForm((prev) => ({ ...prev, company_name: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label={t('superAdmin.systemName')}
              hint={t('common.optional')}
              value={form.system_name}
              onChange={(e) => setForm((prev) => ({ ...prev, system_name: e.target.value }))}
            />
            <Input
              dir="ltr"
              label={t('superAdmin.subdomain')}
              hint={t('common.optional')}
              value={form.subdomain}
              error={fieldErrors.subdomain}
              onChange={(e) =>
                setForm((prev) => ({ ...prev, subdomain: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') }))
              }
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              type="url"
              label={t('superAdmin.logoUrl')}
              hint={t('common.optional')}
              placeholder="https://"
              value={form.logo_url}
              error={fieldErrors.logo_url}
              onChange={(e) => setForm((prev) => ({ ...prev, logo_url: e.target.value }))}
            />
            {/* A colour picker alone hides the value; the operator usually has a
                brand hex to paste, so show both and keep them in step. */}
            <div>
              <label className="mb-1.5 block text-sm font-medium text-[var(--color-ink)]">
                {t('superAdmin.primaryColor')}
              </label>
              <div className="flex items-center gap-2">
                <input
                  type="color"
                  aria-label={t('superAdmin.primaryColor')}
                  className="h-10 w-12 shrink-0 cursor-pointer rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] p-1"
                  value={form.primary_color}
                  onChange={(e) => setForm((prev) => ({ ...prev, primary_color: e.target.value }))}
                />
                <Input
                  dir="ltr"
                  className="flex-1"
                  value={form.primary_color}
                  error={fieldErrors.primary_color}
                  onChange={(e) => setForm((prev) => ({ ...prev, primary_color: e.target.value }))}
                />
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <Select
              label={t('settings.language')}
              value={form.locale}
              onChange={(e) => setForm((prev) => ({ ...prev, locale: e.target.value }))}
            >
              <option value="ar">العربية</option>
              <option value="en">English</option>
            </Select>
            <Select
              label={t('settings.currency')}
              value={form.currency}
              onChange={(e) => setForm((prev) => ({ ...prev, currency: e.target.value }))}
            >
              {CURRENCIES.map((code) => (
                <option key={code} value={code}>
                  {code}
                </option>
              ))}
            </Select>
            <Select
              label={t('settings.timezone')}
              value={form.timezone}
              onChange={(e) => setForm((prev) => ({ ...prev, timezone: e.target.value }))}
            >
              {TIMEZONES.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </Select>
          </div>

          <Input
            required
            label={t('superAdmin.adminName')}
            value={form.admin_name}
            error={fieldErrors.admin_name}
            onChange={(e) => setForm((prev) => ({ ...prev, admin_name: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              required
              dir="ltr"
              type="email"
              label={t('superAdmin.adminEmail')}
              value={form.admin_email}
              error={fieldErrors.admin_email}
              onChange={(e) => setForm((prev) => ({ ...prev, admin_email: e.target.value }))}
            />
            <Input
              required
              dir="ltr"
              type="password"
              autoComplete="new-password"
              label={t('superAdmin.adminPassword')}
              value={form.admin_password}
              error={fieldErrors.admin_password}
              onChange={(e) => setForm((prev) => ({ ...prev, admin_password: e.target.value }))}
            />
          </div>
        </form>
      </Modal>

      {/* Hand the operator the inbound endpoint immediately after provisioning. */}
      <Modal
        open={Boolean(created)}
        onClose={() => setCreated(null)}
        title={t('superAdmin.webhookReady')}
        description={t('integrations.secretOnce')}
        footer={<Button onClick={() => setCreated(null)}>{t('common.close')}</Button>}
      >
        <div className="space-y-3">
          <CopyField label={t('integrations.webhookUrl')} value={created?.url ?? ''} />
          <CopyField label={t('integrations.webhookSecret')} value={created?.secret ?? ''} />
        </div>
      </Modal>
    </>
  )
}

/** Colour chip, trade name, subdomain — enough to recognise an account. */
function TenantIdentity({ tenant }: { tenant: TenantSummary }) {
  return (
    <div className="flex items-center gap-2.5">
      <span
        className="grid size-8 shrink-0 place-items-center rounded-[var(--radius-control)] text-[0.6875rem] font-semibold text-white"
        style={{ background: tenant.primary_color ?? 'var(--brand)' }}
        aria-hidden
      >
        {initials(tenant.system_name || tenant.name)}
      </span>
      <div className="min-w-0">
        <p className="truncate font-medium">{tenant.name}</p>
        <p className="t-caption truncate">
          <bdi dir="ltr">{tenant.slug}</bdi>
        </p>
      </div>
    </div>
  )
}

/**
 * Thirty days of inbound, as a length rather than a number alone.
 *
 * The operator's real question scanning this column is "which of these accounts
 * has gone quiet?", and a column of bare integers makes that a reading task
 * instead of a glance.
 */
function UsageBar({ value, peak }: { value: number; peak: number }) {
  const { i18n } = useTranslation()

  /*
   * Square-rooted, not linear. One enterprise account with a hundred times the
   * volume of the rest would flatten every other bar to nothing on a linear
   * scale, and a column of empty tracks answers no question at all. The exact
   * figure is printed beside it, so the bar only has to carry the ordering.
   */
  const width = value === 0 ? 0 : Math.max(4, Math.round(Math.sqrt(value / peak) * 100))

  return (
    <span className="flex items-center gap-2">
      <span className="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
        <span className="block h-full rounded-full bg-[var(--brand)]" style={{ width: `${width}%` }} />
      </span>
      <span className="tabular text-[0.8125rem] font-medium">{formatNumber(value, i18n.language)}</span>
    </span>
  )
}

/**
 * One account, opened.
 *
 * The list answers "how is the platform doing"; this answers "what is going on
 * with this customer, and what can I do about it" — the subscription fields the
 * API will accept, and the people who can actually log in.
 */
function TenantPanel({ tenant, onClose }: { tenant: TenantSummary | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data, isLoading } = useQuery({
    queryKey: ['platform-tenant', tenant?.id],
    queryFn: () =>
      apiGet<{ tenant: TenantSummary; users: TenantUser[] }>(`/super-admin/tenants/${tenant?.id}`),
    enabled: Boolean(tenant),
  })

  const save = useMutation({
    mutationFn: (payload: Record<string, unknown>) => apiPatch(`/super-admin/tenants/${tenant?.id}`, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-stats'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-tenant', tenant?.id] })
      toast(t('settings.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const current = data?.tenant ?? tenant

  return (
    <Modal
      open={Boolean(tenant)}
      onClose={onClose}
      title={current?.name ?? ''}
      description={current ? t('superAdmin.createdOn', { date: formatDate(current.created_at, i18n.language) }) : undefined}
      footer={<Button variant="ghost" onClick={onClose}>{t('common.close')}</Button>}
    >
      {current && (
        <div className="space-y-5">
          <SubscriptionForm
            tenant={current}
            pending={save.isPending}
            onSave={(payload) => save.mutate(payload)}
          />

          {/*
           * Suspension is the one destructive control here, so it is separated
           * from the form it would otherwise sit inside and says what it does.
           */}
          <div
            className={cn(
              'flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] p-3',
              current.is_active
                ? 'bg-[var(--color-surface-sunken)]'
                : 'bg-[color-mix(in_srgb,var(--color-danger)_10%,transparent)]',
            )}
          >
            <div className="min-w-0">
              <p className="text-[0.8125rem] font-medium">
                {current.is_active ? t('superAdmin.suspendTitle') : t('superAdmin.activateTitle')}
              </p>
              <p className="t-caption mt-0.5">
                {current.is_active ? t('superAdmin.suspendHint') : t('superAdmin.activateHint')}
              </p>
            </div>
            <Button
              variant={current.is_active ? 'danger' : 'secondary'}
              size="sm"
              disabled={save.isPending}
              onClick={() => save.mutate({ is_active: !current.is_active })}
            >
              {current.is_active ? t('superAdmin.suspend') : t('superAdmin.activate')}
            </Button>
          </div>

          <div>
            <h3 className="label-caps mb-2 flex items-center gap-1.5">
              <ShieldCheck className="size-3.5" aria-hidden />
              {t('superAdmin.tenantUsers')}
            </h3>

            {isLoading ? (
              <SkeletonTable rows={3} cols={2} />
            ) : (
              <ul className="divide-y divide-[var(--color-line)] rounded-[var(--radius-card)] border border-[var(--color-line)]">
                {(data?.users ?? []).map((user) => (
                  <li key={user.id} className="flex items-center gap-3 px-3 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[0.8125rem] font-medium">{user.name}</p>
                      <p className="t-caption truncate"><bdi dir="ltr">{user.email}</bdi></p>
                    </div>
                    <span className="t-caption shrink-0">{user.role ? t(`roles.${user.role}`) : '—'}</span>
                    <span className="t-caption w-20 shrink-0 text-end">
                      {user.last_login_at ? formatRelative(user.last_login_at, i18n.language) : t('superAdmin.neverLoggedIn')}
                    </span>
                    {!user.is_active && <Badge tone="neutral">{t('common.inactive')}</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}

/** Plan, status and the two dates billing actually turns on. */
function SubscriptionForm({
  tenant,
  pending,
  onSave,
}: {
  tenant: TenantSummary
  pending: boolean
  onSave: (payload: Record<string, unknown>) => void
}) {
  const { t } = useTranslation()
  const [plan, setPlan] = useState(tenant.plan)
  const [status, setStatus] = useState(tenant.subscription_status)
  const [trialEnds, setTrialEnds] = useState(tenant.trial_ends_at?.slice(0, 10) ?? '')

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault()
        onSave({
          plan,
          subscription_status: status,
          trial_ends_at: trialEnds || null,
        })
      }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <Input label={t('superAdmin.plan')} value={plan} onChange={(e) => setPlan(e.target.value)} />
        <Select
          label={t('superAdmin.subscription')}
          value={status}
          onChange={(e) => setStatus(e.target.value)}
        >
          <option value="trialing">{t('superAdmin.status.trialing')}</option>
          <option value="active">{t('superAdmin.status.active')}</option>
          <option value="past_due">{t('superAdmin.status.past_due')}</option>
          <option value="cancelled">{t('superAdmin.status.cancelled')}</option>
        </Select>
        <Input
          type="date"
          dir="ltr"
          label={t('superAdmin.trialEndsLabel')}
          value={trialEnds}
          onChange={(e) => setTrialEnds(e.target.value)}
        />
      </div>

      <Button type="submit" size="sm" loading={pending}>{t('common.save')}</Button>
    </form>
  )
}

function CopyField({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  return (
    <div>
      <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
      <div className="flex items-center gap-2">
        <code
          dir="ltr"
          className="min-w-0 flex-1 truncate rounded-lg bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.75rem]"
        >
          {value}
        </code>
        <button
          type="button"
          aria-label={copied ? t('common.copied') : t('common.copy')}
          onClick={() => {
            void navigator.clipboard.writeText(value)
            setCopied(true)
            setTimeout(() => setCopied(false), 1800)
          }}
          className="grid size-8 shrink-0 place-items-center rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]"
        >
          {copied ? <Check className="size-4 text-[var(--color-status-won)]" aria-hidden /> : <Copy className="size-4" aria-hidden />}
        </button>
      </div>
    </div>
  )
}
