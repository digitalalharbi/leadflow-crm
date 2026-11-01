import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FileArchive } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, CardHeader } from '@/components/ui'
import { ExportsList } from '@/components/exports/ExportsList'
import { startExport } from '@/features/exports'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

/** Everything the company holds, in one ZIP it can take away — never behind a plan. */
export function CompanyExportCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const start = useMutation({
    mutationFn: () => startExport({ kind: 'company', format: 'zip' }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['exports'] }),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Card data-testid="company-export">
      <CardHeader
        title={t('exports.companyTitle')}
        description={t('exports.companyHint')}
        action={
          <Button icon={<FileArchive className="size-4" />} loading={start.isPending} onClick={() => start.mutate()} data-testid="company-export-start">
            {t('exports.companyStart')}
          </Button>
        }
      />
      <CardBody>
        <ExportsList kind="company" />
      </CardBody>
    </Card>
  )
}
