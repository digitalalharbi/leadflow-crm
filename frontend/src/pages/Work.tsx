import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Bot, CalendarCheck, CalendarPlus, FileText, Flame, MapPin, MessageCircle, MessagesSquare, PartyPopper, Phone, SkipForward, TimerOff, UserX, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  fetchNextInQueue, fetchWorkSummary, logContact, recordCall, recordOutcome,
  OUTCOMES, type NextAction, type Outcome, type QueuedLead, type QueueTimelineEntry, type WorkSummary,
} from '@/features/work'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen, telLink, whatsappLink } from '@/lib/format'
import { WorkContactPanel } from '@/components/work/WorkContactPanel'
import { AppointmentDialog } from '@/components/lead/AppointmentDialog'
import {
  Badge, Button, ButtonLink, buttonClasses, Card, CardBody, CardHeader, Drawer, EmptyState, ErrorState,
  PageHeader, Skeleton, StatusBadge, TemperatureBadge, Textarea,
} from '@/components/ui'
import { cn } from '@/lib/cn'
import { HandoffBriefCard } from '@/components/lead/HandoffBriefCard'
import { RingProgress } from '@/components/visual'
import { InterestBadge } from '@/components/visual/InterestBadge'
import { InterestStrip } from '@/components/work/InterestStrip'

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
  const workspaceLanguage = useAuth((s) => s.workspaceLanguage)
  const translateError = useApiError()

  const [note, setNote] = useState('')
  /** The side panel for writing to the lead — email, WhatsApp and files — without leaving the queue. */
  const [contactOpen, setContactOpen] = useState(false)
  const [appointmentOpen, setAppointmentOpen] = useState(false)
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
  /*
   * The call the rep has just started, so the outcome they press next can say
   * how it went. Nothing watches a rep's phone, so this is the only moment the
   * product can learn it — and only because the person is about to say.
   */
  const [openCall, setOpenCall] = useState<{ leadId: string; callId: string } | null>(null)

  const log = useMutation({
    mutationFn: (payload: { channel: string; outcome?: string; note?: string }) =>
      logContact(lead!.id, payload),
    onSuccess: (result) => {
      if (result.call) {
        setOpenCall({ leadId: lead!.id, callId: result.call.id })
      }

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
      /*
       * Close the call with what the rep just said. `answered` for everything
       * except the two that mean nobody picked up — which is exactly what the
       * rep is telling us by pressing them.
       */
      if (openCall && openCall.leadId === lead!.id) {
        void recordCall(openCall.leadId, openCall.callId, {
          status: choice === 'no_answer' ? 'no_answer' : 'answered',
          outcome: choice,
        })

        setOpenCall(null)
      }

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
      <div className="space-y-3">
        <PageHeader title={t('work.title')} subtitle={t('work.subtitle')} />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-3">
        <PageHeader title={t('work.title')} />
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
      </div>
    )
  }

  const queue = data?.queue ?? []
  const timeline = data?.timeline ?? []

  return (
    <div className="space-y-2.5">
      <PageHeader
        title={t('work.title')}
        subtitle={
          data && data.remaining > 0
            ? t('work.remaining', { count: data.remaining })
            : t('work.subtitle')
        }
      />

      {/* The shift at a glance: how much of the open list has heard from us,
          and the four numbers that are the reason the rep is here — one row,
          never a card each. */}
      {summary.data && <WorkPulse summary={summary.data} />}

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
        /*
         * Three columns, one screen.
         *
         * The card is the work; the queue beside it answers "is the order
         * sane", and the history answers "has anybody already tried today" —
         * both of which a rep used to get by clicking away from the card and
         * losing their place. On a laptop none of it scrolls; below `lg` the
         * two rails fall underneath, because a 3-column layout on a narrow
         * screen is three columns of nothing.
         */
        <div className="grid grid-cols-1 items-start gap-2.5 lg:grid-cols-12">
          <aside className="order-2 lg:order-1 lg:col-span-3">
            <Card className="h-full">
              <CardHeader title={t('work.queue.title')} />
              <QueueMix queue={queue} />
              <CardBody className="max-h-[22rem] space-y-0.5 overflow-y-auto p-2 lg:max-h-[calc(100dvh-21rem)]">
                {queue.length === 0 ? (
                  <p className="t-caption px-1.5 py-1">{t('work.queue.empty')}</p>
                ) : (
                  queue.map((item: QueuedLead) => (
                    <div
                      key={item.id}
                      data-testid={`queue-item-${item.id}`}
                      className="flex items-center gap-2.5 rounded-xl px-2 py-1.5 transition-colors hover:bg-[var(--color-surface-muted)]"
                    >
                      <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: URGENCY_DOT[item.urgency ?? 'none'] }} />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                          <span className="t-small truncate font-medium">{item.name}</span>
                          {item.urgency && item.urgency !== 'none' && (
                            <Badge tone={URGENCY_BADGE[item.urgency]}>
                              {t(`work.urgency.${item.urgency}`)}
                            </Badge>
                          )}
                        </div>
                        {item.interest_level && ['interested', 'hot', 'visit_ready', 'reservation_ready'].includes(item.interest_level) && (
                          <InterestBadge level={item.interest_level} testId={`queue-interest-${item.id}`} className="mb-0.5 !px-1.5 !py-0 !text-[0.8125rem]" />
                        )}
                        <p className="t-caption truncate">
                          {[item.action ? t(`work.action.${item.action}`) : null, item.city]
                            .filter(Boolean)
                            .join(' · ')}
                        </p>
                        {item.rank_reason && (
                          <p className="t-caption truncate text-[var(--color-ink-subtle)]" data-testid={`queue-rank-${item.id}`}>
                            {item.rank_reason}
                          </p>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>
          </aside>

          <section className="order-1 lg:order-2 lg:col-span-6">
            <Card className={cn('border-2', URGENCY[action.urgency])} data-testid="work-card">
              <CardBody className="space-y-2.5">
                {/* Why this lead, before who it is. The reason is the product. */}
                <div className="flex items-center gap-2.5 rounded-xl bg-[var(--color-surface)]/70 p-2">
                  <span
                    aria-hidden
                    className="grid size-8 shrink-0 place-items-center rounded-full text-white"
                    style={{ background: URGENCY_DOT[action.urgency] }}
                  >
                    <Zap className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="t-small font-semibold" data-testid="work-action">
                        {t(`work.action.${action.action}`)}
                      </span>
                      <Badge tone={URGENCY_BADGE[action.urgency]} testId="work-urgency">
                        {t(`work.urgency.${action.urgency}`)}
                      </Badge>
                    </div>
                    <p className="t-caption truncate" data-testid="work-reason">
                      {action.reason}
                    </p>
                    {data?.rank?.reason && (
                      <p className="t-caption truncate font-semibold text-[var(--color-ink)]" data-testid="work-rank-reason" title={t('work.rank.title')}>
                        {t('work.rank.label')}: {data.rank.reason}
                      </p>
                    )}
                    {(data?.signals?.length ?? 0) > 0 && (
                      <ul className="mt-1 flex flex-wrap gap-1" data-testid="work-signals">
                        {data!.signals!.map((signal) => (
                          <li key={signal} className="rounded-full bg-[var(--color-surface)] px-2 py-0.5 text-[0.8125rem] font-medium">
                            {t(`work.signal.${signal}`)}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>

                {/* How far along they are, how long they may still wait, and what they want. */}
                {data && <InterestStrip data={data} />}

                {/* Automation handed this lead over: everything to start the call, without opening the lead. */}
                {data?.handoff && <HandoffBriefCard brief={data.handoff} compact />}

                <div className="flex flex-wrap items-start justify-between gap-3 pt-0.5">
                  <div className="flex min-w-0 items-start gap-3">
                  {/* The score where the face would be: how serious, at a glance. */}
                  <RingProgress
                    value={lead.score ?? 0}
                    size={52}
                    thickness={5}
                    tone={(lead.score ?? 0) >= 70 ? 'good' : (lead.score ?? 0) >= 40 ? 'warn' : 'neutral'}
                    label={t('leads.score')}
                    testId="work-score"
                  >
                    <span className="text-[1.0625rem] font-extrabold tabular-nums">{lead.score ?? 0}</span>
                  </RingProgress>
                  <div className="min-w-0 space-y-1">
                    <Link
                      to={`/leads/${lead.id}`}
                      className="t-section-title hover:underline"
                      data-testid="work-lead-name"
                    >
                      {lead.full_name}
                    </Link>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <StatusBadge status={lead.status} />
                      <TemperatureBadge temperature={lead.temperature} priority={lead.is_priority} />
                      {/*
                        Which language to open the call in — read off the
                        number's country code, and shown only when it is not the
                        one this workspace works in.
                      */}
                      {lead.language && lead.language !== workspaceLanguage && (
                        <Badge tone="info" testId="work-language">
                          {t(`languages.${lead.language}`, { defaultValue: lead.language })}
                        </Badge>
                      )}
                      {lead.city && <span className="t-caption">{lead.city}</span>}
                    </div>
                    <p className="t-caption truncate">
                      {[lead.project?.name, lead.campaign_name, lead.platform]
                        .filter(Boolean)
                        .join(' · ')}
                      {' · '}
                      {t('work.arrived', { when: formatLastSeen(lead.created_at, i18n.language) })}
                    </p>
                  </div>
                  </div>

                  {/* The number, still the biggest thing on the card: this
                      screen exists to get a rep onto a call. */}
                  {lead.phone && (
                    <a
                      href={telLink(lead.phone) ?? '#'}
                      className="t-section-title tabular-nums hover:underline"
                    >
                      <bdi dir="ltr">{lead.phone}</bdi>
                    </a>
                  )}
                </div>

                <div className="flex flex-wrap gap-1.5">
                  {lead.phone && (
                    <ButtonLink
                      size="sm"
                      href={telLink(lead.phone) ?? undefined}
                      icon={<Phone className="size-3.5" aria-hidden />}
                      onClick={() => log.mutate({ channel: 'call', note: note || undefined })}
                    >
                      {t('work.buttons.call')}
                    </ButtonLink>
                  )}

                  {lead.whatsapp && (
                    <ButtonLink
                      size="sm"
                      variant="secondary"
                      href={whatsappLink(lead.whatsapp) ?? undefined}
                      target="_blank"
                      rel="noreferrer"
                      icon={<MessageCircle className="size-3.5" aria-hidden />}
                      onClick={() => log.mutate({ channel: 'whatsapp', note: note || undefined })}
                    >
                      {t('work.buttons.whatsapp')}
                    </ButtonLink>
                  )}

                  <Button
                    variant="secondary"
                    size="sm"
                    icon={<MessagesSquare className="size-3.5" aria-hidden />}
                    onClick={() => setContactOpen(true)}
                    data-testid="work-contact"
                  >
                    {t('work.buttons.contact')}
                  </Button>

                  <Button
                    variant="ghost"
                    size="sm"
                    icon={<SkipForward className="size-3.5" aria-hidden />}
                    onClick={skip}
                    disabled={outcome.isPending}
                    data-testid="work-skip"
                  >
                    {t('work.buttons.skip')}
                    <Kbd>S</Kbd>
                  </Button>

                  <Button
                    variant="secondary"
                    size="sm"
                    icon={<FileText className="size-3.5" aria-hidden />}
                    onClick={() => setContactOpen(true)}
                    data-testid="work-file"
                  >
                    {t('work.more.brochure')}
                  </Button>

                  {/* The project's map pin, in the lead's WhatsApp, in one press. */}
                  {lead.whatsapp && data?.profile?.map_url && (
                    <ButtonLink
                      size="sm"
                      variant="secondary"
                      href={whatsappLink(lead.whatsapp, '{{text}}', { text: t('work.more.locationText', { project: data.profile.project ?? '', url: data.profile.map_url }) }) ?? undefined}
                      target="_blank"
                      rel="noreferrer"
                      icon={<MapPin className="size-3.5" aria-hidden />}
                      onClick={() => log.mutate({ channel: 'whatsapp', note: t('work.more.location') })}
                      data-testid="work-location"
                    >
                      {t('work.more.location')}
                    </ButtonLink>
                  )}

                  <Button
                    variant="secondary"
                    size="sm"
                    icon={<CalendarCheck className="size-3.5" aria-hidden />}
                    onClick={() => setAppointmentOpen(true)}
                    data-testid="work-appointment"
                  >
                    {t('leadView.appointment')}
                  </Button>

                  <Link
                    to={`/leads/${lead.id}`}
                    className={buttonClasses('ghost', 'sm')}
                    data-testid="work-open-lead"
                  >
                    <CalendarPlus className="size-3.5" aria-hidden />
                    {t('work.buttons.openLead')}
                  </Link>
                </div>

                {/*
                  What happened, in the words a rep would use. Pressing one of
                  these is the whole interaction: the stage, the next step and
                  the next lead all follow from it.
                */}
                <div className="space-y-1.5 border-t border-[var(--color-border)] pt-2.5">
                  <p className="t-caption">{t('work.outcomePrompt')}</p>
                  <div className="flex flex-wrap gap-1.5">
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
                  rows={2}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                />
              </CardBody>
            </Card>
          </section>

          <aside className="order-3 lg:col-span-3">
            <Card className="h-full">
              <CardHeader title={t('work.timeline.title')} />
              <CardBody className="max-h-[22rem] space-y-1.5 overflow-y-auto p-2.5 lg:max-h-[calc(100dvh-19rem)]">
                {timeline.length === 0 ? (
                  <p className="t-caption">{t('work.timeline.empty')}</p>
                ) : (
                  <ol className="relative space-y-2.5 ps-4 before:absolute before:inset-y-1 before:start-[0.3125rem] before:w-px before:bg-[var(--color-line)]">
                    {timeline.map((entry: QueueTimelineEntry, index) => (
                      <li key={entry.id} className="relative flex items-baseline justify-between gap-2">
                        <span
                          aria-hidden
                          className={cn(
                            'absolute -start-4 top-1.5 size-2.5 rounded-full border-2 border-[var(--color-surface)]',
                            index === 0 ? 'bg-[var(--brand)]' : 'bg-[var(--color-border-strong)]',
                          )}
                        />
                        <span className="t-small truncate">
                          {t(`activity.${entry.type}`, { defaultValue: entry.type })}
                        </span>
                        <span className="t-caption shrink-0">
                          {entry.at ? formatLastSeen(entry.at, i18n.language) : ''}
                        </span>
                      </li>
                    ))}
                  </ol>
                )}
              </CardBody>
            </Card>
          </aside>
        </div>
      )}

      {/*
        Keyed by the lead, so a card that changes underneath an open panel
        cannot leave a half-written email addressed to somebody else.
      */}
      {lead && (
        <AppointmentDialog
          leadId={lead.id}
          open={appointmentOpen}
          onClose={() => setAppointmentOpen(false)}
          onBooked={() => {
            setAppointmentOpen(false)
            void queryClient.invalidateQueries({ queryKey: ['work-summary'] })
          }}
        />
      )}

      <Drawer
        open={contactOpen && Boolean(lead)}
        onClose={() => setContactOpen(false)}
        title={lead?.full_name}
        description={t('work.contactHint')}
        size="lg"
        testId="work-contact-drawer"
      >
        {lead && <WorkContactPanel key={lead.id} leadId={lead.id} />}
      </Drawer>
    </div>
  )
}

/** The shortcut, shown on the button it triggers — a shortcut nobody can see
 *  is one only its author uses. */
function Kbd({ children }: { children: React.ReactNode }) {
  return (
    <kbd
      className="ms-1 rounded border border-current/25 px-1 font-mono text-[0.8125rem] leading-4 opacity-60"
      aria-hidden
    >
      {children}
    </kbd>
  )
}

/** Urgency as a colour for a dot or a disc — the same three meanings as the badge. */
const URGENCY_DOT: Record<NextAction['urgency'], string> = {
  critical: 'var(--color-danger)',
  high: 'var(--color-warning)',
  medium: 'var(--brand)',
  low: 'var(--color-ink-subtle)',
  none: 'var(--color-border-strong)',
}

/**
 * The shift in one row. The ring is the share of the open list that has heard
 * from somebody — the one number a rep can move by working this screen — and
 * the four tiles beside it are the reasons the queue is ordered the way it is.
 */
function WorkPulse({ summary }: { summary: WorkSummary }) {
  const { t } = useTranslation()
  const reached = Math.max(0, summary.open - summary.uncontacted)
  const percent = summary.open > 0 ? Math.round((reached / summary.open) * 100) : 100

  const tiles: Array<{ key: string; label: string; value: number; icon: LucideIcon; alarming?: boolean }> = [
    { key: 'uncontacted', label: t('work.stats.uncontacted'), value: summary.uncontacted, icon: UserX },
    { key: 'overdue-first', label: t('work.stats.overdueFirstContact'), value: summary.overdue_first_contact, icon: TimerOff, alarming: true },
    { key: 'overdue-follow', label: t('work.stats.overdueFollowUps'), value: summary.overdue_follow_ups, icon: BellRing, alarming: true },
    { key: 'hot', label: t('work.stats.hot'), value: summary.hot, icon: Flame },
  ]

  if ((summary.automated ?? 0) > 0) {
    tiles.push({ key: 'automated', label: t('work.pulse.automated'), value: summary.automated ?? 0, icon: Bot })
  }

  return (
    <div className="grid gap-2 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-2 shadow-[var(--shadow-card)] sm:grid-cols-[auto_1fr]" data-testid="work-pulse">
      <div className="flex items-center gap-2.5 px-1.5">
        <RingProgress value={percent} size={44} thickness={5} tone={percent >= 80 ? 'good' : 'warn'} label={t('work.pulse.reached')}>
          <span className="text-[0.8125rem] font-bold tabular-nums" dir="ltr">{percent}%</span>
        </RingProgress>
        <div className="leading-tight">
          <p className="text-[0.9375rem] font-semibold">{t('work.pulse.reached')}</p>
          <p className="t-caption tabular-nums">{t('work.pulse.ofOpen', { reached, open: summary.open })}</p>
        </div>
      </div>
      <div className={cn('grid grid-cols-2 gap-1.5', tiles.length > 4 ? 'sm:grid-cols-5' : 'sm:grid-cols-4')}>
        {tiles.map(({ key, label, value, icon: Icon, alarming }) => {
          const hot = alarming && value > 0

          return (
            <div
              key={key}
              className={cn(
                'flex items-center gap-2 rounded-xl px-2.5 py-1.5',
                hot ? 'bg-[color-mix(in_srgb,var(--color-danger)_8%,transparent)]' : 'bg-[var(--color-surface-muted)]',
              )}
              data-testid={`work-stat-${key}`}
            >
              <Icon className={cn('size-4 shrink-0', hot ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-muted)]')} aria-hidden />
              <p className="t-caption min-w-0 flex-1 truncate">{label}</p>
              <p className={cn('text-[1.0625rem] font-bold leading-none tabular-nums', hot && 'text-[var(--color-danger)]')}>{value}</p>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** What the queue behind the card is made of, by urgency — one bar, three counts. */
function QueueMix({ queue }: { queue: QueuedLead[] }) {
  const { t } = useTranslation()
  if (queue.length === 0) return null

  const groups = (['critical', 'high', 'medium'] as const).map((urgency) => ({
    urgency,
    count: queue.filter((item) => (urgency === 'medium' ? !['critical', 'high'].includes(item.urgency ?? 'none') : item.urgency === urgency)).length,
  }))

  return (
    <div className="px-4 pb-1" data-testid="queue-mix">
      <div className="flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" dir="ltr">
        {groups.filter((g) => g.count > 0).map((g) => (
          <span key={g.urgency} className="h-full" data-chart-mark style={{ width: `${(g.count / queue.length) * 100}%`, background: URGENCY_DOT[g.urgency] }} />
        ))}
      </div>
      <ul className="mt-1 flex flex-wrap gap-x-2.5 text-[0.8125rem] text-[var(--color-ink-muted)]">
        {groups.map((g) => (
          <li key={g.urgency} className="flex items-center gap-1">
            <span aria-hidden className="size-1.5 rounded-full" style={{ background: URGENCY_DOT[g.urgency] }} />
            {t(`work.mix.${g.urgency}`)}
            <span className="font-semibold tabular-nums text-[var(--color-ink)]">{g.count}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
