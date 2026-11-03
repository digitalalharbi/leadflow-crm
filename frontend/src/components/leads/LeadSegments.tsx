import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, BadgeCheck, BellRing, Bookmark, Flame, Gem, HeartHandshake, MessageCircleReply, MessageCircleOff, Snowflake, Sparkles, Star, UserCog, UserX, Users2, X,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { deleteSavedView, fetchSavedViews, saveView, type LeadFilters, type SavedView } from '@/features/leads'
import { cn } from '@/lib/cn'

type SegmentKey =
  | 'all' | 'priority' | 'hot' | 'awaiting_contact' | 'overdue' | 'unassigned' | 'replied'
  | 'needs_human' | 'no_reply' | 'high_value' | 'new_today' | 'qualified' | 'stalled' | 'interested'

type Counts = Record<SegmentKey, number>

const SEGMENTS: Array<{ key: SegmentKey; Icon: LucideIcon; apply: Partial<LeadFilters> }> = [
  { key: 'all', Icon: Users2, apply: {} },
  { key: 'priority', Icon: Star, apply: { priority: true } },
  { key: 'hot', Icon: Flame, apply: { temperature: ['hot'] } },
  { key: 'awaiting_contact', Icon: AlertTriangle, apply: { awaiting_contact: true } },
  { key: 'overdue', Icon: BellRing, apply: { overdue: true } },
  { key: 'unassigned', Icon: UserX, apply: { unassigned: true } },
  { key: 'replied', Icon: MessageCircleReply, apply: { replied: true } },
  { key: 'needs_human', Icon: UserCog, apply: { view: 'needs_human' } },
  { key: 'no_reply', Icon: MessageCircleOff, apply: { view: 'no_reply' } },
  { key: 'high_value', Icon: Gem, apply: { view: 'high_value' } },
  { key: 'new_today', Icon: Sparkles, apply: { view: 'new_today' } },
  { key: 'qualified', Icon: BadgeCheck, apply: { view: 'qualified' } },
  { key: 'stalled', Icon: Snowflake, apply: { view: 'stalled' } },
  { key: 'interested', Icon: HeartHandshake, apply: { view: 'interested' } },
]

/** Everything a segment can set, so choosing one clears the others. */
const CLEARED: Partial<LeadFilters> = {
  priority: undefined,
  temperature: undefined,
  awaiting_contact: undefined,
  overdue: undefined,
  unassigned: undefined,
  replied: undefined,
  view: undefined,
  page: 1,
}

/** The filters a saved view may hold: everything a person can set from the list. */
const SAVEABLE: Array<keyof LeadFilters> = [
  'search', 'status', 'temperature', 'classification', 'platform', 'project_id', 'assigned_to', 'overdue', 'priority',
  'wrong_fit', 'unassigned', 'awaiting_contact', 'replied', 'campaign_name', 'follow_up', 'view',
]

function activeFilters(filters: LeadFilters): Partial<LeadFilters> {
  return Object.fromEntries(
    SAVEABLE.flatMap((key) => {
      const value = filters[key]
      const empty = value === undefined || value === '' || value === false || (Array.isArray(value) && value.length === 0)

      return empty ? [] : [[key, value]]
    }),
  )
}

function isActive(key: SegmentKey, filters: LeadFilters): boolean {
  const on = {
    priority: Boolean(filters.priority),
    hot: filters.temperature?.length === 1 && filters.temperature[0] === 'hot',
    awaiting_contact: Boolean(filters.awaiting_contact),
    overdue: Boolean(filters.overdue),
    unassigned: Boolean(filters.unassigned),
    replied: Boolean(filters.replied),
    needs_human: filters.view === 'needs_human',
    no_reply: filters.view === 'no_reply',
    high_value: filters.view === 'high_value',
    new_today: filters.view === 'new_today',
    qualified: filters.view === 'qualified',
    stalled: filters.view === 'stalled',
    interested: filters.view === 'interested',
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
        const urgent = (key === 'awaiting_contact' || key === 'overdue' || key === 'priority' || key === 'needs_human') && (count ?? 0) > 0

        return (
          <button
            key={key}
            type="button"
            aria-pressed={active}
            data-testid={`segment-${key}`}
            onClick={() => onChange({ ...CLEARED, ...apply })}
            className={cn(
              'pressable inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-[0.9375rem] font-medium',
              active
                ? 'bg-[var(--brand)] text-[var(--brand-ink)] shadow-[var(--shadow-card)]'
                : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
            )}
          >
            <Icon className="size-3.5" aria-hidden />
            {t(`leads.segments.${key}`)}
            <span
              className={cn(
                'tabular-nums rounded-full px-1.5 text-[0.8125rem]',
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

      <SavedViews filters={filters} onChange={onChange} />
    </div>
  )
}

/**
 * The lists a person keeps for themselves. Pressing one sets exactly the
 * filters it was saved with; the bookmark saves whatever is set right now.
 */
function SavedViews({ filters, onChange }: { filters: LeadFilters; onChange: (next: Partial<LeadFilters>) => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [naming, setNaming] = useState(false)
  const [name, setName] = useState('')

  const { data } = useQuery({ queryKey: ['lead-saved-views'], queryFn: fetchSavedViews })
  const current = activeFilters(filters)
  const saveable = Object.keys(current).length > 0

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['lead-saved-views'] })
  const create = useMutation({
    mutationFn: () => saveView(name.trim(), current),
    onSuccess: () => {
      setName('')
      setNaming(false)
      void refresh()
    },
  })
  const remove = useMutation({ mutationFn: (id: string) => deleteSavedView(id), onSuccess: () => void refresh() })

  const apply = (view: SavedView) => onChange({ ...CLEARED, ...view.filters })

  return (
    <>
      {(data?.views ?? []).map((view) => (
        <span
          key={view.id}
          data-testid={`saved-view-${view.id}`}
          className="inline-flex shrink-0 items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]"
        >
          <button type="button" onClick={() => apply(view)} className="pressable inline-flex items-center gap-1.5 rounded-full py-1.5 ps-3 pe-1.5 text-[0.9375rem] font-semibold">
            <Bookmark className="size-3.5" aria-hidden />
            {view.name}
          </button>
          <button
            type="button"
            aria-label={t('leads.segments.remove')}
            data-testid={`saved-view-remove-${view.id}`}
            onClick={() => remove.mutate(view.id)}
            className="pressable me-1 grid size-6 place-items-center rounded-full hover:bg-black/10"
          >
            <X className="size-3.5" aria-hidden />
          </button>
        </span>
      ))}

      {naming ? (
        <form
          className="inline-flex shrink-0 items-center gap-1"
          onSubmit={(e) => {
            e.preventDefault()
            if (name.trim()) create.mutate()
          }}
        >
          <input
            autoFocus
            value={name}
            maxLength={80}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('leads.segments.saveName')}
            aria-label={t('leads.segments.saveName')}
            data-testid="saved-view-name"
            className="field !h-9 w-40"
          />
          <button type="submit" data-testid="saved-view-confirm" disabled={!name.trim() || create.isPending} className="pressable rounded-full bg-[var(--brand)] px-3 py-1.5 text-[0.9375rem] font-semibold text-[var(--brand-ink)] disabled:opacity-50">
            {t('leads.segments.saveConfirm')}
          </button>
        </form>
      ) : (
        <button
          type="button"
          disabled={!saveable}
          data-testid="saved-view-save"
          title={t('leads.segments.save')}
          onClick={() => setNaming(true)}
          className="pressable inline-flex shrink-0 items-center gap-1.5 rounded-full border border-dashed border-[var(--color-border-strong)] px-3 py-1.5 text-[0.9375rem] font-medium text-[var(--color-ink-muted)] disabled:opacity-40"
        >
          <Bookmark className="size-3.5" aria-hidden />
          {t('leads.segments.save')}
        </button>
      )}
    </>
  )
}
