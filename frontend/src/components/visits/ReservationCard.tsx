import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Check, Handshake, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button } from '@/components/ui'
import { actOnReservation, type Reservation, type ReservationStatus } from '@/features/visits'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatCurrency, formatLastSeen } from '@/lib/format'

const TONE: Record<ReservationStatus, 'info' | 'success' | 'warning' | 'danger' | 'neutral'> = {
  pending: 'warning', confirmed: 'info', converted: 'success', cancelled: 'neutral', expired: 'danger',
}

/** A unit held for a lead, with the three things that can happen to it. */
export function ReservationCard({ reservation }: { reservation: Reservation }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const act = useMutation({
    mutationFn: (action: 'confirm' | 'cancel' | 'convert') => actOnReservation(reservation.id, action),
    onSuccess: (_, action) => {
      toast(t(`visits.reservationDone.${action}`), 'success')
      void queryClient.invalidateQueries({ queryKey: ['reservations'] })
      void queryClient.invalidateQueries({ queryKey: ['work-next'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const has = (move: 'confirm' | 'cancel' | 'convert') => reservation.moves.includes(move)

  return (
    <li className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`reservation-${reservation.id}`} data-status={reservation.status}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 space-y-0.5">
          <Link to={`/leads/${reservation.lead?.id}`} className="t-section-title hover:underline">{reservation.lead?.full_name}</Link>
          <p className="t-caption">
            {[reservation.project?.name, reservation.property?.title].filter(Boolean).join(' · ') || t('visits.noUnit')}
            {reservation.property?.price != null && <span className="ms-2 font-semibold tabular-nums">{formatCurrency(reservation.property.price, 'SAR', i18n.language)}</span>}
          </p>
        </div>
        <Badge tone={TONE[reservation.status]} testId="reservation-status">{t(`visits.reservationStatus.${reservation.status}`)}</Badge>
      </div>

      {reservation.status === 'pending' && reservation.expires_at && (
        <p className="t-caption mt-1" data-testid="reservation-expiry">{t('visits.holdUntil', { when: formatLastSeen(reservation.expires_at, i18n.language) })}</p>
      )}

      <div className="mt-2 flex flex-wrap gap-1.5">
        {has('confirm') && <Button size="sm" icon={<BadgeCheck className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate('confirm')} data-testid="reservation-confirm">{t('visits.reservationConfirm')}</Button>}
        {has('convert') && <Button size="sm" variant="secondary" icon={<Handshake className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate('convert')} data-testid="reservation-convert">{t('visits.reservationConvert')}</Button>}
        {has('cancel') && <Button size="sm" variant="ghost" icon={<X className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate('cancel')} data-testid="reservation-cancel">{t('visits.cancel')}</Button>}
        {reservation.status === 'converted' && <span className="t-caption inline-flex items-center gap-1"><Check className="size-3.5" aria-hidden />{t('visits.reservationStatus.converted')}</span>}
      </div>
    </li>
  )
}
