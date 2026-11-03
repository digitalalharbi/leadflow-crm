import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, CardHeader, ErrorState, Input, Pagination, SkeletonTable } from '@/components/ui'
import { apiGet, apiGetPaginated, apiPost } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import type { PaginationMeta } from '@/types/api'

interface SupportSession {
  id: string
  tenant: { id: string; name: string } | null
  as: { name: string; email: string } | null
  by: { name: string } | null
  reason: string
  started_at: string
  expires_at: string
  ended_at: string | null
  ended_by: string | null
  live: boolean
}

interface AuditRow {
  id: number
  action: string
  actor: { name: string; email: string } | null
  tenant: { id: string; name: string } | null
  changes: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

/** Every support session: who entered which company, as whom, why, and whether it is still open. */
export function SupportSessions() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['platform-impersonations'],
    queryFn: () => apiGet<{ sessions: SupportSession[] }>('/super-admin/impersonations'),
  })
  const revoke = useMutation({
    mutationFn: (id: string) => apiPost(`/super-admin/impersonations/${id}/revoke`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['platform-impersonations'] }),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <SkeletonTable rows={4} cols={4} />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  return (
    <Card data-testid="support-sessions">
      <CardHeader title={t('platformAdmin.supportSessions')} description={t('platformAdmin.supportHint')} />
      <CardBody>
        {data.sessions.length === 0 ? <p className="t-caption">{t('platformAdmin.noSessions')}</p> : (
          <ul className="divide-y divide-[var(--color-line)]">
            {data.sessions.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-2 py-2 text-[0.9375rem]">
                <span className="font-medium">{s.tenant?.name}</span>
                <span className="t-caption"><bdi dir="ltr">{s.as?.email}</bdi></span>
                <span className="t-caption">· {s.reason}</span>
                <span className="t-caption">· {formatDateTime(s.started_at, i18n.language)}</span>
                {s.live ? <Badge tone="warning">{t('platformAdmin.live')}</Badge> : <Badge tone="neutral">{t('platformAdmin.ended')}</Badge>}
                {s.live && <Button size="sm" variant="ghost" className="ms-auto" onClick={() => revoke.mutate(s.id)}>{t('platformAdmin.revoke')}</Button>}
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}

/** The platform's own trail — separate from every company's. */
export function PlatformAuditList() {
  const { t, i18n } = useTranslation()
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['platform-audit', page, action],
    queryFn: () => apiGetPaginated<AuditRow[]>('/super-admin/audit', { page, action: action || undefined }) as Promise<{ data: AuditRow[]; meta: PaginationMeta | undefined }>,
    placeholderData: (previous) => previous,
  })

  return (
    <Card data-testid="platform-audit">
      <CardHeader title={t('platformAdmin.audit')} description={t('platformAdmin.auditHint')} action={<Input dir="ltr" placeholder="subscription." value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }} className="w-48" aria-label={t('platformAdmin.filterAction')} />} />
      <CardBody>
        {isLoading ? <SkeletonTable rows={6} cols={3} /> : isError || !data ? <ErrorState onRetry={() => void refetch()} /> : (
          <>
            <ul className="divide-y divide-[var(--color-line)]">
              {data.data.map((row) => (
                <li key={row.id} className="flex flex-wrap items-baseline gap-2 py-2 text-[0.9375rem]" data-testid="platform-audit-row">
                  <code dir="ltr" className="text-[0.8125rem]">{row.action}</code>
                  {row.changes && <details className="mt-1 text-[0.8125rem]"><summary className="cursor-pointer text-[var(--color-ink-muted)]">{t('platformAdmin.auditDetail')}</summary><pre dir="ltr" className="mt-1 overflow-x-auto rounded bg-[var(--color-surface-muted)] p-2 text-start" data-testid="audit-diff">{JSON.stringify(row.changes, null, 2)}</pre></details>}
                  {row.tenant && <span className="font-medium">{row.tenant.name}</span>}
                  <span className="t-caption">{row.actor?.name ?? t('platformAdmin.system')}</span>
                  <span className="t-caption ms-auto">{formatDateTime(row.created_at, i18n.language)}</span>
                </li>
              ))}
            </ul>
            {data.meta && data.meta.last_page > 1 && <Pagination meta={data.meta} onPageChange={setPage} />}
          </>
        )}
      </CardBody>
    </Card>
  )
}
