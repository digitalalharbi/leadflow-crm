import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ReportsTabs } from '@/components/reports/library/ReportsTabs'
import { PageHeader } from '@/components/ui'
import { ShareTab } from '@/components/reports/ShareTab'

/**
 * التقارير — the reports library: every report a workspace has made, its state (draft, live, archived), who owns it,
 * which projects and period it covers, how it is shared and how often it is opened. The same screen the builder opens
 * from, so a report is made, shared, noted, exported and archived from one place.
 */
export function ReportLibraryPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()

  return (
    <>
      <PageHeader title={t('library.title')} subtitle={t('library.subtitle')} />
      <ReportsTabs />
      <ShareTab startOpen={params.get('new') === '1'} />
    </>
  )
}
