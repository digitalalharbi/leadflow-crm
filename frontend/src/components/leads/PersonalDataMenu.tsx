import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Download, ShieldCheck, UserX } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button, Dropdown, DropdownItem, Input, Modal } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import { apiDownload, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'

/**
 * A person's two rights over what the company holds about them: a copy of it, or its erasure. Erasure asks for the
 * name typed back — the server checks it too — and offers to keep them on the do-not-contact list.
 */
export function PersonalDataMenu({ leadId, name }: { leadId: string; name: string }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [eraseOpen, setEraseOpen] = useState(false)
  const [confirm, setConfirm] = useState('')
  const [suppress, setSuppress] = useState(true)

  const erase = useMutation({
    mutationFn: () => apiPost(`/leads/${leadId}/erase`, { confirm, suppress }),
    onSuccess: () => {
      toast(t('personalData.erased'), 'success')
      navigate('/leads')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <>
      <Dropdown
        trigger={({ toggle }) => (
          <Button variant="ghost" size="icon" aria-label={t('personalData.title')} title={t('personalData.title')} onClick={toggle} data-testid="action-personal-data" icon={<ShieldCheck className="size-4" />} />
        )}
      >
        {({ close }) => (
          <>
            <DropdownItem
              icon={<Download className="size-4" />}
              onClick={() => {
                close()
                apiDownload(`/leads/${leadId}/personal-data`, {}, `personal-data-${leadId}.json`).catch((caught) => toast(translateError(caught), 'error'))
              }}
            >
              {t('personalData.export')}
            </DropdownItem>
            <DropdownItem
              icon={<UserX className="size-4" />}
              onClick={() => {
                close()
                setConfirm('')
                setEraseOpen(true)
              }}
            >
              {t('personalData.erase')}
            </DropdownItem>
          </>
        )}
      </Dropdown>

      <Modal
        open={eraseOpen}
        onClose={() => setEraseOpen(false)}
        title={t('personalData.eraseTitle', { name })}
        description={t('personalData.eraseBody')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEraseOpen(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" loading={erase.isPending} disabled={confirm.trim() !== name.trim()} onClick={() => erase.mutate()} data-testid="erase-confirm">
              {t('personalData.erase')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <label className="block space-y-1">
            <span className="text-[0.875rem] font-medium">{t('personalData.confirmLabel')}</span>
            <Input value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder={name} data-testid="erase-name" />
          </label>
          <label className="flex items-center gap-2 text-[0.875rem]">
            <input type="checkbox" checked={suppress} onChange={(e) => setSuppress(e.target.checked)} />
            {t('personalData.suppress')}
          </label>
        </div>
      </Modal>
    </>
  )
}
