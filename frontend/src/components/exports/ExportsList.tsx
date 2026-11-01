import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, FileArchive, FileSpreadsheet, Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, EmptyState, Skeleton } from '@/components/ui'
import { fetchExports, isPending, openExport, type DataExport } from '@/features/exports'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import { useUi } from '@/stores/ui'

const TONE: Record<DataExport['status'], 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  queued: 'info', running: 'info', ready: 'success', failed: 'danger', expired: 'neutral',
}

function size(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** The person's own exports, newest first; polls while one is still being made. */
export function ExportsList({ kind }: { kind?: DataExport['kind'] }) {
  const { t, i18n } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const list = useQuery({
    queryKey: ['exports'],
    queryFn: fetchExports,
    refetchInterval: (query) => ((query.state.data?.exports ?? []).some(isPending) ? 2000 : false),
  })

  const open = useMutation({ mutationFn: openExport, onError: (caught) => toast(translateError(caught), 'error') })

  if (list.isLoading) return <Skeleton className="h-24" />

  const rows = (list.data?.exports ?? []).filter((e) => !kind || e.kind === kind)

  if (rows.length === 0) {
    return <EmptyState icon={<FileSpreadsheet className="size-6" />} title={t('exports.emptyTitle')} body={t('exports.emptyBody')} />
  }

  return (
    <ul className="divide-y divide-[var(--color-line)] rounded-xl border border-[var(--color-line)]" data-testid="exports-list">
      {rows.map((e) => (
        <li key={e.id} className="flex flex-wrap items-center gap-2 px-3 py-2" data-testid="export-row" data-status={e.status}>
          {e.format === 'zip'
            ? <FileArchive className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
            : <FileSpreadsheet className="size-4 text-[var(--color-ink-muted)]" aria-hidden />}
          <span className="min-w-0 flex-1 truncate font-medium">
            {t(`exports.kinds.${e.kind}`)} · {e.format.toUpperCase()}
          </span>
          <Badge tone={TONE[e.status]}>
            {isPending(e) && <Loader2 className="me-1 inline size-3 animate-spin" aria-hidden />}
            {t(`exports.status.${e.status}`)}
          </Badge>
          {e.status === 'ready' && <span className="t-caption">{t('exports.rows', { count: e.rows })} · {size(e.bytes)}</span>}
          {e.status === 'failed' && e.error && <span className="t-caption text-[var(--color-danger)]">{e.error}</span>}
          {e.created_at && <span className="t-caption">{formatLastSeen(e.created_at, i18n.language)}</span>}
          {e.status === 'ready' && (
            <Button
              size="sm"
              variant="outline"
              icon={<Download className="size-3.5" />}
              loading={open.isPending && open.variables === e.id}
              onClick={() => open.mutate(e.id)}
              data-testid="export-download"
            >
              {t('exports.download')}
            </Button>
          )}
        </li>
      ))}
    </ul>
  )
}
