import { useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, ArrowRight, CalendarCheck, CalendarPlus, Check, ClipboardCheck, FileText, Flame, Mail,
  MessageCircle, Pencil, Phone, StickyNote, ThumbsDown, ThumbsUp, Trash2, UserCheck,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  addLeadNote, assignLead, changeLeadStatus, changeLeadTemperature, deleteLead,
  fetchAssignableUsers, fetchLead, fetchLostReasons, logContact,
} from '@/features/leads'
import { ApiError, apiPost } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatRelative, whatsappLink } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Dropdown, DropdownItem, ErrorState,
  Input, Modal, Select, Skeleton, StatusBadge, Tabs, TemperatureBadge, Textarea,
} from '@/components/ui'
import type { Lead, LeadStatus, LeadTemperature } from '@/types/api'
import { ConversationCard } from '@/components/lead/ConversationCard'
import { DuplicatesBanner } from '@/components/lead/DuplicatesBanner'
import { FollowUpPlanCard } from '@/components/lead/FollowUpPlanCard'
import { InterestCard } from '@/components/lead/InterestCard'
import { HandoffBriefCard } from '@/components/lead/HandoffBriefCard'
import { IntelligenceStrip } from '@/components/lead/IntelligenceStrip'
import { JourneyTimeline } from '@/components/lead/JourneyTimeline'
import { LeadFiles } from '@/components/leads/LeadFiles'
import { AutomationTrace } from '@/components/leads/AutomationTrace'
import { ScoreRing } from '@/components/leads/ScoreRing'
import { ChartCard, LeadJourney, buildJourney, humaniseDuration, platformColor } from '@/components/charts'
import { QualificationCard, QualificationDrawer } from '@/components/leads/QualificationCard'
import { ProjectAnswers } from '@/components/leads/ProjectAnswers'
import { LeadMessaging, rememberedChannel } from '@/components/email/LeadMessaging'
import { cn } from '@/lib/cn'
import { CLASSIFICATION_COLOR } from '@/components/visual/classification'
import { StageTrack } from '@/components/lead/StageTrack'
import { NextActionBanner } from '@/components/lead/NextActionBanner'
import { AppointmentDialog } from '@/components/lead/AppointmentDialog'
import { AppointmentsPanel, CallsPanel, SourceChain } from '@/components/lead/SidePanels'
import { AutomationStatus, Engagement, WhyThisLead } from '@/components/lead/WorkspaceSide'
import { LeadFormDrawer } from '@/components/leads/LeadFormDrawer'

export function LeadDetailPage() {
  const { id = '' } = useParams()
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const workspaceLanguage = useAuth((s) => s.workspaceLanguage)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [note, setNote] = useState('')
  const [noteOpen, setNoteOpen] = useState(false)
  const [lostOpen, setLostOpen] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [wonOpen, setWonOpen] = useState(false)
  const [dealValue, setDealValue] = useState('')
  const [lostReasonId, setLostReasonId] = useState('')
  const [lostNote, setLostNote] = useState('')
  const [followUpOpen, setFollowUpOpen] = useState(false)
  const [followUpAt, setFollowUpAt] = useState('')
  const [followUpNote, setFollowUpNote] = useState('')
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [qualifyOpen, setQualifyOpen] = useState(false)
  const [appointmentOpen, setAppointmentOpen] = useState(false)
  const [channel, setChannel] = useState<'whatsapp' | 'email'>(rememberedChannel)
  const [history, setHistory] = useState<'timeline' | 'automation'>('timeline')
  const messagingRef = useRef<HTMLDivElement>(null)

  const { data, dataUpdatedAt, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['lead', id],
    queryFn: () => fetchLead(id),
    enabled: Boolean(id),
  })

  const { data: users = [] } = useQuery({
    queryKey: ['assignable-users'],
    queryFn: fetchAssignableUsers,
    enabled: can.assignLeads(user),
  })

  const { data: lostReasons = [] } = useQuery({
    queryKey: ['lost-reasons'],
    queryFn: fetchLostReasons,
    enabled: lostOpen,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['lead', id] })
    void queryClient.invalidateQueries({ queryKey: ['leads'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    void queryClient.invalidateQueries({ queryKey: ['follow-up-counts'] })
  }

  function onError(caught: unknown) {
    toast(translateError(caught), 'error')
  }

  const statusMutation = useMutation({
    mutationFn: (next: LeadStatus) => {
      if (next === 'lost') {
        return changeLeadStatus(id, next, { lost_reason_id: lostReasonId, lost_note: lostNote || undefined })
      }

      // Blank means nobody knows the figure yet, which is not the same as zero
      // and must not be sent as one.
      if (next === 'won' && dealValue.trim() !== '') {
        return changeLeadStatus(id, next, { deal_value: Number(dealValue) })
      }

      return changeLeadStatus(id, next)
    },
    onSuccess: () => {
      refresh()
      setLostOpen(false)
      setLostReasonId('')
      setLostNote('')
      setWonOpen(false)
      setDealValue('')
    },
    onError,
  })

  const temperatureMutation = useMutation({
    mutationFn: (next: LeadTemperature) => changeLeadTemperature(id, next),
    onSuccess: refresh,
    onError,
  })

  const assignMutation = useMutation({
    mutationFn: (assignee: string) => assignLead(id, assignee || null),
    onSuccess: refresh,
    onError,
  })

  const noteMutation = useMutation({
    mutationFn: () => addLeadNote(id, note),
    onSuccess: () => {
      setNote('')
      setNoteOpen(false)
      refresh()
    },
    onError,
  })

  const contactMutation = useMutation({
    mutationFn: (channel: 'call' | 'whatsapp') => logContact(id, channel),
    onSuccess: (result, channel) => {
      refresh()

      if (channel !== 'call' || !result.call) return

      if (result.call.mode === 'bridge') {
        // The workspace's telephony is ringing the rep's own phone. Dialling
        // as well would be a second call to the same customer.
        toast(t('calls.ringingYou'), 'success')

        return
      }

      /*
       * The rep dials. This is also where a provider that could not place the
       * call lands, with the reason, so the button never appears dead.
       */
      if (result.call.fallback_reason) {
        toast(
          t(`calls.fallback.${result.call.fallback_reason}`, { defaultValue: t('calls.fallback.default') }),
          'info',
        )
      }

      if (lead?.phone) window.location.href = `tel:${lead.phone}`
    },
  })

  const followUpMutation = useMutation({
    mutationFn: () =>
      apiPost('/follow-ups', {
        lead_id: id,
        due_at: new Date(followUpAt).toISOString(),
        note: followUpNote || undefined,
      }),
    onSuccess: () => {
      setFollowUpOpen(false)
      setFollowUpAt('')
      setFollowUpNote('')
      toast(t('followUps.created'), 'success')
      refresh()
    },
    onError,
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteLead(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      navigate('/leads', { replace: true })
    },
    onError,
  })

  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  function scrollToId(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  function openChannel(next: 'whatsapp' | 'email') {
    setChannel(next)
    messagingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  if (isError) {
    return (
      <Card>
        <ErrorState message={translateError(error)} onRetry={error instanceof ApiError && error.status === 404 ? undefined : () => void refetch()} back={{ to: '/leads', label: t('nav.leads') }} />
      </Card>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="space-y-4" aria-busy="true">
        <Skeleton className="h-9 w-32" />
        <Skeleton className="h-28 w-full" />
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-64 lg:col-span-2" />
          <Skeleton className="h-64" />
        </div>
      </div>
    )
  }

  const { lead, notes, follow_ups: followUps } = data

  const waHref = whatsappLink(lead.whatsapp, branding ? undefined : null, {
    name: lead.full_name,
    agent: user?.name ?? '',
    project: lead.project?.name ?? '',
    company: branding?.company_name ?? '',
  })

  // The banner's button does what its label says, using the same handlers as
  // the dock — so advice and action cannot drift apart.
  function actOnNext(action: string) {
    switch (action) {
      case 'call_now':
      case 'follow_up':
        if (lead.phone) contactMutation.mutate('call')
        break
      case 'reply_now':
        openChannel('whatsapp')
        break
      case 'book_meeting':
        setAppointmentOpen(true)
        break
      case 'send_whatsapp':
      case 'nurture':
        openChannel('whatsapp')
        break
      case 'send_email':
        openChannel('email')
        break
      case 'send_brochure':
        document.querySelector<HTMLElement>('[data-testid="lead-files"], [data-testid="lead-files-empty"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        break
      case 'close_lost':
        setLostOpen(true)
        break
      case 'schedule_follow_up':
        setFollowUpOpen(true)
        break
      case 'review_fit':
        setQualifyOpen(true)
        break
      case 'assign':
        document.querySelector<HTMLElement>('[data-testid="action-assign"]')?.click()
        break
    }
  }

  return (
    <>
      <Link
        to="/leads"
        className="mb-4 inline-flex items-center gap-1.5 text-[0.9375rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
      >
        <Back className="size-4" aria-hidden />
        {t('leads.title')}
      </Link>

      {/* Header: who, how serious, where they are, and the actions a rep reaches for first. */}
      <Card className="mb-2">
        <CardBody className="space-y-4">
          <div className="flex min-w-0 items-start gap-4">
            {/*
              The score leads, because the first question a rep has is whether
              this person is worth the next twenty minutes. Clicking it opens
              the breakdown rather than making them hunt for the card below.
            */}
            <ScoreRing
              score={lead.score}
              temperature={lead.temperature}
              onClick={() => setQualifyOpen(true)}
              testId="lead-score-ring"
            />

            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="t-page-title">{lead.full_name}</h1>
                <StatusBadge status={lead.status} testId="lead-status-badge" />
                <TemperatureBadge temperature={lead.temperature} priority={lead.is_priority} testId="lead-temperature-badge" />
                {lead.re_engaged_count > 0 && (
                  <Badge tone="info">{t('leads.reEngagedCount', { count: lead.re_engaged_count })}</Badge>
                )}
                {lead.is_overdue && <Badge tone="danger">{t('leads.overdue')}</Badge>}
              </div>

              {/* Why the system calls this lead what it does, in a few words. */}
              {lead.classification && (
                <p className="mt-1 flex items-center gap-1.5 text-[0.875rem] text-[var(--color-ink-muted)]" data-testid="lead-classification">
                  <span className="size-2 rounded-full" style={{ background: CLASSIFICATION_COLOR[lead.classification.key] }} aria-hidden />
                  <span className="font-medium text-[var(--color-ink)]">{t(`classification.${lead.classification.key}`)}</span>
                  <span>· {t(`classification.reason.${lead.classification.reason}`)}</span>
                </p>
              )}

              {/*
                The facts that decide what happens next, on one line: who to
                call, who owns them, which project, where they came from, and
                when they were last touched.
              */}
              <dl className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                {lead.phone && (
                  <div className="flex items-center gap-1.5">
                    <dt className="sr-only">{t('leads.phone')}</dt>
                    <dd className="tabular text-[1rem] text-[var(--color-ink-muted)]" dir="ltr">
                      {lead.phone}
                    </dd>
                  </div>
                )}

                {/*
                  Which language to open the call in — read off the number's
                  country code, and shown only when it is not the language this
                  workspace works in. Labelling every Riyadh lead "Arabic" would
                  teach the eye to skip the line, and the +92 number is the one
                  that needs the warning.
                */}
                {lead.language && lead.language !== workspaceLanguage && (
                  <Fact
                    label={t('leads.language')}
                    value={t(`languages.${lead.language}`, { defaultValue: lead.language })}
                  />
                )}

                <Fact label={t('leads.assignedTo')} value={lead.assignee?.name ?? t('common.unassigned')} />
                {lead.project?.name && <Fact label={t('leads.project')} value={lead.project.name} />}
                {lead.platform && (
                  <Fact
                    label={t('leads.platform')}
                    value={t(`platforms.${lead.platform}`, { defaultValue: lead.platform })}
                    dot={platformColor(lead.platform)}
                  />
                )}
                {lead.campaign_name && <Fact label={t('workspace.why.campaign')} value={lead.campaign_name} />}
                {lead.last_contact_at && (
                  <Fact
                    label={t('leads.lastContact')}
                    value={formatRelative(lead.last_contact_at, i18n.language)}
                  />
                )}
              </dl>
            </div>
          </div>
        </CardBody>

        {/* The path, and the control that moves the lead along it. */}
        <div className="border-t border-[var(--color-line)] px-4 py-3 sm:px-6">
          <StageTrack
            status={lead.status}
            disabled={statusMutation.isPending}
            onPick={(status) =>
              status === 'lost' ? setLostOpen(true) : status === 'won' ? setWonOpen(true) : statusMutation.mutate(status)
            }
          />
        </div>
      </Card>

      {/*
        The acts a rep reaches for, kept in view: this bar sticks under the header while the page
        scrolls, so the thread below can be as long as it likes and the next press is never off screen.
      */}
      <div
        className="sticky top-[5.25rem] z-20 mb-4 rounded-[var(--radius-card)] bg-[var(--color-surface)] px-3 py-2 shadow-[var(--shadow-card)]"
        data-testid="lead-actions-bar"
      >
        {/*
          Every act a rep performs on a lead, one press away and without
          leaving it: the six on the left are the conversation (call, WhatsApp,
          email, a file, a visit, a follow-up); the round icons are the rarer
          ones. Nothing done dozens of times a day needs scrolling to.
        */}
        <div className="flex flex-wrap items-center gap-2" data-testid="lead-quick-actions">
          {lead.phone && (
            <a
              href={`tel:${lead.phone}`}
              onClick={(event) => {
                /*
                 * Held until the product has answered. Whether this call is
                 * placed by telephony or dialled by the rep is decided on the
                 * server, and following the link first would dial in both
                 * cases. The link stays a real `tel:` anchor so a long-press
                 * still copies the number.
                 */
                event.preventDefault()
                contactMutation.mutate('call')
              }}
            >
              <Button variant="outline" icon={<Phone className="size-4" />} data-testid="action-call">
                {t('leads.call')}
              </Button>
            </a>
          )}

          {waHref && (
            <a href={waHref} target="_blank" rel="noreferrer" onClick={() => contactMutation.mutate('whatsapp')}>
              <Button
                variant="outline"
                icon={<MessageCircle className="size-4" />}
                className="border-[color-mix(in_srgb,var(--color-status-won)_35%,transparent)] text-[var(--color-status-won)] hover:bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)]"
                data-testid="action-whatsapp"
              >
                {t('leads.whatsapp')}
              </Button>
            </a>
          )}

          <Button variant="outline" icon={<Mail className="size-4" />} onClick={() => openChannel('email')} data-testid="action-email">
            {t('leadView.email')}
          </Button>

          <Button variant="outline" icon={<FileText className="size-4" />} onClick={() => scrollToId('lead-files')} data-testid="action-file">
            {t('leadView.file')}
          </Button>

          <Button variant="outline" icon={<CalendarCheck className="size-4" />} onClick={() => setAppointmentOpen(true)} data-testid="action-appointment">
            {t('leadView.appointment')}
          </Button>

          <Button
            variant="outline"
            icon={<CalendarPlus className="size-4" />}
            onClick={() => setFollowUpOpen(true)}
            data-testid="action-follow-up"
          >
            {t('leads.setFollowUp')}
          </Button>

          <Button variant="outline" icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)} data-testid="action-edit">
            {t('leadView.edit')}
          </Button>

          {/*
            Accept is the rep saying "this one is real and worth working":
            it qualifies the lead, which makes it a priority and tells the ad
            platform this campaign brought a real buyer. Reject asks why, the
            same way a loss does, so the campaign report learns from it.
          */}
          {!['qualified', 'appointment', 'negotiation', 'won', 'lost'].includes(lead.status) && (
            <Button
              variant="outline"
              icon={<ThumbsUp className="size-4" />}
              className="text-[var(--color-status-won)]"
              loading={statusMutation.isPending}
              onClick={() => statusMutation.mutate('qualified')}
              data-testid="action-accept"
            >
              {t('leadView.accept')}
            </Button>
          )}
          {!['won', 'lost'].includes(lead.status) && (
            <Button
              variant="outline"
              icon={<ThumbsDown className="size-4" />}
              className="text-[var(--color-status-lost)]"
              onClick={() => setLostOpen(true)}
              data-testid="action-reject"
            >
              {t('leadView.reject')}
            </Button>
          )}

          <span className="mx-0.5 hidden h-6 w-px bg-[var(--color-chrome-fill)] sm:block" aria-hidden />

          <Button
            variant="ghost"
            size="icon"
            aria-label={t('qualification.action')}
            title={t('qualification.action')}
            onClick={() => setQualifyOpen(true)}
            data-testid="action-qualify"
            icon={<ClipboardCheck className="size-4" />}
          />

          <Button
            variant="ghost"
            size="icon"
            aria-label={t('leads.addNote')}
            title={t('leads.addNote')}
            onClick={() => setNoteOpen(true)}
            data-testid="action-note"
            icon={<StickyNote className="size-4" />}
          />

          {/* Temperature: three options, so a dropdown beats a separate page. */}
          <Dropdown
            trigger={({ toggle }) => (
              <Button
                variant="ghost"
                size="icon"
                aria-label={t('leads.temperature')}
                title={t('leads.temperature')}
                onClick={toggle}
                loading={temperatureMutation.isPending}
                data-testid="action-temperature"
                icon={<Flame className="size-4" />}
              />
            )}
          >
            {({ close }) => (
              <>
                {(['hot', 'warm', 'cold'] as const).map((temp) => (
                  <DropdownItem
                    key={temp}
                    icon={
                      lead.temperature === temp ? (
                        <Check className="size-4" aria-hidden />
                      ) : (
                        <span className="size-4" aria-hidden />
                      )
                    }
                    onClick={() => {
                      temperatureMutation.mutate(temp)
                      close()
                    }}
                  >
                    {t(`temperature.${temp}`)}
                  </DropdownItem>
                ))}
              </>
            )}
          </Dropdown>

          {can.assignLeads(user) && (
            <Dropdown
              trigger={({ toggle }) => (
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t('leads.assign')}
                  title={t('leads.assign')}
                  onClick={toggle}
                  loading={assignMutation.isPending}
                  data-testid="action-assign"
                  icon={<UserCheck className="size-4" />}
                />
              )}
            >
              {({ close }) => (
                <div className="max-h-64 overflow-y-auto">
                  <DropdownItem
                    icon={
                      lead.assignee ? (
                        <span className="size-4" aria-hidden />
                      ) : (
                        <Check className="size-4" aria-hidden />
                      )
                    }
                    onClick={() => {
                      assignMutation.mutate('')
                      close()
                    }}
                  >
                    {t('common.unassigned')}
                  </DropdownItem>
                  {users.map((candidate) => (
                    <DropdownItem
                      key={candidate.id}
                      icon={
                        lead.assignee?.id === candidate.id ? (
                          <Check className="size-4" aria-hidden />
                        ) : (
                          <span className="size-4" aria-hidden />
                        )
                      }
                      onClick={() => {
                        assignMutation.mutate(candidate.id)
                        close()
                      }}
                    >
                      {candidate.name}
                    </DropdownItem>
                  ))}
                </div>
              )}
            </Dropdown>
          )}

          {can.manageTenant(user) && (
            <Button variant="ghost" size="icon" aria-label={t('common.delete')} title={t('common.delete')} onClick={() => setDeleteOpen(true)} icon={<Trash2 className="size-4" />} />
          )}
        </div>
      </div>

      {/*
        One screen for one customer. The wide column is the conversation and
        everything that happened — the thread a rep continues, the calls, the
        files, the visits, the notes. The narrow column is orientation: what to
        do next and why this lead, who is holding it (the journey or a person),
        how engaged they are, and what they told us.
      */}
      {can.seeAllLeads(user) && <DuplicatesBanner leadId={lead.id} onMerged={() => void queryClient.invalidateQueries({ queryKey: ['lead', id] })} />}

      {data.handoff && <div className="mb-4"><HandoffBriefCard brief={data.handoff} onAct={actOnNext} /></div>}

      <IntelligenceStrip intelligence={data.intelligence} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <div ref={messagingRef}>
            <LeadMessaging lead={lead} channel={channel} onChannel={setChannel} />
          </div>

          <Card>
            <div className="px-4 pt-4">
              <Tabs
                testId="history-tabs"
                value={history}
                onChange={setHistory}
                items={[
                  { key: 'timeline', label: t('leadView.timeline') },
                  { key: 'automation', label: t('leadView.automation') },
                ]}
              />
            </div>
            {history === 'timeline' ? (
              <JourneyTimeline leadId={lead.id} version={dataUpdatedAt} />
            ) : (
              <AutomationTrace leadId={lead.id} />
            )}
          </Card>

          <div className="grid gap-4 md:grid-cols-2">
            <CallsPanel calls={data.calls} />
            <AppointmentsPanel appointments={data.appointments} />
          </div>

          <div id="lead-files">
            <LeadFiles lead={lead} />
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader
                title={t('leads.notes')}
                action={
                  <Button size="sm" variant="ghost" icon={<StickyNote className="size-4" />} onClick={() => setNoteOpen(true)}>
                    {t('leads.addNote')}
                  </Button>
                }
              />
              {notes.length === 0 ? (
                <p className="px-5 py-6 text-center text-[0.9375rem] text-[var(--color-ink-muted)]">
                  {t('leads.noNotes')}
                </p>
              ) : (
                <CardBody>
                  <ul className="space-y-2.5" data-testid="notes-list">
                    {notes.map((entry) => (
                      <li key={entry.id} className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-3">
                        <p className="text-[1rem] leading-relaxed whitespace-pre-wrap">{entry.body}</p>
                        <p className="mt-1.5 text-[0.875rem] text-[var(--color-ink-subtle)]">
                          {entry.user?.name} · {formatRelative(entry.created_at, i18n.language)}
                        </p>
                      </li>
                    ))}
                  </ul>
                </CardBody>
              )}
            </Card>

            <DetailCard title={t('followUps.title')}>
              <Row
                label={t('leads.nextFollowUp')}
                value={lead.next_follow_up_at ? formatDateTime(lead.next_follow_up_at, i18n.language) : null}
                danger={lead.is_overdue}
              />
              {followUps.filter((f) => f.status === 'pending').length > 0 && (
                <ul className="mt-2 space-y-1.5 border-t border-[var(--color-border)] pt-2">
                  {followUps
                    .filter((f) => f.status === 'pending')
                    .map((followUp) => (
                      <li key={followUp.id} className="text-[0.9375rem]">
                        <span className={followUp.is_overdue ? 'text-[var(--color-status-lost)]' : undefined}>
                          {formatDateTime(followUp.due_at, i18n.language)}
                        </span>
                        {followUp.note && (
                          <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{followUp.note}</p>
                        )}
                      </li>
                    ))}
                </ul>
              )}
            </DetailCard>

          </div>
        </div>

        <div className="space-y-4">
          <NextActionBanner action={data.next_action} onAct={actOnNext} />
          <InterestCard lead={lead} />
          {data.follow_up && <FollowUpPlanCard leadId={lead.id} plan={data.follow_up} onChanged={() => void queryClient.invalidateQueries({ queryKey: ['lead', id] })} />}
          <WhyThisLead lead={lead} signals={data.signals ?? []} />
          <ConversationCard leadId={lead.id} conversation={data.conversation} />
          <AutomationStatus lead={lead} onShowSteps={() => { setHistory('automation'); document.querySelector('[data-testid="history-tabs"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }) }} />
          <Engagement lead={lead} />
          <QualificationCard lead={lead} gaps={data.qualification_gaps} />
          <ProjectAnswers lead={lead} />
          <JourneyCard lead={lead} />
          <SourceChain lead={lead} />

          <DetailCard title={t('leads.contactInfo')}>
            <Row label={t('leads.phone')} value={lead.phone} ltr />
            <Row label={t('leads.email')} value={lead.email} ltr />
            <Row label={t('leads.city')} value={lead.city} />
          </DetailCard>

          {lead.status === 'lost' && lead.lost_reason && (
            <DetailCard title={t('leads.lostReason')}>
              <Row label={t('leads.lostReason')} value={lead.lost_reason.label} />
              <Row label={t('leads.lostNote')} value={lead.lost_note} />
            </DetailCard>
          )}
        </div>
      </div>

      <LeadFormDrawer key={lead.id + String(editOpen)} open={editOpen} onClose={() => setEditOpen(false)} lead={lead} />

      <AppointmentDialog
        leadId={lead.id}
        open={appointmentOpen}
        onClose={() => setAppointmentOpen(false)}
        onBooked={refresh}
      />

      {/* Reachable from the quick actions as well as the card, because a rep on
          a call should not have to find the card first. */}
      <QualificationDrawer lead={lead} open={qualifyOpen} onClose={() => setQualifyOpen(false)} />

      {/*
        A win asks what it closed for. Optional, and the button says so: a sale
        recorded without its value is still a sale, and blocking the stage until
        somebody knows the final figure is how a CRM ends up with a stage people
        route around. Without it there is no return on ad spend to compute, and
        the buyer's stated budget is not a substitute — that is a number they
        typed into a form before anybody showed them anything.
      */}
      <Modal
        open={wonOpen}
        onClose={() => setWonOpen(false)}
        title={t('status.won')}
        description={t('campaignQuality.dealValueHint')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setWonOpen(false)}>{t('common.cancel')}</Button>
            <Button
              loading={statusMutation.isPending}
              onClick={() => statusMutation.mutate('won')}
              data-testid="confirm-won"
            >
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <Input
          label={t('campaignQuality.dealValue')}
          hint={t('common.optional')}
          type="number"
          min="0"
          step="0.01"
          dir="ltr"
          value={dealValue}
          onChange={(event) => setDealValue(event.target.value)}
          data-testid="deal-value"
        />
      </Modal>

      {/* Lost requires a reason — this is what makes the lost-reason report real. */}
      <Modal
        open={lostOpen}
        onClose={() => setLostOpen(false)}
        title={t('status.lost')}
        description={t('leads.lostReasonRequired')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setLostOpen(false)}>{t('common.cancel')}</Button>
            <Button
              variant="danger"
              disabled={!lostReasonId}
              loading={statusMutation.isPending}
              onClick={() => statusMutation.mutate('lost')}
            >
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Select
            label={t('leads.lostReason')}
            value={lostReasonId}
            onChange={(e) => setLostReasonId(e.target.value)}
            required
          >
            <option value="">—</option>
            {lostReasons.map((reason) => (
              <option key={reason.id} value={reason.id}>{reason.label}</option>
            ))}
          </Select>
          <Textarea
            label={t('leads.lostNote')}
            hint={t('common.optional')}
            value={lostNote}
            onChange={(e) => setLostNote(e.target.value)}
          />
        </div>
      </Modal>

      {/* Quick note from the action bar, so a rep never has to scroll to write one. */}
      <Modal
        open={noteOpen}
        onClose={() => setNoteOpen(false)}
        title={t('leads.addNote')}
        description={lead.full_name}
        footer={
          <>
            <Button variant="ghost" onClick={() => setNoteOpen(false)}>{t('common.cancel')}</Button>
            <Button
              disabled={!note.trim()}
              loading={noteMutation.isPending}
              onClick={() => noteMutation.mutate()}
              data-testid="save-note"
            >
              {t('common.save')}
            </Button>
          </>
        }
      >
        <Textarea
          autoFocus
          rows={4}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder={t('leads.notePlaceholder')}
          data-testid="note-input"
        />
      </Modal>

      <Modal
        open={followUpOpen}
        onClose={() => setFollowUpOpen(false)}
        title={t('leads.setFollowUp')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setFollowUpOpen(false)}>{t('common.cancel')}</Button>
            <Button
              disabled={!followUpAt}
              loading={followUpMutation.isPending}
              onClick={() => followUpMutation.mutate()}
            >
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input
            id="due-at"
            type="datetime-local"
            label={t('followUps.due')}
            value={followUpAt}
            onChange={(e) => setFollowUpAt(e.target.value)}
          />
          <Textarea
            label={t('followUps.note')}
            hint={t('common.optional')}
            value={followUpNote}
            onChange={(e) => setFollowUpNote(e.target.value)}
          />
        </div>
      </Modal>

      <Modal
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title={t('common.delete')}
        description={t('leads.deleteConfirm')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleteOpen(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" loading={deleteMutation.isPending} onClick={() => deleteMutation.mutate()}>
              {t('common.delete')}
            </Button>
          </>
        }
      >
        <p className="text-[1rem]">{lead.full_name}</p>
      </Modal>
    </>
  )
}

/**
 * The lead's own milestones, and the gaps between them.
 *
 * In a ChartCard for the table view: the bars encode durations that span
 * minutes to months, and a reader who wants the actual figures should not have
 * to measure a bar to get them.
 */
function JourneyCard({ lead }: { lead: Lead }) {
  const { t, i18n } = useTranslation()
  const journey = buildJourney(lead, new Date())

  return (
    <ChartCard
      title={t('charts.journey.title')}
      description={t('charts.journey.hint')}
      height={0}
      testId="lead-journey-card"
      table={{
        head: [t('charts.journey.step'), t('charts.journey.when'), t('charts.journey.gap')],
        rows: journey.milestones.map((milestone) => [
          t(`charts.journey.${milestone.key}`),
          milestone.at ? formatDateTime(milestone.at, i18n.language) : t('charts.journey.notYet'),
          milestone.sincePrevious === null ? '—' : humaniseDuration(milestone.sincePrevious, t),
        ]),
      }}
    >
      <LeadJourney lead={lead} />
    </ChartCard>
  )
}

function DetailCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} />
      <CardBody className="space-y-2.5">{children}</CardBody>
    </Card>
  )
}

function Row({
  label,
  value,
  ltr,
  danger,
}: {
  label: string
  value?: string | null
  ltr?: boolean
  danger?: boolean
}) {
  return (
    <div className="flex items-baseline justify-between gap-3 text-[0.9375rem]">
      <span className="shrink-0 text-[var(--color-ink-muted)]">{label}</span>
      <span
        dir={ltr ? 'ltr' : undefined}
        className={cn(
          'min-w-0 truncate text-end font-medium',
          ltr && 'tabular',
          danger && 'text-[var(--color-status-lost)]',
          !value && 'font-normal text-[var(--color-ink-subtle)]',
        )}
      >
        {value || '—'}
      </span>
    </div>
  )
}

/** One labelled fact in the lead header, with an optional source dot. */
function Fact({ label, value, dot }: { label: string; value: string; dot?: string }) {
  return (
    <div className="flex min-w-0 items-center gap-1.5">
      {dot && <span className="size-2 shrink-0 rounded-full" style={{ background: dot }} aria-hidden />}
      <dt className="sr-only">{label}</dt>
      <dd className="t-caption truncate text-[0.9375rem]">{value}</dd>
    </div>
  )
}
