import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { apiPost } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { Button, Input, Modal, Select } from '@/components/ui'

/**
 * Book a visit without leaving the lead.
 *
 * Four fields, one of them required. Saving it moves the lead to the
 * appointment stage on the server — the rep is not asked to record the meeting
 * and then separately drag a card — and the dialog says so by closing on a
 * toast rather than by explaining.
 */
export function AppointmentDialog({
  leadId,
  open,
  onClose,
  onBooked,
}: {
  leadId: string
  open: boolean
  onClose: () => void
  onBooked: () => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [at, setAt] = useState('')
  const [minutes, setMinutes] = useState('60')
  const [location, setLocation] = useState('')

  const book = useMutation({
    mutationFn: () =>
      apiPost('/appointments', {
        lead_id: leadId,
        scheduled_at: new Date(at).toISOString(),
        duration_minutes: Number(minutes),
        location: location || undefined,
      }),
    onSuccess: () => {
      toast(t('leadView.booked'), 'success')
      setAt('')
      setLocation('')
      onBooked()
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('leadView.bookTitle')}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button disabled={!at} loading={book.isPending} onClick={() => book.mutate()} data-testid="confirm-appointment">
            {t('leadView.book')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Input
          label={t('leadView.when')}
          type="datetime-local"
          dir="ltr"
          value={at}
          onChange={(e) => setAt(e.target.value)}
          data-testid="appointment-at"
        />
        <Select label={t('leadView.duration')} value={minutes} onChange={(e) => setMinutes(e.target.value)}>
          {[30, 45, 60, 90, 120].map((m) => (
            <option key={m} value={m}>{t('leadView.minutes', { count: m })}</option>
          ))}
        </Select>
        <Input label={t('leadView.location')} hint={t('common.optional')} value={location} onChange={(e) => setLocation(e.target.value)} />
      </div>
    </Modal>
  )
}
