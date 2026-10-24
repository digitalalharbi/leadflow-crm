import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Input, Select } from '@/components/ui'
import { fetchLostReasons } from '@/features/leads'
import type { Outcome } from '@/features/work'

/** What an outcome needs beyond the tap, so the record says what actually happened. */
export interface OutcomeExtras {
  deal_value?: string
  lost_reason_id?: string
  appointment_at?: string
  follow_up_at?: string
}

/** The reminder each open-ended outcome books by default — the same hours the server uses. */
const FOLLOW_UP_HOURS: Partial<Record<Outcome, number>> = { interested: 48, reservation: 24, contacted: 24, call_later: 24, no_answer: 3 }

const LOST: Outcome[] = ['lost', 'not_interested']

/** A value for an `<input type="datetime-local">`, in the device's own time. */
function localInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export function defaultExtras(choice: Outcome | null): OutcomeExtras {
  const hours = choice ? FOLLOW_UP_HOURS[choice] : undefined

  return hours ? { follow_up_at: localInput(new Date(Date.now() + hours * 3_600_000)) } : {}
}

/** Whether the outcome can be saved: a sale has its value, a loss its reason, a booked visit its time. */
export function extrasComplete(choice: Outcome | null, extras: OutcomeExtras): boolean {
  if (choice === 'won') return Number(extras.deal_value) > 0
  if (choice && LOST.includes(choice)) return Boolean(extras.lost_reason_id)
  if (choice === 'meeting_booked') return Boolean(extras.appointment_at) && new Date(extras.appointment_at as string).getTime() > Date.now()

  return true
}

/** The payload the server takes: amounts as numbers, times as ISO instants. */
export function extrasPayload(choice: Outcome, extras: OutcomeExtras): Record<string, unknown> {
  const iso = (value?: string) => (value ? new Date(value).toISOString() : undefined)

  return {
    deal_value: choice === 'won' ? Number(extras.deal_value) : undefined,
    lost_reason_id: LOST.includes(choice) ? extras.lost_reason_id : undefined,
    appointment_at: choice === 'meeting_booked' ? iso(extras.appointment_at) : undefined,
    follow_up_at: FOLLOW_UP_HOURS[choice] ? iso(extras.follow_up_at) : undefined,
  }
}

export function OutcomeDetails({ choice, value, onChange }: { choice: Outcome | null; value: OutcomeExtras; onChange: (next: OutcomeExtras) => void }) {
  const { t } = useTranslation()
  const lost = choice !== null && LOST.includes(choice)
  const { data: reasons = [] } = useQuery({ queryKey: ['lost-reasons'], queryFn: fetchLostReasons, enabled: lost })

  if (choice === 'won') {
    return (
      <Input type="number" inputMode="decimal" min={1} required dir="ltr" label={t('work.details.dealValue')} hint={t('work.details.dealValueHint')}
        value={value.deal_value ?? ''} onChange={(e) => onChange({ ...value, deal_value: e.target.value })} data-testid="outcome-deal-value" />
    )
  }

  if (lost) {
    return (
      <Select label={t('work.details.lostReason')} required value={value.lost_reason_id ?? ''} onChange={(e) => onChange({ ...value, lost_reason_id: e.target.value })} data-testid="outcome-lost-reason">
        <option value="">{t('work.details.chooseReason')}</option>
        {reasons.map((reason) => <option key={reason.id} value={reason.id}>{reason.label}</option>)}
      </Select>
    )
  }

  if (choice === 'meeting_booked') {
    return (
      <Input type="datetime-local" required dir="ltr" label={t('work.details.visitAt')} hint={t('work.details.visitHint')}
        min={localInput(new Date())} value={value.appointment_at ?? ''} onChange={(e) => onChange({ ...value, appointment_at: e.target.value })} data-testid="outcome-appointment-at" />
    )
  }

  if (choice && FOLLOW_UP_HOURS[choice]) {
    return (
      <Input type="datetime-local" dir="ltr" label={t('work.details.nextFollowUp')} hint={t('work.details.nextFollowUpHint')}
        min={localInput(new Date())} value={value.follow_up_at ?? ''} onChange={(e) => onChange({ ...value, follow_up_at: e.target.value })} data-testid="outcome-follow-up-at" />
    )
  }

  return null
}
