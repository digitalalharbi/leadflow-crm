import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, ArrowRight, CalendarPlus, Check, Flame, MessageCircle, Phone,
  StickyNote, Trash2, UserCheck,
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
import { formatBudgetRange, formatDateTime, formatRelative, whatsappLink } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Dropdown, DropdownItem, ErrorState,
  Input, Modal, Select, Skeleton, StatusBadge, TemperatureBadge, Textarea,
} from '@/components/ui'
import type { LeadStatus, LeadTemperature } from '@/types/api'
import { LeadTimeline } from '@/components/leads/LeadTimeline'
import { cn } from '@/lib/cn'

const STATUSES: LeadStatus[] = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost']

export function LeadDetailPage() {
  const { id = '' } = useParams()
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [note, setNote] = useState('')
  const [noteOpen, setNoteOpen] = useState(false)
  const [lostOpen, setLostOpen] = useState(false)
  const [lostReasonId, setLostReasonId] = useState('')
  const [lostNote, setLostNote] = useState('')
  const [followUpOpen, setFollowUpOpen] = useState(false)
  const [followUpAt, setFollowUpAt] = useState('')
  const [followUpNote, setFollowUpNote] = useState('')
  const [deleteOpen, setDeleteOpen] = useState(false)

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
    mutationFn: (next: LeadStatus) =>
      next === 'lost'
        ? changeLeadStatus(id, next, { lost_reason_id: lostReasonId, lost_note: lostNote || undefined })
        : changeLeadStatus(id, next),
    onSuccess: () => {
      refresh()
      setLostOpen(false)
      setLostReasonId('')
      setLostNote('')
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
    onSuccess: refresh,
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
  const currency = branding?.currency ?? 'SAR'

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
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-semibold tracking-tight">{lead.full_name}</h1>
              <StatusBadge status={lead.status} />
              <TemperatureBadge temperature={lead.temperature} />
              {lead.re_engaged_count > 0 && (
                <Badge tone="info">{t('leads.reEngagedCount', { count: lead.re_engaged_count })}</Badge>
              )}
              {lead.is_overdue && <Badge tone="danger">{t('leads.overdue')}</Badge>}
            </div>

            <p className="tabular mt-2 text-[0.875rem] text-[var(--color-ink-muted)]" dir="ltr">
              {lead.phone ?? '—'}
            </p>

            <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">
              {lead.assignee?.name ?? t('common.unassigned')}
              {lead.project?.name ? ` · ${lead.project.name}` : ''}
            </p>
          </div>

          {/*
            Every action a rep performs on a lead lives here, above the fold:
            call, WhatsApp, note, follow-up, temperature and assignment. Nothing
            that is done dozens of times a day should require scrolling.
          */}
          <div className="flex flex-wrap gap-2" data-testid="lead-quick-actions">
            {lead.phone && (
              <a href={`tel:${lead.phone}`} onClick={() => contactMutation.mutate('call')}>
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
                  className="border-emerald-300 text-emerald-700 hover:bg-emerald-50"
                  data-testid="action-whatsapp"
                >
                  {t('leads.whatsapp')}
                </Button>
              </a>
            )}

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
                onClick={() => (status === 'lost' ? setLostOpen(true) : statusMutation.mutate(status))}
                className={cn(
                  'rounded-lg border px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
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
        <div className="space-y-4 lg:col-span-2">
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
                    <li key={entry.id} className="rounded-lg bg-[var(--color-surface-muted)] p-3">
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
        </div>

        <div className="space-y-4">
          <DetailCard title={t('leads.contactInfo')}>
            <Row label={t('leads.phone')} value={lead.phone} ltr />
            <Row label={t('leads.email')} value={lead.email} ltr />
            <Row label={t('leads.city')} value={lead.city} />
          </DetailCard>

          <DetailCard title={t('leads.interest')}>
            <Row label={t('leads.project')} value={lead.project?.name} />
            <Row
              label={t('leads.budget')}
              value={formatBudgetRange(lead.budget_min, lead.budget_max, currency, i18n.language)}
            />
            <Row label={t('leads.propertyType')} value={lead.preferred_property_type} />
            <Row label={t('leads.preferredLocation')} value={lead.preferred_location} />
            <Row label={t('leads.paymentMethod')} value={lead.payment_method} />
            <Row label={t('leads.timeline')} value={lead.purchase_timeline} />
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
