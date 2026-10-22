import { useTranslation } from 'react-i18next'
import { Modal } from '@/components/ui'
import { LANDING } from '@/content/landing'
import { ProductPreview } from './ProductPreview'

/**
 * What a shared live report looks like — a picture, not a real report.
 *
 * There is no public demo report to open (a report link is a credential that belongs to a workspace), so this
 * shows the shape with sample numbers and says so, twice: in the note and on the preview itself.
 */
export function SampleReportDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { i18n } = useTranslation()
  const copy = LANDING[i18n.language.startsWith('ar') ? 'ar' : 'en'].reports

  return (
    <Modal open={open} onClose={onClose} title={copy.sampleTitle} description={copy.sampleNote} size="lg">
      <div data-testid="sample-report">
        <ProductPreview />
      </div>
    </Modal>
  )
}
