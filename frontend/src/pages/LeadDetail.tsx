import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, ArrowRight, CalendarPlus, Check, ClipboardCheck, Flame, MessageCircle,
  Phone, StickyNote, Trash2, UserCheck,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  addLeadNote, assignLead, changeLeadStatus, changeLeadTemperature, deleteLead,
  fetchAssignableUsers, fetchLead, fetchLostReasons, logContact,
} from '@/features/leads'
import { apiPost } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatRelative, whatsappLink } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Dropdown, DropdownItem, ErrorState,
  Input, Modal, Select, Skeleton, StatusBadge, TemperatureBadge, Textarea,
} from '@/components/ui'
import type { Lead, LeadStatus, LeadTemperature } from '@/types/api'
import { LeadFiles } from '@/components/leads/LeadFiles'
import { LeadTimeline } from '@/components/leads/LeadTimeline'
import { AutomationTrace } from '@/components/leads/AutomationTrace'
import { ScoreRing } from '@/components/leads/ScoreRing'
import { ChartCard, LeadJourney, buildJourney, humaniseDuration, platformColor } from '@/components/charts'
import { QualificationCard, QualificationDrawer } from '@/components/leads/QualificationCard'
import { Conversation } from '@/components/whatsapp/Conversation'
import { cn } from '@/lib/cn'

const STATUSES: LeadStatus[] = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost']

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
  const [wonOpen, setWonOpen] = useState(false)
  const [dealValue, setDealValue] = useState('')
  const [lostReasonId, setLostReasonId] = useState('')
  const [lostNote, setLostNote] = useState('')
  const [followUpOpen, setFollowUpOpen] = useState(false)
  const [followUpAt, setFollowUpAt] = useState('')
  const [followUpNote, setFollowUpNote] = useState('')
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [qualifyOpen, setQualifyOpen] = useState(false)

  const { data, isLoading, isError, error, refetch } = useQuery({
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

  if (isError) {
    return (
      <Card>
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
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

  const { lead, activities, notes, follow_ups: followUps } = data

  const waHref = whatsappLink(lead.whatsapp, branding ? undefined : null, {
    name: lead.full_name,
    agent: user?.name ?? '',
    project: lead.project?.name ?? '',
    company: branding?.company_name ?? '',
  })

  return (
    <>
      <Link
        to="/leads"
        className="mb-4 inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
      >
        <Back className="size-4" aria-hidden />
        {t('leads.title')}
      </Link>

      {/* Header: identity + the actions a rep reaches for first. */}
      <Card className="mb-4">
        <CardBody className="flex flex-wrap items-start justify-between gap-4">
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
                <TemperatureBadge temperature={lead.temperature} testId="lead-temperature-badge" />
                {lead.re_engaged_count > 0 && (
                  <Badge tone="info">{t('leads.reEngagedCount', { count: lead.re_engaged_count })}</Badge>
                )}
                {lead.is_overdue && <Badge tone="danger">{t('leads.overdue')}</Badge>}
              </div>

              {/*
                The five facts that decide what happens next, on one line:
                who to call, who owns them, which project, where they came
                from, and when they were last touched.
              */}
              <dl className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                {lead.phone && (
                  <div className="flex items-center gap-1.5">
                    <dt className="sr-only">{t('leads.phone')}</dt>
                    <dd className="tabular text-[0.875rem] text-[var(--color-ink-muted)]" dir="ltr">
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
                {lead.last_contact_at && (
                  <Fact
                    label={t('leads.lastContact')}
                    value={formatRelative(lead.last_contact_at, i18n.language)}
                  />
                )}
              </dl>
            </div>
          </div>

          {/*
            Every action a rep performs on a lead lives here, above the fold:
            call, WhatsApp, note, follow-up, temperature and assignment. Nothing
            that is done dozens of times a day should require scrolling.
          */}
          <div className="flex flex-wrap gap-2" data-testid="lead-quick-actions">
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

            <Button
              variant="outline"
              icon={<ClipboardCheck className="size-4" />}
              onClick={() => setQualifyOpen(true)}
              data-testid="action-qualify"
            >
              {t('qualification.action')}
            </Button>

            <Button
              variant="outline"
              icon={<StickyNote className="size-4" />}
              onClick={() => setNoteOpen(true)}
              data-testid="action-note"
            >
              {t('leads.addNote')}
            </Button>

            <Button
              variant="outline"
              icon={<CalendarPlus className="size-4" />}
              onClick={() => setFollowUpOpen(true)}
              data-testid="action-follow-up"
            >
              {t('leads.setFollowUp')}
            </Button>

            {/* Temperature: three options, so a dropdown beats a separate page. */}
            <Dropdown
              trigger={({ toggle }) => (
                <Button
                  variant="outline"
                  icon={<Flame className="size-4" />}
                  onClick={toggle}
                  loading={temperatureMutation.isPending}
                  data-testid="action-temperature"
                >
                  {t('leads.temperature')}
                </Button>
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
                    variant="outline"
                    icon={<UserCheck className="size-4" />}
                    onClick={toggle}
                    loading={assignMutation.isPending}
                    data-testid="action-assign"
                  >
                    {t('leads.assign')}
                  </Button>
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
              <Button variant="ghost" size="icon" aria-label={t('common.delete')} onClick={() => setDeleteOpen(true)}>
                <Trash2 className="size-4" />
              </Button>
            )}
          </div>
        </CardBody>

        {/* Stage switcher — one tap, no dropdown hunting. */}
        <div className="scroll-x border-t border-[var(--color-border)] px-5 py-3">
          <div className="flex items-center gap-1.5" data-testid="stage-switcher">
            <span className="me-1 shrink-0 text-[0.75rem] font-medium text-[var(--color-ink-subtle)]">
              {t('leads.changeStatus')}
            </span>
            {STATUSES.map((status) => (
              <button
                key={status}
                type="button"
                disabled={statusMutation.isPending}
                onClick={() =>
                  status === 'lost'
                    ? setLostOpen(true)
                    : status === 'won'
                      ? setWonOpen(true)
                      : statusMutation.mutate(status)
                }
                className={cn(
                  'rounded-[var(--radius-control)] border px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                  lead.status === status
                    ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand)]'
                    : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
                )}
              >
                {t(`status.${status}`)}
              </button>
            ))}
          </div>
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        {/*
          Ordered by what a rep does, not by what the database holds: see
          where it stands, qualify, talk, note. The journey leads because it is
          orientation rather than history — it is the only thing on the page
          that says how long this lead has been waiting right now. Attribution and the contact
          details sit in the sidebar — read occasionally, acted on rarely.
        */}
        <div className="space-y-4 lg:col-span-2">
          <JourneyCard lead={lead} />

          <QualificationCard lead={lead} />

          <Conversation lead={lead} />

          {/*
            Under the conversation, because sending a brochure is the same kind
            of act as sending a message, and the answer to "did they open it"
            belongs next to "did they reply".
          */}
          <LeadFiles lead={lead} />

          <Card>
            <CardHeader
              title={t('leads.notes')}
              action={
                <Button
                  size="sm"
                  variant="outline"
                  icon={<StickyNote className="size-4" />}
                  onClick={() => setNoteOpen(true)}
                >
                  {t('leads.addNote')}
                </Button>
              }
            />
            {notes.length === 0 ? (
              <p className="px-5 py-8 text-center text-[0.8125rem] text-[var(--color-ink-muted)]">
                {t('leads.noNotes')}
              </p>
            ) : (
              <CardBody>
                <ul className="space-y-2.5" data-testid="notes-list">
                  {notes.map((entry) => (
                    <li key={entry.id} className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-3">
                      <p className="text-[0.875rem] leading-relaxed whitespace-pre-wrap">{entry.body}</p>
                      <p className="mt-1.5 text-[0.75rem] text-[var(--color-ink-subtle)]">
                        {entry.user?.name} · {formatRelative(entry.created_at, i18n.language)}
                      </p>
                    </li>
                  ))}
                </ul>
              </CardBody>
            )}
          </Card>

          <Card>
            <CardHeader title={t('leads.timeline_')} />
            <LeadTimeline activities={activities} />
          </Card>

          {/*
            Below the human timeline, not inside it. What a rep did and what a
            rule did are different kinds of fact, and interleaving them makes
            the automation look like somebody's work.
          */}
          <Card>
            <CardHeader
              title={t('automation.trace.title')}
              description={t('automation.trace.description')}
            />
            <AutomationTrace leadId={lead.id} />
          </Card>
        </div>

        <div className="space-y-4">
          <DetailCard title={t('leads.contactInfo')}>
            <Row label={t('leads.phone')} value={lead.phone} ltr />
            <Row label={t('leads.email')} value={lead.email} ltr />
            <Row label={t('leads.city')} value={lead.city} />
          </DetailCard>

          {/* Interest used to be repeated here; the qualification card above
              shows the same answers, editable, so this was two places to read
              and one place to change. */}
          <DetailCard title={t('leads.interest')}>
            <Row label={t('leads.project')} value={lead.project?.name} />
            <Row label={t('leads.firstContact')} value={formatDateTime(lead.first_contact_at, i18n.language)} />
            <Row label={t('leads.firstResponse')} value={formatDateTime(lead.first_response_at, i18n.language)} />
          </DetailCard>

          <DetailCard title={t('leads.attribution')}>
            <Row
              label={t('leads.platform')}
              value={lead.platform ? t(`platforms.${lead.platform}`, { defaultValue: lead.platform }) : null}
            />
            <Row label={t('leads.campaign')} value={lead.campaign_name} />
            <Row label={t('leads.adset')} value={lead.adset_name} />
            <Row label={t('leads.ad')} value={lead.ad_name} />
            <Row label={t('leads.form')} value={lead.form_name} />
          </DetailCard>

          <DetailCard title={t('followUps.title')}>
            <Row label={t('leads.lastContact')} value={formatDateTime(lead.last_contact_at, i18n.language)} />
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
                    <li key={followUp.id} className="text-[0.8125rem]">
                      <span className={followUp.is_overdue ? 'text-[var(--color-status-lost)]' : undefined}>
                        {formatDateTime(followUp.due_at, i18n.language)}
                      </span>
                      {followUp.note && (
                        <p className="text-[0.75rem] text-[var(--color-ink-muted)]">{followUp.note}</p>
                      )}
                    </li>
                  ))}
              </ul>
            )}
          </DetailCard>

          {lead.status === 'lost' && lead.lost_reason && (
            <DetailCard title={t('leads.lostReason')}>
              <Row label={t('leads.lostReason')} value={lead.lost_reason.label} />
              <Row label={t('leads.lostNote')} value={lead.lost_note} />
            </DetailCard>
          )}
        </div>
      </div>

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
        <p className="text-[0.875rem]">{lead.full_name}</p>
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
    <div className="flex items-baseline justify-between gap-3 text-[0.8125rem]">
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
      <dd className="t-caption truncate text-[0.8125rem]">{value}</dd>
    </div>
  )
}
