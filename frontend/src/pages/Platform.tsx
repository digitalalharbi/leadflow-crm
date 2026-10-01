import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Check, Copy, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ApiError, apiGet, apiGetPaginated, apiPatch, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDate, formatNumber } from '@/lib/format'
import {
  Badge, Button, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Pagination, SkeletonCards, SkeletonTable, StatCard, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { PaginationMeta, TenantSummary } from '@/types/api'

interface PlatformStats {
  tenants: number
  active_tenants: number
  trialing: number
  users: number
  leads: number
  leads_last_30_days: number
}

const EMPTY_FORM = {
  company_name: '',
  system_name: '',
  subdomain: '',
  primary_color: '#2563eb',
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
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [created, setCreated] = useState<{ url: string; secret: string } | null>(null)

  const { data: stats } = useQuery({
    queryKey: ['platform-stats'],
    queryFn: () => apiGet<PlatformStats>('/super-admin/stats'),
  })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['platform-tenants', page],
    queryFn: () =>
      apiGetPaginated<TenantSummary[]>('/super-admin/tenants', { page, per_page: 25 }) as Promise<{
        data: TenantSummary[]
        meta: PaginationMeta | undefined
      }>,
    placeholderData: (previous) => previous,
  })

  const create = useMutation({
    mutationFn: () =>
      apiPost<{ webhook: { url: string; secret: string } }>('/super-admin/tenants', {
        ...form,
        system_name: form.system_name || undefined,
        subdomain: form.subdomain || undefined,
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

  const toggleActive = useMutation({
    mutationFn: (tenant: TenantSummary) =>
      apiPatch(`/super-admin/tenants/${tenant.id}`, { is_active: !tenant.is_active }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
      toast(t('settings.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    create.mutate()
  }

  const tenants = data?.data ?? []

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

      {stats ? (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-6">
          <StatCard label={t('superAdmin.totalTenants')} value={formatNumber(stats.tenants, i18n.language)} />
          <StatCard label={t('superAdmin.activeTenants')} value={formatNumber(stats.active_tenants, i18n.language)} tone="success" />
          <StatCard label={t('superAdmin.trialing')} value={formatNumber(stats.trialing, i18n.language)} tone="warning" />
          <StatCard label={t('superAdmin.totalUsers')} value={formatNumber(stats.users, i18n.language)} />
          <StatCard label={t('superAdmin.totalLeads')} value={formatNumber(stats.leads, i18n.language)} />
          <StatCard label={t('superAdmin.leadsLast30')} value={formatNumber(stats.leads_last_30_days, i18n.language)} tone="brand" />
        </div>
      ) : (
        <div className="mb-5"><SkeletonCards count={6} /></div>
      )}

      <Card className="overflow-hidden">
        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={6} cols={6} />
        ) : tenants.length === 0 ? (
          <EmptyState
            icon={<Building2 className="size-6" />}
            title={t('superAdmin.tenants')}
            actions={<Button onClick={() => setOpen(true)}>{t('superAdmin.createTenant')}</Button>}
          />
        ) : (
          <>
            <TableWrap>
              <Table>
                <thead>
                  <tr>
                    <Th>{t('superAdmin.companyName')}</Th>
                    <Th className="hidden sm:table-cell">{t('superAdmin.subdomain')}</Th>
                    <Th className="hidden md:table-cell">{t('superAdmin.plan')}</Th>
                    <Th>{t('superAdmin.users')}</Th>
                    <Th>{t('superAdmin.leads')}</Th>
                    <Th className="hidden lg:table-cell">{t('superAdmin.createdAt')}</Th>
                    <Th>{t('common.status')}</Th>
                    <Th className="text-end">{t('common.actions')}</Th>
                  </tr>
                </thead>
                <tbody>
                  {tenants.map((tenant) => (
                    <Tr key={tenant.id}>
                      <Td>
                        <div className="flex items-center gap-2">
                          <span
                            className="size-6 shrink-0 rounded"
                            style={{ background: tenant.primary_color ?? '#2563eb' }}
                            aria-hidden
                          />
                          <div className="min-w-0">
                            <p className="truncate font-medium">{tenant.name}</p>
                            {tenant.system_name && (
                              <p className="truncate text-[0.75rem] text-[var(--color-ink-subtle)]">
                                {tenant.system_name}
                              </p>
                            )}
                          </div>
                        </div>
                      </Td>
                      <Td className="hidden sm:table-cell" dir="ltr">{tenant.slug}</Td>
                      <Td className="hidden md:table-cell">{tenant.plan}</Td>
                      <Td className="tabular">{formatNumber(tenant.users_count ?? 0, i18n.language)}</Td>
                      <Td className="tabular">{formatNumber(tenant.leads_count ?? 0, i18n.language)}</Td>
                      <Td className="hidden whitespace-nowrap lg:table-cell">
                        {formatDate(tenant.created_at, i18n.language)}
                      </Td>
                      <Td>
                        <Badge tone={tenant.is_active ? 'success' : 'danger'}>
                          {tenant.is_active ? t('common.active') : t('common.inactive')}
                        </Badge>
                      </Td>
                      <Td className="text-end">
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={toggleActive.isPending}
                          onClick={() => toggleActive.mutate(tenant)}
                        >
                          {tenant.is_active ? t('superAdmin.suspend') : t('superAdmin.activate')}
                        </Button>
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
            onChange={(e) => setForm({ ...form, company_name: e.target.value })}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label={t('superAdmin.systemName')}
              hint={t('common.optional')}
              value={form.system_name}
              onChange={(e) => setForm({ ...form, system_name: e.target.value })}
            />
            <Input
              dir="ltr"
              label={t('superAdmin.subdomain')}
              hint={t('common.optional')}
              value={form.subdomain}
              error={fieldErrors.subdomain}
              onChange={(e) =>
                setForm({ ...form, subdomain: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })
              }
            />
          </div>
          <Input
            required
            label={t('superAdmin.adminName')}
            value={form.admin_name}
            error={fieldErrors.admin_name}
            onChange={(e) => setForm({ ...form, admin_name: e.target.value })}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              required
              dir="ltr"
              type="email"
              label={t('superAdmin.adminEmail')}
              value={form.admin_email}
              error={fieldErrors.admin_email}
              onChange={(e) => setForm({ ...form, admin_email: e.target.value })}
            />
            <Input
              required
              dir="ltr"
              type="password"
              autoComplete="new-password"
              label={t('superAdmin.adminPassword')}
              value={form.admin_password}
              error={fieldErrors.admin_password}
              onChange={(e) => setForm({ ...form, admin_password: e.target.value })}
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
          {copied ? <Check className="size-4 text-emerald-600" aria-hidden /> : <Copy className="size-4" aria-hidden />}
        </button>
      </div>
    </div>
  )
}
