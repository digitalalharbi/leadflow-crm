import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, MessageCircle, PartyPopper, Phone, SkipForward } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  fetchNextInQueue, fetchWorkSummary, logContact, recordOutcome,
  OUTCOMES, type NextAction, type Outcome,
} from '@/features/work'
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

  /*
   * The queue moves while a rep is looking at it: colleagues work leads, new
   * ones arrive, the SLA sweep escalates. Polling rather than a socket — this
   * is a number in a corner, not a chat window, and a minute of staleness is
   * invisible where a websocket server in the deploy is not.
   *
   * TanStack pauses this while the tab is hidden, so a rep with the screen open
   * all day costs one request a minute only while they are looking at it.
   */
  const summary = useQuery({
    queryKey: ['work-summary'],
    queryFn: fetchWorkSummary,
    refetchInterval: 60_000,
  })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['work-next', skipped],
    queryFn: () => fetchNextInQueue(skipped),
    /*
     * Not polled. The card is what the rep is about to act on, and swapping it
     * underneath them mid-dial is how somebody calls the wrong person. It
     * changes when they record an outcome, which is the only moment they are
     * not holding a phone.
     */
    refetchOnWindowFocus: false,
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

  /**
   * One press: the server records the result, moves the stage, books the next
   * step and returns the next lead. The response seeds the cache for the key
   * the new skip list produces, so the card changes without a second request —
   * the pause between "done" and "next" is where a rep stops and checks their
   * phone instead.
   */
  const outcome = useMutation({
    mutationFn: (choice: Outcome) =>
      recordOutcome({
        lead_id: lead!.id,
        outcome: choice,
        note: note || undefined,
        skip: skipped,
      }),
    onSuccess: (result, choice) => {
      const advanced = [...skipped, lead!.id]

      queryClient.setQueryData(['work-next', advanced], result.next)
      setSkipped(advanced)
      setNote('')

      toast(t(`work.recorded.${choice}`), 'success')
      void queryClient.invalidateQueries({ queryKey: ['work-summary'] })
      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      void queryClient.invalidateQueries({ queryKey: ['follow-up-counts'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const skip = useCallback(() => {
    if (!lead) return
    setSkipped((prev) => [...prev, lead.id])
  }, [lead])

  /*
   * Shortcuts, because this screen is used with a phone in one hand. They are
   * ignored while the note field has focus — a rep typing "و" in a note should
   * not record "won" — and never fire with a modifier held, so the browser's
   * own shortcuts keep working.
   */
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.metaKey || event.ctrlKey || event.altKey) return

      const target = event.target as HTMLElement | null
      if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return
      if (target?.isContentEditable) return
      if (!lead || outcome.isPending) return

      const index = Number(event.key)

      if (index >= 1 && index <= OUTCOMES.length) {
        event.preventDefault()
        outcome.mutate(OUTCOMES[index - 1])
        return
      }

      if (event.key.toLowerCase() === 's') {
        event.preventDefault()
        skip()
      }
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [lead, outcome, skip])

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
          <CardBody data-testid="work-empty">
            <EmptyState
              icon={<PartyPopper className="size-6" aria-hidden />}
              title={t('work.empty.title')}
              body={t('work.empty.description')}
            />
          </CardBody>
        </Card>
      ) : (
        <Card className={cn('border-2', URGENCY[action.urgency])} data-testid="work-card">
          <CardBody className="space-y-5">
            {/* Why this lead, before who it is. The reason is the product. */}
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={URGENCY_BADGE[action.urgency]} testId="work-urgency">
                {t(`work.urgency.${action.urgency}`)}
              </Badge>
              <span className="t-body font-medium" data-testid="work-action">
                {t(`work.action.${action.action}`)}
              </span>
              {/*
                No overdue chip here. It said the same words as the reason line
                directly beneath it — "متأخر 3 أيام" above "متابعة متأخرة بـ 3
                أيام" — and a card that says a thing twice reads as a card that
                is padding. The urgency badge carries the alarm; the reason
                carries the detail.
              */}
            </div>

            <p className="t-caption" data-testid="work-reason">
              {action.reason}
            </p>

            <div className="flex flex-wrap items-start justify-between gap-4 border-t border-[var(--color-border)] pt-4">
              <div className="min-w-0 space-y-1.5">
                <Link to={`/leads/${lead.id}`} className="t-h3 hover:underline" data-testid="work-lead-name">
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
                variant="ghost"
                icon={<SkipForward className="size-4" aria-hidden />}
                onClick={skip}
                disabled={outcome.isPending}
                data-testid="work-skip"
              >
                {t('work.buttons.skip')}
                <Kbd>S</Kbd>
              </Button>

              <Link to={`/leads/${lead.id}`} className={buttonClasses('ghost')} data-testid="work-open-lead">
                <CalendarPlus className="size-4" aria-hidden />
                {t('work.buttons.openLead')}
              </Link>
            </div>

            {/*
              What happened, in the words a rep would use. Pressing one of these
              is the whole interaction: the stage, the next step and the next
              lead all follow from it, so nothing else on this screen needs to
              be touched afterwards.
            */}
            <div className="space-y-2 border-t border-[var(--color-border)] pt-4">
              <p className="t-caption">{t('work.outcomePrompt')}</p>
              <div className="flex flex-wrap gap-2">
                {OUTCOMES.map((choice, index) => (
                  <Button
                    key={choice}
                    variant={choice === 'won' ? 'primary' : choice === 'lost' ? 'danger' : 'outline'}
                    size="sm"
                    disabled={outcome.isPending}
                    data-testid={`outcome-${choice}`}
                    onClick={() => outcome.mutate(choice)}
                  >
                    {t(`work.outcome.${choice}`)}
                    <Kbd>{index + 1}</Kbd>
                  </Button>
                ))}
              </div>
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

/** The shortcut, shown on the button it triggers — a shortcut nobody can see
 *  is one only its author uses. */
function Kbd({ children }: { children: React.ReactNode }) {
  return (
    <kbd
      className="ms-1 rounded border border-current/25 px-1 font-mono text-[0.6875rem] leading-4 opacity-60"
      aria-hidden
    >
      {children}
    </kbd>
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
