import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck, ChevronLeft, Inbox, MessageSquareShare, Sparkles, Users, Workflow } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { Card, Skeleton } from '@/components/ui'
import { ProgressBar } from '@/components/visual'
import { formatCompact } from '@/lib/format'
import { cn } from '@/lib/cn'

type StepKey = 'arrived' | 'automated' | 'reached' | 'followed' | 'waiting' | 'closed'

interface JourneyData {
  days: number
  steps: { key: StepKey; count: number; appointments?: number; won?: number }[]
}

const STEPS: Record<StepKey, { Icon: LucideIcon; to: string }> = {
  arrived: { Icon: Inbox, to: '/leads' },
  automated: { Icon: Workflow, to: '/automation' },
  reached: { Icon: MessageSquareShare, to: '/leads' },
  followed: { Icon: CalendarCheck, to: '/follow-ups' },
  waiting: { Icon: Users, to: '/work' },
  closed: { Icon: Sparkles, to: '/pipeline' },
}

/**
 * The product's promise, in order, as one row.
 *
 * A lead arrives, the automation starts, the customer is reached, a follow-up
 * is set, the team is told who matters, and an outcome comes back. Each is a
 * count with a bar for its share of the first, so a break in the chain — leads
 * arriving that nothing is reaching — is a short bar, seen before it is read.
 * "Waiting for the team" is the node with the brand colour: it is the only one
 * that is somebody's job right now, and it opens the work queue.
 */
export function JourneyStrip({ days }: { days: number }) {
  const { t, i18n } = useTranslation()

  const { data } = useQuery({
    queryKey: ['dashboard', 'journey', days],
    queryFn: () => apiGet<JourneyData>('/dashboard/journey', { days }),
  })

  if (!data) return <Skeleton className="h-32" />

  const arrived = Math.max(1, data.steps[0]?.count ?? 0)

  return (
    <Card data-testid="journey-strip">
      <ol className="grid grid-cols-3 gap-x-2 gap-y-4 p-4 lg:grid-cols-6">
        {data.steps.map((step, index) => {
          const { Icon, to } = STEPS[step.key]
          const hot = step.key === 'waiting'
          // "Waiting" is a count of now, not of the window, so it is not a share of arrivals.
          const share = hot ? null : Math.min(100, Math.round((step.count / arrived) * 100))

          return (
            <li key={step.key} className="relative" data-testid={`journey-${step.key}`}>
              <Link to={to} className="group flex flex-col items-center gap-1.5 rounded-[var(--radius-control)] px-1 py-1 text-center hover:bg-[var(--color-surface-muted)]">
                <span
                  className={cn(
                    'grid size-11 place-items-center rounded-full transition-transform group-hover:scale-105',
                    hot ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--brand-soft)] text-[var(--brand)]',
                  )}
                  aria-hidden
                >
                  <Icon className="size-5" />
                </span>
                <span className="t-metric !text-[1.5rem] leading-none" data-testid={`journey-count-${step.key}`}>
                  {formatCompact(step.count, i18n.language)}
                </span>
                <span className="text-[0.75rem] text-[var(--color-ink-muted)]">{t(`journey.${step.key}`)}</span>
                {share !== null ? <ProgressBar value={share} className="w-full max-w-24" /> : <span className="h-1.5" />}
              </Link>
              {index < data.steps.length - 1 && (
                <ChevronLeft
                  className="absolute top-5 hidden size-4 text-[var(--color-ink-subtle)] [inset-inline-end:-0.5rem] lg:block ltr:rotate-180"
                  aria-hidden
                />
              )}
            </li>
          )
        })}
      </ol>
    </Card>
  )
}
