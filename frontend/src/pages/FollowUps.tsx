import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarCheck, Check, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGetPaginated, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatClock, formatDateTime, formatDue, whatsappLink } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, Input, PageHeader, Pagination, Select,
  Badge, SkeletonTable, StatusBadge, Textarea, Modal, TemperatureBadge,
} from '@/components/ui'
import type { FollowUp, FollowUpOutcome, PaginationMeta } from '@/types/api'
import { cn } from '@/lib/cn'

type Bucket = 'overdue' | 'today' | 'upcoming' | 'missed' | 'completed'

/*
 * "Missed" sits between the work still waiting and the work that got done: it
 * is the work that will never happen now, which is the number a manager asking
 * "how much are we dropping" actually wants.
 */
const BUCKETS: Bucket[] = ['overdue', 'today', 'upcoming', 'missed', 'completed']

const OUTCOMES: FollowUpOutcome[] = [
  'replied', 'no_answer', 'interested', 'not_interested', 'callback', 'appointment',
]

/** Days from now, or a date the rep picks. */
type NextIn = '' | '1' | '3' | '7' | 'custom'


export function FollowUpsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)

  const requested = searchParams.get('bucket')
  const bucket: Bucket = BUCKETS.includes(requested as Bucket) ? (requested as Bucket) : 'today'

  function selectBucket(next: Bucket) {
    setSearchParams({ bucket: next }, { replace: true })
    setPage(1)
  }
  const [completing, setCompleting] = useState<FollowUp | null>(null)
  const [outcome, setOutcome] = useState('')
  const [result, setResult] = useState<FollowUpOutcome | ''>('')
  const [nextIn, setNextIn] = useState<NextIn>('')
  const [customDate, setCustomDate] = useState('')

  /** The next follow-up as an ISO instant, or nothing if none was chosen. */
  const nextDueAt = () => {
    if (nextIn === '') return null
    if (nextIn === 'custom') return customDate ? new Date(customDate).toISOString() : null

    const date = new Date()
    date.setDate(date.getDate() + Number(nextIn))
    // Mid-morning: a follow-up due at the exact minute it was created is a
    // reminder nobody can act on.
    date.setHours(10, 0, 0, 0)

    return date.toISOString()
  }

  const { data: counts } = useQuery({
    queryKey: ['follow-up-counts'],
    queryFn: () => apiGetPaginated<{ overdue: number; today: number; upcoming: number }>('/follow-ups/counts').then((r) => r.data),
  })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['follow-ups', bucket, page],
    queryFn: () =>
      apiGetPaginated<FollowUp[]>('/follow-ups', { bucket, page, per_page: 25 }) as Promise<{
        data: FollowUp[]
        meta: PaginationMeta | undefined
      }>,
    placeholderData: (previous) => previous,
  })

  const completeMutation = useMutation({
    mutationFn: (followUp: FollowUp) =>
      apiPost(`/follow-ups/${followUp.id}/complete`, {
        outcome: result || undefined,
        outcome_note: outcome || undefined,
        next_due_at: nextDueAt() || undefined,
      }),
    onSuccess: () => {
      toast(t('followUps.completedMsg'), 'success')
      setCompleting(null)
      setOutcome('')
      setResult('')
      setNextIn('')
      setCustomDate('')
      void queryClient.invalidateQueries({ queryKey: ['lead'] })
      void queryClient.invalidateQueries({ queryKey: ['follow-ups'] })
      void queryClient.invalidateQueries({ queryKey: ['follow-up-counts'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const followUps = data?.data ?? []

  const emptyText: Record<Bucket, string> = {
    overdue: t('followUps.emptyOverdue'),
    today: t('followUps.emptyToday'),
    upcoming: t('followUps.emptyUpcoming'),
    missed: t('followUps.emptyMissed'),
    completed: t('followUps.emptyCompleted'),
  }

  const badge = (key: Bucket) =>
    key === 'overdue' ? counts?.overdue : key === 'today' ? counts?.today : key === 'upcoming' ? counts?.upcoming : undefined

  return (
    <>
      <PageHeader title={t('followUps.title')} subtitle={t('followUps.subtitle')} />

      <Card className="overflow-hidden">
        {/* Buckets as tabs: a rep works overdue first, then today. */}
        <div className="scroll-x flex gap-1 border-b border-[var(--color-line)] p-2">
          {BUCKETS.map((key) => {
            const count = badge(key)

            return (
              <button
                key={key}
                type="button"
                onClick={() => selectBucket(key)}
                className={cn(
                  'flex items-center gap-2 rounded-[var(--radius-control)] px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                  bucket === key
                    ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                    : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
                )}
              >
                {t(`followUps.${key}`)}
                {count !== undefined && count > 0 && (
                  <span
                    className={cn(
                      'tabular rounded-full px-1.5 py-0.5 text-[0.6875rem] font-semibold',
                      key === 'overdue'
                        ? 'bg-[var(--color-danger)] text-white'
                        : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]',
                    )}
                  >
                    {count}
                  </span>
                )}
              </button>
            )
          })}
        </div>

        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={6} cols={4} />
        ) : followUps.length === 0 ? (
          <EmptyState icon={<CalendarCheck className="size-6" />} title={emptyText[bucket]} />
        ) : (
          <>
            <ul className="divide-y divide-[var(--color-line)]">
              {followUps.map((followUp) => (
                <li
                  key={followUp.id}
                  data-testid="follow-up-row"
                  className="flex flex-wrap items-center gap-3 px-5 py-3.5 transition-colors hover:bg-[var(--color-surface-muted)]"
                >
                  {/*
                    The due time leads the row. On a work queue the question is
                    always "is this late, and how late" — the lead's name is how
                    you act on it, but the clock is why you are looking.
                  */}
                  <div
                    className={cn(
                      'flex w-[5.5rem] shrink-0 flex-col items-center justify-center',
                      'rounded-[var(--radius-control)] px-2 py-1.5',
                      followUp.is_overdue
                        ? 'bg-[color-mix(in_srgb,var(--color-danger)_10%,transparent)] text-[var(--color-danger)]'
                        : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]',
                    )}
                  >
                    <span className="t-small text-center leading-tight font-medium">
                      {formatDue(followUp.due_at, i18n.language, followUp.is_overdue)}
                    </span>
                    {/* The relative label already says which day; repeating the
                        full date here only made it wrap. */}
                    <span
                      className="tabular text-[0.6875rem] opacity-75"
                      dir="ltr"
                      title={formatDateTime(followUp.due_at, i18n.language)}
                    >
                      {formatClock(followUp.due_at)}
                    </span>
                  </div>

                  {/*
                    `min-w-0 flex-1` alone let this block shrink to nothing on a
                    phone instead of wrapping, so the name — the thing you act
                    on — was truncated to two letters while the buttons kept
                    their full width. A floor makes it wrap the row instead.
                  */}
                  <div className="min-w-[9rem] flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      {followUp.lead ? (
                        <Link
                          to={`/leads/${followUp.lead.id}`}
                          className="truncate font-medium hover:text-[var(--brand)]"
                        >
                          {followUp.lead.full_name}
                        </Link>
                      ) : (
                        <span className="text-[var(--color-ink-subtle)]">—</span>
                      )}
                      {followUp.lead && <TemperatureBadge temperature={followUp.lead.temperature} />}
                      {followUp.lead && (
                        <StatusBadge status={followUp.lead.status} className="hidden sm:inline-flex" />
                      )}
                      {followUp.outcome && (
                        <Badge tone="neutral">{t(`outcomes.${followUp.outcome}`)}</Badge>
                      )}
                    </div>

                    <p className="t-caption mt-0.5 flex flex-wrap items-center gap-x-2">
                      {followUp.assignee?.name && <span>{followUp.assignee.name}</span>}
                    </p>

                    {(followUp.note || followUp.outcome_note) && (
                      <p className="t-small mt-1 truncate text-[var(--color-ink-muted)]">
                        {followUp.outcome_note ?? followUp.note}
                      </p>
                    )}
                  </div>

                  <div className="flex shrink-0 items-center gap-1.5 max-sm:w-full max-sm:justify-end">
                    {followUp.lead?.phone && (
                      <a
                        href={`tel:${followUp.lead.phone}`}
                        aria-label={t('leads.call')}
                        className="pressable grid size-9 place-items-center rounded-[var(--radius-control)] border border-[var(--color-line)] text-[var(--color-ink-muted)] hover:text-[var(--brand)]"
                      >
                        <Phone className="size-4" aria-hidden />
                      </a>
                    )}
                    {followUp.lead?.whatsapp && (
                      <a
                        href={whatsappLink(followUp.lead.whatsapp) ?? '#'}
                        target="_blank"
                        rel="noreferrer"
                        aria-label={t('leads.whatsapp')}
                        className="pressable grid size-9 place-items-center rounded-[var(--radius-control)] border border-[var(--color-line)] text-[var(--color-ink-muted)] hover:text-[var(--color-status-won)]"
                      >
                        <MessageCircle className="size-4" aria-hidden />
                      </a>
                    )}
                    {followUp.status === 'pending' && (
                      <Button
                        size="sm"
                        icon={<Check className="size-4" />}
                        onClick={() => setCompleting(followUp)}
                        data-testid="complete-follow-up"
                      >
                        {t('followUps.complete')}
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>

            <Pagination meta={data?.meta} onPageChange={setPage} />
          </>
        )}
      </Card>

      <Modal
        open={Boolean(completing)}
        onClose={() => setCompleting(null)}
        title={t('followUps.complete')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setCompleting(null)}>{t('common.cancel')}</Button>
            <Button
              loading={completeMutation.isPending}
              onClick={() => completing && completeMutation.mutate(completing)}
            >
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-[0.875rem] font-medium">{completing?.lead?.full_name}</p>

          {/*
            What happened is a choice, not a sentence: a free-text box cannot be
            counted, and "what did the call produce?" is the question the
            reports need answered.
          */}
          <Select
            label={t('outcomes.label')}
            value={result}
            data-testid="followup-outcome"
            onChange={(e) => setResult(e.target.value as FollowUpOutcome | '')}
          >
            <option value="">{t('common.none')}</option>
            {OUTCOMES.map((option) => (
              <option key={option} value={option}>{t(`outcomes.${option}`)}</option>
            ))}
          </Select>

          {/* "Call them again in three days" is the most common next step, so
              it is one click rather than a date picker. */}
          <Select
            label={t('outcomes.nextFollowUp')}
            value={nextIn}
            data-testid="followup-next"
            onChange={(e) => setNextIn(e.target.value as NextIn)}
          >
            <option value="">{t('outcomes.noNext')}</option>
            <option value="1">{t('outcomes.tomorrow')}</option>
            <option value="3">{t('outcomes.inThreeDays')}</option>
            <option value="7">{t('outcomes.inAWeek')}</option>
            <option value="custom">{t('outcomes.customDate')}</option>
          </Select>

          {nextIn === 'custom' && (
            <Input
              type="datetime-local"
              label={t('outcomes.customDate')}
              value={customDate}
              data-testid="followup-next-date"
              onChange={(e) => setCustomDate(e.target.value)}
            />
          )}

          <Textarea
            label={t('followUps.outcomeNote')}
            hint={t('common.optional')}
            value={outcome}
            data-testid="followup-note"
            onChange={(e) => setOutcome(e.target.value)}
          />
        </div>
      </Modal>
    </>
  )
}
