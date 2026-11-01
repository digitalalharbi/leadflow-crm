import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Download, FileSpreadsheet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { ExportsList } from '@/components/exports/ExportsList'
import { startExport } from '@/features/exports'
import { useApiError } from '@/hooks/useApiError'
import { apiDownload } from '@/lib/api'
import { useUi } from '@/stores/ui'

/**
 * Exporting the list the person is looking at: a CSV straight away, or an Excel file made in the background for a
 * long list — the modal shows it being made and opens it when it is ready.
 */
export function ExportsModal({ open, onClose, filters }: { open: boolean; onClose: () => void; filters: Record<string, unknown> }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const queue = useMutation({
    mutationFn: () => startExport({ kind: 'leads', format: 'xlsx', filters }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['exports'] }),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal open={open} onClose={onClose} title={t('exports.title')} description={t('exports.hint')} size="lg">
      <div className="space-y-4">
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            icon={<Download className="size-4" />}
            onClick={() => void apiDownload('/leads/export', filters, 'leads.csv')}
            data-testid="export-csv-now"
          >
            {t('exports.csvNow')}
          </Button>
          <Button icon={<FileSpreadsheet className="size-4" />} loading={queue.isPending} onClick={() => queue.mutate()} data-testid="export-xlsx">
            {t('exports.xlsxBackground')}
          </Button>
        </div>
        {open && <ExportsList kind="leads" />}
      </div>
    </Modal>
  )
}
