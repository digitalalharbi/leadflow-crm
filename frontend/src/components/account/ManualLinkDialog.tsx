import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'

/** No mail provider yet, so nobody received an email: the person who asked passes this link on themselves. */
export function ManualLinkDialog({ link, onClose }: { link: string | null; onClose: () => void }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  return (
    <Modal
      open={Boolean(link)}
      onClose={onClose}
      title={t('account.manualLinkTitle')}
      description={t('account.manualLinkNote')}
      footer={<Button onClick={onClose}>{t('common.close')}</Button>}
    >
      <div className="flex items-center gap-2">
        <code dir="ltr" data-testid="manual-link" className="min-w-0 flex-1 break-all rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.875rem]">{link}</code>
        <Button
          size="sm"
          variant="outline"
          onClick={() => {
            void navigator.clipboard.writeText(link ?? '')
            setCopied(true)
          }}
        >
          {copied ? t('common.copied') : t('common.copy')}
        </Button>
      </div>
    </Modal>
  )
}
