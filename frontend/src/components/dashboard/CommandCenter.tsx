import {
  AlertTriangle, BellRing, CalendarClock, Flame, MessageSquareWarning, PhoneCall,
  Rocket, Sparkles, UserX, Workflow,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Card, Skeleton, buttonClasses } from '@/components/ui'
import {
  useCommandCenter,
  type AttentionKey,
  type AttentionRow,
  type Recommendation,
} from '@/features/commandCenter'
import { formatClock, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const ICON: Record<AttentionKey, LucideIcon> = {
  sla_breached: AlertTriangle,
  overdue_follow_ups: BellRing,
  hot: Flame,
  appointments_awaiting_outcome: CalendarClock,
  unassigned: UserX,
  automation_failures: Workflow,
  message_failures: MessageSquareWarning,
}

const URGENCY_DOT: Record<Recommendation['urgency'], string> = {
  critical: 'bg-[var(--color-danger)]',
  high: 'bg-[var(--color-warning)]',
  medium: 'bg-[var(--brand)]',
  low: 'bg-[var(--color-border-strong)]',
}

/**
 * The first thing on the dashboard, and the only part that is about *now*.
 *
 * Three questions, answered without a click: what is going wrong, which lead
 * should I call first and why, and what is on today. Everything else on the
 * page describes a period; a person who opens the product at nine wants this.
 *
 * A row appears only when its count is above zero. When nothing is wrong the
 * panel says so once, in a sentence, rather than listing seven fine things.
 */
export function CommandCenter() {
  const { t } = useTranslation()
  const { data, isLoading } = useCommandCenter()

  if (isLoading || !data) {
    return (
      <div className="grid gap-3 lg:grid-cols-12" data-testid="command-center-loading">
        <Skeleton className="h-60 lg:col-span-7" />
        <Skeleton className="h-60 lg:col-span-5" />
      </div>
    )
  }

  const critical = data.attention.filter((row) => row.severity === 'critical').length

  return (
    <section className="grid gap-3 lg:grid-cols-12" data-testid="command-center">
      <Card className="relative overflow-hidden lg:col-span-7">
        {/* A wash of the workspace colour, not a decoration: it is the one
            place the page says "this is yours". Quiet enough to read over. */}
        <div
          aria-hidden
          className="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-[var(--brand-soft)] to-transparent"
        />
        <div className="relative p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-[1.0625rem] font-semibold leading-tight">
                {critical > 0 ? t('commandCenter.needsYou') : t('commandCenter.title')}
              </h2>
              <p className="mt-0.5 text-[0.8125rem] text-[var(--color-ink-muted)]">
                {data.attention.length === 0
                  ? t('commandCenter.allClear')
                  : t('commandCenter.subtitle', { count: data.attention.length })}
              </p>
            </div>

            {data.open_leads > 0 && (
              <Link to="/work" data-testid="start-work" className={buttonClasses('primary', 'md', 'gap-1.5')}>
                <Rocket className="size-4" aria-hidden />
                {t('commandCenter.startWork')}
              </Link>
            )}
          </div>

          {data.attention.length > 0 && (
            <ul className="mt-3 grid gap-2 sm:grid-cols-3" data-testid="attention-list">
              {data.attention.map((row) => (
                <AttentionTile key={row.key} row={row} />
              ))}
            </ul>
          )}
        </div>
      </Card>

      <Card className="lg:col-span-5">
        <div className="p-4">
          <div className="flex items-center justify-between gap-2">
            <h2 className="flex items-center gap-1.5 text-[0.9375rem] font-semibold">
              <Sparkles className="size-4 text-[var(--brand)]" aria-hidden />
              {t('commandCenter.recommended')}
            </h2>
          </div>

          {data.recommended.length === 0 ? (
            <p className="mt-3 text-[0.8125rem] text-[var(--color-ink-muted)]" data-testid="no-recommendations">
              {t('commandCenter.nothingToDo')}
            </p>
          ) : (
            <ul className="mt-2 divide-y divide-[var(--color-line)]" data-testid="recommended-list">
              {data.recommended.map((item) => (
                <li key={item.id}>
                  <Link
                    to={`/leads/${item.id}`}
                    className="pressable -mx-2 flex items-center gap-3 rounded-[var(--radius-control)] px-2 py-2 hover:bg-[var(--color-surface-muted)]"
                    data-testid="recommended-item"
                  >
                    <span
                      className={cn('size-2 shrink-0 rounded-full', URGENCY_DOT[item.urgency])}
                      aria-label={t(`work.urgency.${item.urgency}`)}
                    />
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-1.5 text-[0.8125rem] font-medium">
                        <span className="truncate">{item.name}</span>
                        {item.project && (
                          <span className="truncate text-[0.6875rem] font-normal text-[var(--color-ink-subtle)]">
                            {item.project}
                          </span>
                        )}
                      </span>
                      <span className="block truncate text-[0.75rem] text-[var(--color-ink-muted)]">
                        {item.reason}
                      </span>
                    </span>
                    <Badge tone={item.urgency === 'critical' ? 'danger' : 'neutral'}>
                      <PhoneCall className="me-1 size-3" aria-hidden />
                      {t(`work.action.${item.action}`)}
                    </Badge>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>

    </section>
  )
}

/**
 * Today's meetings and who is carrying what — the two things on the page that
 * are about people rather than leads. Rendered beside the lead-flow chart
 * rather than inside the command panel: that panel has to stay short enough
 * for the figures under it to remain on the first screen.
 */
export function CommandSide() {
  const { t } = useTranslation()
  const { data } = useCommandCenter()

  if (!data || (data.appointments_today.length === 0 && data.workload.length === 0)) {
    return null
  }

  return (
    <div className="grid content-start gap-3" data-testid="command-side">
    {data.appointments_today.length > 0 && (
      <Card>
        <div className="p-4" data-testid="appointments-today">
          <h2 className="flex items-center gap-1.5 text-[0.9375rem] font-semibold">
            <CalendarClock className="size-4 text-[var(--brand)]" aria-hidden />
            {t('commandCenter.appointmentsToday')}
          </h2>
          <ul className="mt-2 divide-y divide-[var(--color-line)]">
            {data.appointments_today.map((a) => (
              <li key={a.id} className="flex items-center gap-3 py-1.5 text-[0.8125rem]">
                <span className="w-14 shrink-0 tabular-nums text-[var(--color-ink-muted)]">
                  {formatClock(a.at)}
                </span>
                {a.lead_id ? (
                  <Link to={`/leads/${a.lead_id}`} className="min-w-0 flex-1 truncate font-medium hover:underline">
                    {a.lead}
                  </Link>
                ) : (
                  <span className="min-w-0 flex-1 truncate">{a.lead}</span>
                )}
                {a.location && (
                  <span className="truncate text-[0.75rem] text-[var(--color-ink-subtle)]">{a.location}</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      </Card>
    )}

    {data.workload.length > 0 && (
      <Card>
        <div className="p-4" data-testid="team-workload">
          <h2 className="text-[0.9375rem] font-semibold">{t('commandCenter.workload')}</h2>
          <ul className="mt-2 space-y-2">
            {data.workload.slice(0, 6).map((member) => {
              const widest = Math.max(...data.workload.map((m) => m.open), 1)
              return (
                <li key={member.id} className="text-[0.8125rem]">
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate font-medium">{member.name}</span>
                    <span className="tabular-nums text-[var(--color-ink-muted)]">
                      {formatNumber(member.open)}
                      {member.overdue > 0 && (
                        <span className="ms-2 text-[var(--color-danger)]">
                          {t('commandCenter.overdueShort', { count: member.overdue })}
                        </span>
                      )}
                    </span>
                  </div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                    <div
                      className="h-full rounded-full bg-[var(--brand)]"
                      style={{ width: `${(member.open / widest) * 100}%` }}
                    />
                  </div>
                </li>
              )
            })}
          </ul>
        </div>
      </Card>
    )}
    </div>
  )
}

function AttentionTile({ row }: { row: AttentionRow }) {
  const { t } = useTranslation()
  const Icon = ICON[row.key]
  const critical = row.severity === 'critical'

  return (
    <li>
      <Link
        to={row.href}
        data-testid={`attention-${row.key}`}
        className={cn(
          'pressable group flex items-center gap-2.5 rounded-[var(--radius-control)] px-2.5 py-2',
          'bg-[var(--color-surface)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-raised,var(--shadow-card))]',
        )}
      >
        <span
          className={cn(
            'flex size-8 shrink-0 items-center justify-center rounded-full',
            critical
              ? 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]'
              : 'bg-[color-mix(in_srgb,var(--color-warning)_14%,transparent)] text-[var(--color-warning)]',
          )}
        >
          <Icon className="size-4" aria-hidden />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-[1.25rem] font-semibold leading-none tabular-nums">
            {formatNumber(row.count)}
          </span>
          <span className="mt-0.5 line-clamp-2 block text-[0.6875rem] leading-tight text-[var(--color-ink-muted)]">
            {t(`commandCenter.attention.${row.key}`, { minutes: row.minutes })}
          </span>
        </span>
      </Link>
    </li>
  )
}
