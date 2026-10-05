import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlarmClock, CalendarPlus, MessageCircle, PartyPopper, Phone, PhoneOff, SkipForward,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchNextInQueue, fetchWorkSummary, logContact, type NextAction } from '@/features/work'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen, telLink, whatsappLink } from '@/lib/format'
import {
  Badge, Button, ButtonLink, buttonClasses, Card, CardBody, EmptyState, ErrorState,
  PageHeader, Skeleton, StatusBadge, TemperatureBadge, Textarea,
} from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * One lead, one decision, one keystroke.
 *
 * The rest of the app is a CRM: lists, filters, a board. This screen is the
 * machine. It asks the server for the single most urgent lead the rep could
 * work right now, shows why it is that one, and offers the two or three things
 * worth doing — then hands over the next one. A rep who lives here never
 * chooses what to work on, which is the whole point: choosing is where the
 * pipeline leaks.
 */

/** The urgency colours. Deliberately not the tenant brand colour: a red that
 *  turns teal because a customer picked teal stops meaning "now". */
const URGENCY: Record<NextAction['urgency'], string> = {
  critical: 'border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5',
  high: 'border-[var(--color-warning)]/40 bg-[var(--color-warning)]/5',
  medium: 'border-[var(--color-border)] bg-[var(--color-surface)]',
  low: 'border-[var(--color-border)] bg-[var(--color-surface)]',
  none: 'border-[var(--color-border)] bg-[var(--color-surface)]',
}

const URGENCY_BADGE: Record<NextAction['urgency'], 'danger' | 'warning' | 'neutral'> = {
  critical: 'danger',
  high: 'warning',
  medium: 'neutral',
  low: 'neutral',
  none: 'neutral',
}

export function WorkPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [note, setNote] = useState('')
  /** Leads the rep passed over in this sitting, so the queue moves on. */
  const [skipped, setSkipped] = useState<string[]>([])

  const summary = useQuery({ queryKey: ['work-summary'], queryFn: fetchWorkSummary })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['work-next', skipped],
    queryFn: () => fetchNextInQueue(skipped),
  })

  const lead = data?.lead
  const action = data?.action

  /** Logging an outcome is what advances the queue — there is no "next" button
   *  that merely hides a lead, because a hidden lead is one nobody called. */
  const log = useMutation({
    mutationFn: (payload: { channel: string; outcome?: string; note?: string }) =>
      logContact(lead!.id, payload),
    onSuccess: () => {
      setNote('')
      toast(t('work.logged'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['work-next'] })
      void queryClient.invalidateQueries({ queryKey: ['work-summary'] })
      void queryClient.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function skip() {
    if (!lead) return
    setSkipped((prev) => [...prev, lead.id])
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('work.title')} subtitle={t('work.subtitle')} />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('work.title')} />
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('work.title')}
        subtitle={
          data && data.remaining > 0
            ? t('work.remaining', { count: data.remaining })
            : t('work.subtitle')
        }
      />

      {summary.data && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <QueueStat label={t('work.stats.uncontacted')} value={summary.data.uncontacted} />
          <QueueStat
            label={t('work.stats.overdueFirstContact')}
            value={summary.data.overdue_first_contact}
            alarming
          />
          <QueueStat
            label={t('work.stats.overdueFollowUps')}
            value={summary.data.overdue_follow_ups}
            alarming
          />
          <QueueStat label={t('work.stats.hot')} value={summary.data.hot} />
        </div>
      )}

      {!lead || !action ? (
        <Card>
          <CardBody>
            <EmptyState
              icon={<PartyPopper className="size-6" aria-hidden />}
              title={t('work.empty.title')}
              body={t('work.empty.description')}
            />
          </CardBody>
        </Card>
      ) : (
        <Card className={cn('border-2', URGENCY[action.urgency])}>
          <CardBody className="space-y-5">
            {/* Why this lead, before who it is. The reason is the product. */}
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={URGENCY_BADGE[action.urgency]}>
                {t(`work.urgency.${action.urgency}`)}
              </Badge>
              <span className="t-body font-medium">{t(`work.action.${action.action}`)}</span>
              {action.due_in_minutes !== null && action.due_in_minutes < 0 && (
                <span className="t-caption inline-flex items-center gap-1 text-[var(--color-danger)]">
                  <AlarmClock className="size-3.5" />
                  {t('work.overdueBy', { minutes: Math.abs(action.due_in_minutes) })}
                </span>
              )}
            </div>

            <p className="t-caption">{action.reason}</p>

            <div className="flex flex-wrap items-start justify-between gap-4 border-t border-[var(--color-border)] pt-4">
              <div className="min-w-0 space-y-1.5">
                <Link to={`/leads/${lead.id}`} className="t-h3 hover:underline">
                  {lead.full_name}
                </Link>
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={lead.status} />
                  <TemperatureBadge temperature={lead.temperature} />
                  {lead.city && <span className="t-caption">{lead.city}</span>}
                </div>
                <p className="t-caption">
                  {[lead.project?.name, lead.campaign_name, lead.platform]
                    .filter(Boolean)
                    .join(' · ')}
                </p>
                <p className="t-caption">
                  {t('work.arrived', { when: formatLastSeen(lead.created_at, i18n.language) })}
                </p>
              </div>

              {/* The phone number, large and tappable: this screen exists to
                  get a rep onto a call, not to be read. */}
              {lead.phone && (
                <a href={telLink(lead.phone) ?? '#'} className="t-h3 tabular-nums hover:underline">
                  <bdi dir="ltr">{lead.phone}</bdi>
                </a>
              )}
            </div>

            <div className="flex flex-wrap gap-2">
              {lead.phone && (
                <ButtonLink
                  href={telLink(lead.phone) ?? undefined}
                  icon={<Phone className="size-4" aria-hidden />}
                  onClick={() => log.mutate({ channel: 'call', note: note || undefined })}
                >
                  {t('work.buttons.call')}
                </ButtonLink>
              )}

              {lead.whatsapp && (
                <ButtonLink
                  variant="secondary"
                  href={whatsappLink(lead.whatsapp) ?? undefined}
                  target="_blank"
                  rel="noreferrer"
                  icon={<MessageCircle className="size-4" aria-hidden />}
                  onClick={() => log.mutate({ channel: 'whatsapp', note: note || undefined })}
                >
                  {t('work.buttons.whatsapp')}
                </ButtonLink>
              )}

              <Button
                variant="secondary"
                loading={log.isPending}
                icon={<PhoneOff className="size-4" aria-hidden />}
                onClick={() => log.mutate({ channel: 'no_answer', note: note || undefined })}
              >
                {t('work.buttons.noAnswer')}
              </Button>

              <Link to={`/leads/${lead.id}`} className={buttonClasses('ghost')}>
                <CalendarPlus className="size-4" aria-hidden />
                {t('work.buttons.openLead')}
              </Link>

              <Button
                variant="ghost"
                icon={<SkipForward className="size-4" aria-hidden />}
                onClick={skip}
                disabled={log.isPending}
              >
                {t('work.buttons.skip')}
              </Button>
            </div>

            <Textarea
              label={t('work.noteLabel')}
              hint={t('work.noteHint')}
              rows={2}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </CardBody>
        </Card>
      )}
    </div>
  )
}

function QueueStat({
  label,
  value,
  alarming = false,
}: {
  label: string
  value: number
  alarming?: boolean
}) {
  return (
    <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
      <p className="t-caption">{label}</p>
      <p
        className={cn(
          't-h3 mt-0.5 tabular-nums',
          alarming && value > 0 && 'text-[var(--color-danger)]',
        )}
      >
        {value}
      </p>
    </div>
  )
}
