import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarCheck, Check, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGetPaginated, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatRelative, whatsappLink } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, PageHeader, Pagination, SkeletonTable,
  StatusBadge, Textarea, Modal, TemperatureBadge,
} from '@/components/ui'
import type { FollowUp, PaginationMeta } from '@/types/api'
import { cn } from '@/lib/cn'

type Bucket = 'overdue' | 'today' | 'upcoming' | 'completed'

const BUCKETS: Bucket[] = ['overdue', 'today', 'upcoming', 'completed']

export function FollowUpsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [bucket, setBucket] = useState<Bucket>('today')
  const [page, setPage] = useState(1)
  const [completing, setCompleting] = useState<FollowUp | null>(null)
  const [outcome, setOutcome] = useState('')

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
      apiPost(`/follow-ups/${followUp.id}/complete`, { outcome: outcome || undefined }),
    onSuccess: () => {
      toast(t('followUps.completedMsg'), 'success')
      setCompleting(null)
      setOutcome('')
      void queryClient.invalidateQueries({ queryKey: ['follow-ups'] })
      void queryClient.invalidateQueries({ queryKey: ['follow-up-counts'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const followUps = data?.data ?? []

  const emptyText = {
    overdue: t('followUps.emptyOverdue'),
    today: t('followUps.emptyToday'),
    upcoming: t('followUps.emptyUpcoming'),
    completed: t('followUps.emptyCompleted'),
  }[bucket]

  const badge = (key: Bucket) =>
    key === 'overdue' ? counts?.overdue : key === 'today' ? counts?.today : key === 'upcoming' ? counts?.upcoming : undefined

  return (
    <>
      <PageHeader title={t('followUps.title')} subtitle={t('followUps.subtitle')} />

      <Card className="overflow-hidden">
        {/* Buckets as tabs: a rep works overdue first, then today. */}
        <div className="scroll-x flex gap-1 border-b border-[var(--color-border)] p-2">
          {BUCKETS.map((key) => {
            const count = badge(key)

            return (
              <button
                key={key}
                type="button"
                onClick={() => {
                  setBucket(key)
                  setPage(1)
                }}
                className={cn(
                  'flex items-center gap-2 rounded-lg px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
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
                        ? 'bg-[var(--color-status-lost)] text-white'
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
          <EmptyState icon={<CalendarCheck className="size-6" />} title={emptyText} />
        ) : (
          <>
            <ul className="divide-y divide-[var(--color-border)]">
              {followUps.map((followUp) => (
                <li key={followUp.id} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      {followUp.lead ? (
                        <Link
                          to={`/leads/${followUp.lead.id}`}
                          className="truncate font-medium hover:text-[var(--brand)] hover:underline"
                        >
                          {followUp.lead.full_name}
                        </Link>
                      ) : (
                        <span className="text-[var(--color-ink-subtle)]">—</span>
                      )}
                      {followUp.lead && <StatusBadge status={followUp.lead.status} />}
                      {followUp.lead && <TemperatureBadge temperature={followUp.lead.temperature} />}
                    </div>

                    {followUp.note && (
                      <p className="mt-1 truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{followUp.note}</p>
                    )}

                    <p
                      className={cn(
                        'mt-1 text-[0.75rem]',
                        followUp.is_overdue ? 'font-medium text-[var(--color-status-lost)]' : 'text-[var(--color-ink-subtle)]',
                      )}
                    >
                      {formatDateTime(followUp.due_at, i18n.language)}
                      {followUp.is_overdue && ` · ${formatRelative(followUp.due_at, i18n.language)}`}
                    </p>
                  </div>

                  <div className="flex shrink-0 items-center gap-1.5">
                    {followUp.lead?.phone && (
                      <a
                        href={`tel:${followUp.lead.phone}`}
                        aria-label={t('leads.call')}
                        className="grid size-9 place-items-center rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:text-[var(--brand)]"
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
                        className="grid size-9 place-items-center rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:text-emerald-600"
                      >
                        <MessageCircle className="size-4" aria-hidden />
                      </a>
                    )}
                    {followUp.status === 'pending' && (
                      <Button size="sm" icon={<Check className="size-4" />} onClick={() => setCompleting(followUp)}>
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
          <Textarea
            label={t('followUps.outcome')}
            hint={t('common.optional')}
            value={outcome}
            onChange={(e) => setOutcome(e.target.value)}
          />
        </div>
      </Modal>
    </>
  )
}
