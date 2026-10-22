import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarCheck, CalendarClock, Check, MapPin, Phone, UserX, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Input, Modal } from '@/components/ui'
import { moveVisit, recordVisitOutcome, VISIT_OUTCOMES, type Visit, type VisitOutcome, type VisitStatus } from '@/features/visits'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatDateTime, formatLastSeen, telLink } from '@/lib/format'

const TONE: Record<VisitStatus, 'info' | 'success' | 'warning' | 'danger' | 'neutral'> = {
  requested: 'warning', confirmed: 'info', rescheduled: 'info', completed: 'success', no_show: 'danger', cancelled: 'neutral',
}

/**
 * One visit and every move open to it. The buttons come from the server (`moves`), so a screen
 * never offers what the workflow would refuse.
 */
export function VisitCard({ visit }: { visit: Visit }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [timing, setTiming] = useState<'confirmed' | 'rescheduled' | null>(null)
  const [at, setAt] = useState('')
  const [resulting, setResulting] = useState(false)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['visits'] })
    void queryClient.invalidateQueries({ queryKey: ['reservations'] })
    void queryClient.invalidateQueries({ queryKey: ['work-next'] })
  }

  const move = useMutation({
    mutationFn: (payload: { to: VisitStatus; scheduled_at?: string }) => moveVisit(visit.id, payload),
    onSuccess: () => { toast(t('visits.moved'), 'success'); setTiming(null); setAt(''); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const result = useMutation({
    mutationFn: (outcome: VisitOutcome) => recordVisitOutcome(visit.id, { outcome }),
    onSuccess: () => { toast(t('visits.resultSaved'), 'success'); setResulting(false); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const can = (to: VisitStatus) => visit.moves.includes(to)
  const needsResult = visit.status === 'completed' && !visit.outcome
  const when = visit.scheduled_at ? formatDateTime(visit.scheduled_at, i18n.language) : null

  return (
    <li className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`visit-${visit.id}`} data-status={visit.status}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 space-y-0.5">
          <Link to={`/leads/${visit.lead?.id}`} className="t-section-title hover:underline" data-testid="visit-lead">{visit.lead?.full_name}</Link>
          <p className="t-caption flex flex-wrap items-center gap-x-2">
            {[visit.project?.name, visit.property?.title].filter(Boolean).join(' · ') || t('visits.noUnit')}
            {visit.location && <span className="inline-flex items-center gap-0.5"><MapPin className="size-3" aria-hidden />{visit.location}</span>}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge tone={TONE[visit.status]} testId="visit-status">{t(`visits.status.${visit.status}`)}</Badge>
          {visit.rescheduled_count > 0 && <Badge tone="neutral">{t('visits.rescheduled', { count: visit.rescheduled_count })}</Badge>}
          {visit.outcome && <Badge tone="success" testId="visit-outcome">{t(`visits.outcome.${visit.outcome}`)}</Badge>}
        </div>
      </div>

      <p className="t-small mt-1.5 flex items-center gap-1.5" data-testid="visit-when">
        <CalendarClock className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
        {when ?? t('visits.noTime', { when: formatLastSeen(visit.requested_at, i18n.language) })}
      </p>

      <div className="mt-2 flex flex-wrap gap-1.5">
        {can('confirmed') && (
          <Button size="sm" icon={<CalendarCheck className="size-3.5" aria-hidden />} onClick={() => setTiming('confirmed')} data-testid="visit-confirm">{t('visits.confirm')}</Button>
        )}
        {can('rescheduled') && (
          <Button size="sm" variant="secondary" icon={<CalendarClock className="size-3.5" aria-hidden />} onClick={() => setTiming('rescheduled')} data-testid="visit-reschedule">{t('visits.reschedule')}</Button>
        )}
        {(can('completed') || needsResult) && (
          <Button size="sm" icon={<Check className="size-3.5" aria-hidden />} onClick={() => setResulting(true)} data-testid="visit-result">{needsResult ? t('visits.recordResult') : t('visits.done')}</Button>
        )}
        {can('no_show') && (
          <Button size="sm" variant="outline" icon={<UserX className="size-3.5" aria-hidden />} loading={move.isPending} onClick={() => move.mutate({ to: 'no_show' })} data-testid="visit-no-show">{t('visits.noShow')}</Button>
        )}
        {can('cancelled') && (
          <Button size="sm" variant="ghost" icon={<X className="size-3.5" aria-hidden />} loading={move.isPending} onClick={() => move.mutate({ to: 'cancelled' })} data-testid="visit-cancel">{t('visits.cancel')}</Button>
        )}
        {visit.lead?.phone && (
          <a href={telLink(visit.lead.phone) ?? '#'} className="t-small inline-flex items-center gap-1 px-2 py-1 hover:underline" dir="ltr"><Phone className="size-3.5" aria-hidden />{visit.lead.phone}</a>
        )}
      </div>

      <Modal
        open={timing !== null}
        onClose={() => setTiming(null)}
        title={timing === 'rescheduled' ? t('visits.reschedule') : t('visits.confirm')}
        size="sm"
        footer={<Button disabled={!at} loading={move.isPending} data-testid="visit-time-save" onClick={() => timing && move.mutate({ to: timing, scheduled_at: new Date(at).toISOString() })}>{t('common.save')}</Button>}
      >
        <Input type="datetime-local" label={t('leadView.when')} value={at} data-testid="visit-time" onChange={(e) => setAt(e.target.value)} />
      </Modal>

      {/* What came of it: four big presses, never a form. */}
      <Modal open={resulting} onClose={() => setResulting(false)} title={t('visits.resultTitle')} size="sm">
        <div className="grid grid-cols-2 gap-2" data-testid="visit-outcomes">
          {VISIT_OUTCOMES.map((outcome) => (
            <Button key={outcome} variant={outcome === 'reservation' ? 'primary' : outcome === 'lost' ? 'danger' : 'outline'} loading={result.isPending} data-testid={`visit-outcome-${outcome}`} onClick={() => result.mutate(outcome)}>
              {t(`visits.outcome.${outcome}`)}
            </Button>
          ))}
        </div>
      </Modal>
    </li>
  )
}
