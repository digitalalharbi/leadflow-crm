import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck, CalendarClock, ClipboardCheck, UserX } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, PageHeader, Pagination, Skeleton, Tabs } from '@/components/ui'
import { VisitCard } from '@/components/visits/VisitCard'
import { ReservationCard } from '@/components/visits/ReservationCard'
import { fetchReservations, fetchVisits, VISIT_BUCKETS, type VisitBucket } from '@/features/visits'
import { cn } from '@/lib/cn'

const AREAS = ['visits', 'reservations'] as const
type Area = (typeof AREAS)[number]

const BUCKET_ICON: Record<VisitBucket, LucideIcon> = {
  requested: CalendarClock, upcoming: CalendarCheck, needs_result: ClipboardCheck, no_show: UserX,
}

/**
 * Visits and reservations: the two steps between "interested" and "sold".
 *
 * A visit is asked for, confirmed, moved, done or missed — and once done it needs a result. A
 * reservation holds a unit until it is confirmed, sold, cancelled or lapses. The lists are the
 * work: what is waiting for a time, what happened and nobody said how it went, who did not come.
 */
export function VisitsPage() {
  const { t } = useTranslation()
  const [area, setArea] = useState<Area>('visits')
  const [bucket, setBucket] = useState<VisitBucket>('requested')
  const [page, setPage] = useState(1)

  const visits = useQuery({ queryKey: ['visits', bucket, page], queryFn: () => fetchVisits(bucket, page), enabled: area === 'visits' })
  const reservations = useQuery({ queryKey: ['reservations', 'open', page], queryFn: () => fetchReservations('open', page), enabled: area === 'reservations' })
  // The counts on the chips: one cheap request each, the first page only.
  const counts = useQuery({
    queryKey: ['visits', 'counts'],
    queryFn: async () => Object.fromEntries(await Promise.all(VISIT_BUCKETS.map(async (b) => [b, (await fetchVisits(b, 1)).meta?.total ?? 0]))) as Record<VisitBucket, number>,
    refetchInterval: 60_000,
  })

  const active = area === 'visits' ? visits : reservations

  return (
    <>
      <PageHeader title={t('visits.title')} subtitle={t('visits.subtitle')} />

      <Tabs testId="visits-tabs" value={area} onChange={(next) => { setArea(next); setPage(1) }} items={AREAS.map((key) => ({ key, label: t(`visits.area.${key}`) }))} />

      <div className="mt-3 space-y-3">
        {area === 'visits' && (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="visit-buckets">
            {VISIT_BUCKETS.map((key) => {
              const Icon = BUCKET_ICON[key]
              const count = counts.data?.[key] ?? 0
              const alarming = (key === 'requested' || key === 'needs_result' || key === 'no_show') && count > 0

              return (
                <button
                  key={key}
                  type="button"
                  aria-pressed={bucket === key}
                  data-testid={`visit-bucket-${key}`}
                  onClick={() => { setBucket(key); setPage(1) }}
                  className={cn(
                    'pressable flex items-center gap-2 rounded-xl px-3 py-2 text-start',
                    bucket === key ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]',
                  )}
                >
                  <Icon className="size-4 shrink-0" aria-hidden />
                  <span className="t-small min-w-0 flex-1 truncate font-medium">{t(`visits.bucket.${key}`)}</span>
                  <span className={cn('text-[1.125rem] font-extrabold tabular-nums', alarming && bucket !== key && 'text-[var(--color-danger)]')} data-testid={`visit-bucket-count-${key}`}>{count}</span>
                </button>
              )
            })}
          </div>
        )}

        {active.isLoading ? (
          <Skeleton className="h-40" />
        ) : active.isError ? (
          <ErrorState onRetry={() => void active.refetch()} />
        ) : (active.data?.data.length ?? 0) === 0 ? (
          <EmptyState title={t(area === 'visits' ? `visits.empty.${bucket}` : 'visits.empty.reservations')} body="" />
        ) : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid={area === 'visits' ? 'visit-list' : 'reservation-list'}>
            {area === 'visits'
              ? visits.data?.data.map((visit) => <VisitCard key={visit.id} visit={visit} />)
              : reservations.data?.data.map((reservation) => <ReservationCard key={reservation.id} reservation={reservation} />)}
          </ul>
        )}

        {active.data?.meta && <Pagination meta={active.data.meta} onPageChange={setPage} />}
      </div>
    </>
  )
}
