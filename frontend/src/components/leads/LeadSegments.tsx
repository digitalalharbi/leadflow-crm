import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, BellRing, Flame, MessageCircleReply, Star, UserX, Users2 } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import type { LeadFilters } from '@/features/leads'
import { cn } from '@/lib/cn'

type SegmentKey = 'all' | 'priority' | 'hot' | 'awaiting_contact' | 'overdue' | 'unassigned' | 'replied'

type Counts = Record<SegmentKey, number>

const SEGMENTS: Array<{ key: SegmentKey; Icon: LucideIcon; apply: Partial<LeadFilters> }> = [
  { key: 'all', Icon: Users2, apply: {} },
  { key: 'priority', Icon: Star, apply: { priority: true } },
  { key: 'hot', Icon: Flame, apply: { temperature: ['hot'] } },
  { key: 'awaiting_contact', Icon: AlertTriangle, apply: { awaiting_contact: true } },
  { key: 'overdue', Icon: BellRing, apply: { overdue: true } },
  { key: 'unassigned', Icon: UserX, apply: { unassigned: true } },
  { key: 'replied', Icon: MessageCircleReply, apply: { replied: true } },
]

/** Everything a segment can set, so choosing one clears the others. */
const CLEARED: Partial<LeadFilters> = {
  priority: undefined,
  temperature: undefined,
  awaiting_contact: undefined,
  overdue: undefined,
  unassigned: undefined,
  replied: undefined,
  page: 1,
}

function isActive(key: SegmentKey, filters: LeadFilters): boolean {
  const on = {
    priority: Boolean(filters.priority),
    hot: filters.temperature?.length === 1 && filters.temperature[0] === 'hot',
    awaiting_contact: Boolean(filters.awaiting_contact),
    overdue: Boolean(filters.overdue),
    unassigned: Boolean(filters.unassigned),
    replied: Boolean(filters.replied),
  }

  if (key === 'all') return !Object.values(on).some(Boolean)

  // Exactly this segment and no other, or it is a filter combination and not a
  // segment at all — and highlighting one of several would be a small lie.
  return on[key] && Object.entries(on).filter(([, v]) => v).length === 1
}

/**
 * The lists people actually work from, with how long each is.
 *
 * The leads table answers "who is in here"; this answers "which of them needs
 * me" before a row is read. A count on a segment is exactly the length of the
 * list behind it, so pressing one never lands on a different number.
 *
 * Segments that are empty are shown, muted, not hidden: a chip that appears and
 * disappears moves the row under the cursor, and "nothing is waiting" is itself
 * the thing a rep is glad to see.
 */
export function LeadSegments({
  filters,
  onChange,
  showUnassigned,
}: {
  filters: LeadFilters
  onChange: (next: Partial<LeadFilters>) => void
  showUnassigned: boolean
}) {
  const { t, i18n } = useTranslation()

  const { data } = useQuery({
    queryKey: ['lead-segments'],
    queryFn: () => apiGet<Counts>('/leads/segments'),
    // A minute: the numbers move while somebody is looking, and a rep who has
    // just cleared a list should see it shorter without reloading.
    refetchInterval: 60_000,
  })

  return (
    <div
      role="group"
      aria-label={t('leads.segments.label')}
      className="flex gap-1.5 overflow-x-auto px-3 pb-2 pt-3"
      data-testid="lead-segments"
    >
      {SEGMENTS.filter((segment) => segment.key !== 'unassigned' || showUnassigned).map(({ key, Icon, apply }) => {
        const active = isActive(key, filters)
        const count = data?.[key]
        const urgent = (key === 'awaiting_contact' || key === 'overdue' || key === 'priority') && (count ?? 0) > 0

        return (
          <button
            key={key}
            type="button"
            aria-pressed={active}
            data-testid={`segment-${key}`}
            onClick={() => onChange({ ...CLEARED, ...apply })}
            className={cn(
              'pressable inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-[0.8125rem] font-medium',
              active
                ? 'bg-[var(--brand)] text-[var(--brand-ink)] shadow-[var(--shadow-card)]'
                : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
            )}
          >
            <Icon className="size-3.5" aria-hidden />
            {t(`leads.segments.${key}`)}
            <span
              className={cn(
                'tabular-nums rounded-full px-1.5 text-[0.6875rem]',
                active
                  ? 'bg-white/20'
                  : urgent
                    ? 'bg-[color-mix(in_srgb,var(--color-danger)_14%,transparent)] text-[var(--color-danger)]'
                    : 'bg-[var(--color-surface)]',
              )}
              data-testid={`segment-${key}-count`}
            >
              {count === undefined ? '·' : formatNumber(count, i18n.language)}
            </span>
          </button>
        )
      })}
    </div>
  )
}
