import { CalendarCheck, CalendarX, Phone, PhoneIncoming, PhoneMissed, PhoneOutgoing } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { StatusPill, type StatusKey } from '@/components/visual'
import { humaniseDuration } from '@/components/charts/duration'
import { formatDateTime, formatRelative } from '@/lib/format'
import type { Lead, LeadAppointment, LeadCall } from '@/types/api'
import { platformColor } from '@/components/charts'
import { cn } from '@/lib/cn'

function PanelTitle({ children, count }: { children: string; count?: number }) {
  return (
    <h2 className="t-card-title mb-3 flex items-center justify-between">
      {children}
      {count !== undefined && count > 0 && <span className="text-[0.875rem] font-medium tabular-nums text-[var(--color-ink-subtle)]">{count}</span>}
    </h2>
  )
}

/** Calls as a short run of icons and durations — who, when, how long, did they pick up. */
export function CallsPanel({ calls }: { calls: LeadCall[] }) {
  const { t, i18n } = useTranslation()

  return (
    <Card data-testid="calls-panel">
      <CardBody>
        <PanelTitle count={calls.length}>{t('leadView.calls')}</PanelTitle>
        {calls.length === 0 ? (
          <p className="flex items-center gap-2 text-[0.9375rem] text-[var(--color-ink-subtle)]">
            <Phone className="size-4" aria-hidden />
            {t('leadView.noCalls')}
          </p>
        ) : (
          <ul className="space-y-2">
            {calls.slice(0, 5).map((call) => {
              const missed = call.status === 'no_answer' || call.status === 'failed'
              const Icon = missed ? PhoneMissed : call.direction === 'inbound' ? PhoneIncoming : PhoneOutgoing

              return (
                <li key={call.id} className="flex items-center gap-2.5">
                  <span
                    className={cn(
                      'grid size-8 shrink-0 place-items-center rounded-full',
                      missed
                        ? 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]'
                        : 'bg-[var(--brand-soft)] text-[var(--brand)]',
                    )}
                    aria-hidden
                  >
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[0.9375rem] font-medium">{t(`leadView.callStatus.${call.status}`)}</span>
                    <span className="block truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">
                      {call.by} · {call.at ? formatRelative(call.at, i18n.language) : ''}
                    </span>
                  </span>
                  {call.duration_seconds ? (
                    <span className="text-[0.875rem] tabular-nums text-[var(--color-ink-muted)]">{humaniseDuration(call.duration_seconds, t)}</span>
                  ) : null}
                </li>
              )
            })}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}

const APPOINTMENT_STATUS: Record<LeadAppointment['status'], StatusKey> = {
  scheduled: 'pending',
  completed: 'completed',
  cancelled: 'paused',
  no_show: 'overdue',
}

export function AppointmentsPanel({ appointments }: { appointments: LeadAppointment[] }) {
  const { t, i18n } = useTranslation()

  return (
    <Card data-testid="appointments-panel">
      <CardBody>
        <PanelTitle count={appointments.length}>{t('leadView.appointments')}</PanelTitle>
        {appointments.length === 0 ? (
          <p className="flex items-center gap-2 text-[0.9375rem] text-[var(--color-ink-subtle)]">
            <CalendarX className="size-4" aria-hidden />
            {t('leadView.noAppointments')}
          </p>
        ) : (
          <ul className="space-y-2">
            {appointments.slice(0, 4).map((appointment) => (
              <li key={appointment.id} className="flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden>
                  <CalendarCheck className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[0.9375rem] font-medium" dir="auto">
                    {formatDateTime(appointment.scheduled_at, i18n.language)}
                  </span>
                  {appointment.location && <span className="block truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{appointment.location}</span>}
                </span>
                <StatusPill status={appointment.is_past && appointment.status === 'scheduled' ? 'overdue' : APPOINTMENT_STATUS[appointment.status]} />
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}

/**
 * Where the lead came from, as a chain: platform → campaign → ad set → ad.
 *
 * The same four facts the attribution card listed as label/value rows, drawn as
 * the path the lead actually took — which is how a marketer thinks about it,
 * and what makes "which ad brought this person" a glance instead of a read.
 */
export function SourceChain({ lead }: { lead: Lead }) {
  const { t } = useTranslation()

  const steps = [
    lead.platform ? { key: 'platform', value: t(`platforms.${lead.platform}`, { defaultValue: lead.platform }), dot: platformColor(lead.platform) } : null,
    lead.campaign_name ? { key: 'campaign', value: lead.campaign_name } : null,
    lead.adset_name ? { key: 'adset', value: lead.adset_name } : null,
    lead.ad_name ? { key: 'ad', value: lead.ad_name } : null,
    lead.form_name ? { key: 'form', value: lead.form_name } : null,
  ].filter((step): step is { key: string; value: string; dot?: string } => step !== null)

  return (
    <Card data-testid="source-chain">
      <CardBody>
        <PanelTitle>{t('leadView.source')}</PanelTitle>
        {steps.length === 0 ? (
          <p className="text-[0.9375rem] text-[var(--color-ink-subtle)]">—</p>
        ) : (
          <ol className="relative space-y-3 ps-5 before:absolute before:inset-y-1 before:start-[0.3125rem] before:w-px before:bg-[var(--color-chrome-fill)]">
            {steps.map((step) => (
              <li key={step.key} className="relative">
                <span
                  className="absolute top-1.5 size-2.5 rounded-full border-2 border-[var(--color-surface)] [inset-inline-start:-1.25rem]"
                  style={{ background: step.dot ?? 'var(--brand)' }}
                  aria-hidden
                />
                <span className="block text-[0.8125rem] uppercase tracking-wide text-[var(--color-ink-subtle)]">{t(`leadView.chain.${step.key}`)}</span>
                <span className="block truncate text-[0.9375rem] font-medium" dir="auto">{step.value}</span>
              </li>
            ))}
          </ol>
        )}
      </CardBody>
    </Card>
  )
}
