import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowRightLeft, Banknote, CalendarCheck, CalendarClock, FileText, Gauge, Inbox, Mail, MessageCircle, Phone, StickyNote, Zap,
  type LucideIcon,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui'
import { fetchJourney, JOURNEY_KINDS, type JourneyEntry, type JourneyKind } from '@/features/journey'
import { formatDate, formatTime } from '@/lib/format'
import { cn } from '@/lib/cn'

const KIND: Record<JourneyKind, { icon: LucideIcon; color: string }> = {
  source: { icon: Inbox, color: 'var(--color-info)' },
  automation: { icon: Zap, color: 'var(--brand)' },
  whatsapp: { icon: MessageCircle, color: 'var(--color-status-contacted)' },
  email: { icon: Mail, color: 'var(--color-info)' },
  file: { icon: FileText, color: 'var(--color-warning)' },
  call: { icon: Phone, color: 'var(--color-success)' },
  appointment: { icon: CalendarCheck, color: 'var(--color-status-appointment)' },
  followup: { icon: CalendarClock, color: 'var(--color-info)' },
  stage: { icon: ArrowRightLeft, color: 'var(--color-ink-muted)' },
  score: { icon: Gauge, color: 'var(--color-temp-warm)' },
  revenue: { icon: Banknote, color: 'var(--color-success)' },
  note: { icon: StickyNote, color: 'var(--color-ink-subtle)' },
}

/**
 * The whole story of a lead, in one column: a coloured icon per kind of event,
 * a short title, a line of detail, and who did it. Filter chips count what is
 * there; the day is a heading, not a repeated timestamp.
 */
/**
 * `version` is the lead screen's own refresh time: whatever made the lead
 * reload — a note, a call, a stage — makes the journey reload with it.
 */
export function JourneyTimeline({ leadId, version }: { leadId: string; version?: number }) {
  const { t, i18n } = useTranslation()
  const [only, setOnly] = useState<JourneyKind | null>(null)

  const { data, isLoading } = useQuery({
    placeholderData: (previous) => previous, queryKey: ['lead-journey', leadId, version], queryFn: () => fetchJourney(leadId) })

  if (isLoading && !data) return <Skeleton className="h-48" />
  if (!data) return null

  const entries = data.entries.filter((entry) => only === null || entry.kind === only)
  const days = groupByDay(entries)

  return (
    <div data-testid="activity-timeline">
      <div className="mb-3 flex flex-wrap gap-1.5" role="group" aria-label={t('leadJourney.filter')} data-testid="journey-filters">
        {JOURNEY_KINDS.filter((kind) => (data.counts[kind] ?? 0) > 0).map((kind) => {
          const { icon: Icon, color } = KIND[kind]
          const active = only === kind

          return (
            <div key={kind}>
              <button
                type="button"
                aria-pressed={active}
                data-testid={`journey-filter-${kind}`}
                onClick={() => setOnly(active ? null : kind)}
                className={cn(
                  'pressable inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[0.9375rem] font-medium',
                  active ? 'border-transparent text-white' : 'border-[var(--color-border)] text-[var(--color-ink)]',
                )}
                style={active ? { background: color } : undefined}
              >
                <Icon className="size-3.5" aria-hidden style={active ? undefined : { color }} />
                {t(`leadJourney.kind.${kind}`)}
                <span className="tabular-nums opacity-80">{data.counts[kind]}</span>
              </button>
            </div>
          )
        })}
      </div>

      {entries.length === 0 ? (
        <p className="t-caption py-6 text-center">{t('leadJourney.empty')}</p>
      ) : (
        <div className="space-y-4">
          {days.map(([day, items]) => (
            <section key={day}>
              <h4 className="mb-2 text-[0.9375rem] font-bold text-[var(--color-ink-muted)]">{formatDate(items[0].at, i18n.language)}</h4>
              <ol className="relative space-y-2.5 ps-4 before:absolute before:inset-y-1 before:start-[0.9375rem] before:w-px before:bg-[var(--color-border-strong)]">
                {items.map((entry) => (
                  <Entry key={entry.id} entry={entry} />
                ))}
              </ol>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

function Entry({ entry }: { entry: JourneyEntry }) {
  const { t } = useTranslation()
  const { icon: Icon, color } = KIND[entry.kind]
  const from = entry.meta?.from as string | undefined
  const to = entry.meta?.to as string | undefined
  const title = entry.type
    ? entry.type === 're_engaged' && entry.meta?.restored
      ? t('activity.re_engaged_restored')
      : t(`activity.${entry.type}`, { defaultValue: t(`status.${entry.type}`, { defaultValue: entry.detail ?? entry.title }) })
    : entry.title

  return (
    <li className="relative flex items-start gap-3" data-testid="journey-entry" data-kind={entry.kind} data-actor={entry.actor}>
      <span
        className="relative z-10 grid size-8 shrink-0 place-items-center rounded-full"
        style={{ background: `color-mix(in srgb, ${color} 16%, var(--color-surface))`, color }}
        aria-hidden
      >
        <Icon className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
          <span className="text-[1rem] font-semibold">
            {title}
            {entry.type === 'status_changed' && from && to && (
              <span className="font-medium text-[var(--color-ink-muted)]">
                {' '}{t(`status.${from}`, { defaultValue: from })} → {t(`status.${to}`, { defaultValue: to })}
              </span>
            )}
            {entry.kind === 'score' && entry.type === 'score_changed' && (
              <span dir="ltr" className="tabular-nums font-medium text-[var(--color-ink-muted)]">
                {' '}{String(from ?? '')} → {String(to ?? '')}
              </span>
            )}
          </span>
          <span
            data-testid="journey-actor"
            className="rounded-full bg-[var(--color-surface-sunken)] px-2 py-0.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]"
          >
            {entry.by ?? t(`leadJourney.actor.${entry.actor}`)}
          </span>
          <span className="ms-auto text-[0.875rem] tabular-nums text-[var(--color-ink-subtle)]">{formatTime(entry.at)}</span>
        </div>
        {entry.detail && entry.type !== 'status_changed' && entry.type !== 'score_changed' && <p className="t-caption line-clamp-2">{entry.detail}</p>}
      </div>
    </li>
  )
}

function groupByDay(entries: JourneyEntry[]): Array<[string, JourneyEntry[]]> {
  const map = new Map<string, JourneyEntry[]>()

  for (const entry of entries) {
    const day = entry.at.slice(0, 10)
    map.set(day, [...(map.get(day) ?? []), entry])
  }

  return [...map.entries()]
}
