import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Download, ExternalLink, Files, KeyRound, Pencil, ShieldOff } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, EmptyState, ErrorState, PageHeader, Skeleton } from '@/components/ui'
import { ReportsTabs } from '@/components/reports/library/ReportsTabs'
import { protectionOf } from '@/components/reports/library/LinksPanel'
import { duplicateReport, exportReport, fetchClientLinks, markShared, revokeLink, type ClientLink } from '@/features/reportLibrary'
import type { ShareStatus } from '@/features/reportShares'
import { useApiError } from '@/hooks/useApiError'
import { formatDate, formatLastSeen } from '@/lib/format'
import { useEntitled } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const TONE: Record<ShareStatus, 'success' | 'warning' | 'danger' | 'neutral'> = { active: 'success', expired: 'warning', revoked: 'danger', inactive: 'neutral' }

/**
 * تقارير العملاء — the one place that lists every external link the workspace has handed out: which client, which project and
 * report, the short address, when it was last opened and how often, when it ends, how it is protected, and whether it is
 * still open. Revoking a link here closes only that client’s door.
 */
export function ClientAccessPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const exportsOn = useEntitled('exports')
  const [search, setSearch] = useState('')
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['client-links'], queryFn: fetchClientLinks })

  const copy = async (url: string, link?: ClientLink) => {
    if (link) void markShared(link.report.id, link.id)

    try { await navigator.clipboard.writeText(url); toast(t('links.copied'), 'success') } catch { toast(url, 'info') }
  }
  const revoke = useMutation({
    mutationFn: (link: ClientLink) => revokeLink(link.report.id, link.id),
    onSuccess: () => { toast(t('links.done.revoke'), 'success'); void queryClient.invalidateQueries({ queryKey: ['client-links'] }) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const duplicate = useMutation({
    mutationFn: (link: ClientLink) => duplicateReport(link.report.id),
    onSuccess: () => { toast(t('library.done.duplicate'), 'success'); void queryClient.invalidateQueries({ queryKey: ['report-shares'] }) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const download = useMutation({
    mutationFn: (link: ClientLink) => exportReport(link.report.id, 'csv', link.report.name),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const links = (data?.links ?? []).filter((l) => `${l.client_name ?? ''} ${l.report.name}`.toLowerCase().includes(search.trim().toLowerCase()))

  return (
    <>
      <PageHeader title={t('clientAccess.title')} subtitle={t('clientAccess.subtitle')} />
      <ReportsTabs />

      {isError ? <Card><ErrorState onRetry={() => void refetch()} /></Card> : isLoading || !data ? <Skeleton className="h-48" /> : data.links.length === 0 ? (
        <Card><CardBody><EmptyState title={t('clientAccess.empty.title')} body={t('clientAccess.empty.body')} /></CardBody></Card>
      ) : (
        <div className="space-y-3" data-testid="client-access">
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('clientAccess.search')} aria-label={t('clientAccess.search')} data-testid="client-search"
            className="w-full max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-[0.9375rem]" />

          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="client-list">
            {links.map((link) => (
              <li key={link.id} className="space-y-2 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`client-link-${link.id}`} data-status={link.status}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="t-small font-semibold" data-testid="client-name">{link.client_name || t('clientAccess.noClient')}</p>
                    <p className="t-caption truncate">{link.report.name}</p>
                    <p className="t-caption truncate">{link.projects.length > 0 ? link.projects.map((p) => p.name).join('، ') : t('library.allProjects')}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Badge tone={TONE[link.status]} testId="client-status">{t(`links.status.${link.status}`)}</Badge>
                    <Badge tone="neutral"><KeyRound className="size-3" aria-hidden />{t(`links.protection.${protectionOf(link)}`)}</Badge>
                  </div>
                </div>
                <p className="t-caption break-all" dir="ltr" data-testid="client-url">{link.url}</p>
                <p className="t-caption">
                  {t('links.views', { n: link.view_count })} · {link.last_viewed_at ? t('links.lastViewed', { when: formatLastSeen(link.last_viewed_at, i18n.language) }) : t('links.neverViewed')}
                  {' · '}{link.expires_at ? `${t('links.expires')} ${formatDate(link.expires_at, i18n.language)}` : t('clientAccess.noExpiry')}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  <a href={link.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[0.8125rem]" data-testid="client-open"><ExternalLink className="size-3.5" aria-hidden />{t('clientAccess.action.open')}</a>
                  <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} disabled={link.status === 'revoked'} onClick={() => void copy(link.url, link)} data-testid="client-copy">{t('clientAccess.action.copy')}</Button>
                  <Button size="sm" variant="secondary" icon={<Pencil className="size-3.5" aria-hidden />} onClick={() => navigate('/report-library')} data-testid="client-edit">{t('clientAccess.action.edit')}</Button>
                  <Button size="sm" variant="outline" icon={<Files className="size-3.5" aria-hidden />} loading={duplicate.isPending} onClick={() => duplicate.mutate(link)} data-testid="client-duplicate">{t('clientAccess.action.duplicate')}</Button>
                  {exportsOn && <Button size="sm" variant="outline" icon={<Download className="size-3.5" aria-hidden />} loading={download.isPending} onClick={() => download.mutate(link)} data-testid="client-export">{t('clientAccess.action.export')}</Button>}
                  {link.status !== 'revoked' && <Button size="sm" variant="outline" icon={<ShieldOff className="size-3.5" aria-hidden />} loading={revoke.isPending} onClick={() => revoke.mutate(link)} data-testid="client-revoke">{t('clientAccess.action.revoke')}</Button>}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  )
}
