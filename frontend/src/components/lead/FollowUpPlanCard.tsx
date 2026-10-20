import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CalendarClock, CheckCircle2, CircleDashed, Mail, MessageCircle, MinusCircle, Pause, Phone, Play, Square, UserCog, XCircle, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Modal } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { useApiError } from '@/hooks/useApiError'
import {
  pauseFollowUp, rescheduleFollowUp, resumeFollowUp, stopFollowUp,
  type FollowUpPlan, type PlanChannel, type PlanStep, type StepState,
} from '@/features/followUpPlan'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

const CHANNEL: Record<PlanChannel, LucideIcon> = { whatsapp: MessageCircle, email: Mail, call: Phone, team: UserCog }

const STATE: Record<StepState, { icon: LucideIcon; color: string }> = {
  done: { icon: CheckCircle2, color: 'var(--color-success)' },
  planned: { icon: CircleDashed, color: 'var(--brand)' },
  skipped: { icon: MinusCircle, color: 'var(--color-ink-subtle)' },
  cancelled: { icon: MinusCircle, color: 'var(--color-ink-subtle)' },
  failed: { icon: XCircle, color: 'var(--color-danger)' },
}

const TONE = { active: 'good', paused: 'warn', stopped: 'neutral', completed: 'brand' } as const

/** `15m+quiet_hours` → the reason and whether the night moved it. */
const split = (why: string) => {
  const [reason, ...rest] = why.split('+')

  return { reason, quiet: rest.includes('quiet_hours') }
}

/**
 * The next week of follow-up for this lead, inside the lead's own workspace:
 * what happens next, why, on which channel, when, how far along the week is,
 * and the three things a person can do about it — pause, move it, stop it.
 *
 * It never sends anything. It shows the plan and acts on the plan.
 */
export function FollowUpPlanCard({ leadId, plan, onChanged }: { leadId: string; plan: FollowUpPlan; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [moving, setMoving] = useState(false)
  const [at, setAt] = useState('')

  const done = (key: string) => () => {
    toast(t(`followPlan.done.${key}`), 'success')
    setMoving(false)
    setAt('')
    onChanged()
  }
  const fail = (caught: unknown) => toast(translateError(caught), 'error')

  const pause = useMutation({ mutationFn: () => pauseFollowUp(leadId), onSuccess: done('pause'), onError: fail })
  const resume = useMutation({ mutationFn: () => resumeFollowUp(leadId), onSuccess: done('resume'), onError: fail })
  const stop = useMutation({ mutationFn: () => stopFollowUp(leadId), onSuccess: done('stop'), onError: fail })
  const move = useMutation({ mutationFn: () => rescheduleFollowUp(leadId, new Date(at).toISOString()), onSuccess: done('reschedule'), onError: fail })

  const next = plan.next
  const NextIcon = next ? CHANNEL[next.channel] : CalendarClock
  const percent = plan.progress.total > 0 ? Math.round((plan.progress.done / plan.progress.total) * 100) : 0
  const nextWhy = next ? split(next.why) : null
  const busy = pause.isPending || resume.isPending || stop.isPending

  return (
    <Card data-testid="follow-up-plan" data-status={plan.status} data-decision={plan.decision}>
      <CardBody className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="flex items-center gap-2 text-[1.0625rem] font-extrabold">
            <CalendarClock className="size-5 text-[var(--brand)]" aria-hidden />
            {t('followPlan.title')}
          </h3>
          <span
            data-testid="plan-status"
            className={cn(
              'rounded-full px-2.5 py-0.5 text-[0.9375rem] font-bold',
              plan.status === 'active' && 'bg-[var(--brand-soft)] text-[var(--brand)]',
              plan.status === 'paused' && 'bg-[color-mix(in_srgb,var(--color-warning)_16%,transparent)] text-[var(--color-warning)]',
              (plan.status === 'stopped' || plan.status === 'completed') && 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]',
            )}
          >
            {t(`followPlan.status.${plan.status}`)}
          </span>
        </div>

        <div className="flex items-center gap-3">
          <RingProgress value={percent} size={56} thickness={6} tone={TONE[plan.status]} label={t('followPlan.progress')}>
            <span className="text-[0.9375rem] font-bold tabular-nums" dir="ltr" data-testid="plan-progress">{plan.progress.done}/{plan.progress.total}</span>
          </RingProgress>
          <dl className="min-w-0 flex-1 space-y-1 text-[1rem]">
            <div className="flex items-baseline gap-2" data-testid="plan-next">
              <dt className="shrink-0 text-[0.875rem] text-[var(--color-ink-subtle)]">{t('followPlan.next')}</dt>
              <dd className="min-w-0 font-bold">
                {next ? (
                  <span data-testid="plan-next-at">{formatDateTime(next.at, i18n.language)}</span>
                ) : (
                  <span className="text-[var(--color-ink-muted)]" data-testid="plan-next-none">{t('followPlan.nothingNext')}</span>
                )}
              </dd>
            </div>
            {next && (
              <div className="flex items-center gap-2" data-testid="plan-channel" data-channel={next.channel}>
                <dt className="shrink-0 text-[0.875rem] text-[var(--color-ink-subtle)]">{t('followPlan.channel')}</dt>
                <dd className="flex items-center gap-1.5 font-semibold">
                  <NextIcon className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
                  {t(`followPlan.channels.${next.channel}`)}
                  {next.fallback && <span className="rounded-full bg-[var(--color-surface-muted)] px-2 py-0.5 text-[0.8125rem]">{t('followPlan.fallback')}</span>}
                </dd>
              </div>
            )}
          </dl>
        </div>

        <p className="text-[1rem] font-semibold" data-testid="plan-reason" data-reason={plan.reason}>
          <span className="text-[0.875rem] font-medium text-[var(--color-ink-subtle)]">{t('followPlan.reason')}: </span>
          {t(`followPlan.reasons.${plan.reason}`, { defaultValue: plan.reason })}
          {nextWhy && plan.status === 'active' && nextWhy.reason !== plan.reason && (
            <span className="ms-1.5 font-normal text-[var(--color-ink-muted)]">· {t(`followPlan.why.${nextWhy.reason}`, { defaultValue: nextWhy.reason })}</span>
          )}
          {nextWhy?.quiet && plan.status === 'active' && (
            <span className="ms-1.5 rounded-full bg-[var(--color-surface-muted)] px-2 py-0.5 text-[0.8125rem] font-medium" data-testid="plan-quiet">{t('followPlan.quiet')}</span>
          )}
        </p>

        <ol className="grid grid-cols-6 gap-1" data-testid="plan-steps" aria-label={t('followPlan.sequence')}>
          {plan.steps.map((step: PlanStep) => {
            const { icon: StateIcon, color } = STATE[step.state]
            const Channel = CHANNEL[step.channel]

            return (
              <li
                key={step.key}
                data-testid={`plan-step-${step.key}`}
                data-state={step.state}
                title={`${formatDateTime(step.at, i18n.language)} · ${t(`followPlan.channels.${step.channel}`)} · ${t(`followPlan.why.${split(step.why).reason}`, { defaultValue: '' })}`}
                className="flex flex-col items-center gap-0.5 rounded-lg bg-[var(--color-surface-muted)] px-1 py-1.5 text-center"
              >
                <StateIcon className="size-4" style={{ color }} aria-hidden />
                <Channel className={cn('size-3.5', step.state === 'planned' ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-subtle)]')} aria-hidden />
                <span className="text-[0.8125rem] font-bold tabular-nums">{t(`followPlan.rung.${step.key}`)}</span>
              </li>
            )
          })}
        </ol>

        <div className="flex flex-wrap gap-2">
          {plan.can.pause && (
            <Button size="sm" variant="outline" icon={<Pause className="size-4" />} loading={pause.isPending} disabled={busy} onClick={() => pause.mutate()} data-testid="plan-pause">
              {t('followPlan.pause')}
            </Button>
          )}
          {plan.can.resume && (
            <Button size="sm" variant="primary" icon={<Play className="size-4" />} loading={resume.isPending} disabled={busy} onClick={() => resume.mutate()} data-testid="plan-resume">
              {t('followPlan.resume')}
            </Button>
          )}
          <Button size="sm" variant="outline" icon={<CalendarClock className="size-4" />} disabled={!plan.can.reschedule || busy} onClick={() => setMoving(true)} data-testid="plan-reschedule">
            {t('followPlan.reschedule')}
          </Button>
          <Button size="sm" variant="outline" icon={<Square className="size-4" />} loading={stop.isPending} disabled={!plan.can.stop || busy} onClick={() => stop.mutate()} data-testid="plan-stop">
            {t('followPlan.stop')}
          </Button>
        </div>
      </CardBody>

      <Modal
        open={moving}
        onClose={() => setMoving(false)}
        title={t('followPlan.rescheduleTitle')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setMoving(false)}>{t('common.cancel')}</Button>
            <Button disabled={!at} loading={move.isPending} onClick={() => move.mutate()} data-testid="plan-reschedule-confirm">{t('followPlan.rescheduleConfirm')}</Button>
          </>
        }
      >
        <p className="mb-3 text-[1rem] text-[var(--color-ink-muted)]">{t('followPlan.rescheduleHint')}</p>
        <Input label={t('followPlan.rescheduleWhen')} type="datetime-local" dir="ltr" value={at} onChange={(e) => setAt(e.target.value)} data-testid="plan-reschedule-at" />
      </Modal>
    </Card>
  )
}
