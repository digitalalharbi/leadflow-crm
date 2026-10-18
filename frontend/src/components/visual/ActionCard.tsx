import { Link } from 'react-router-dom'
import { ArrowUpRight } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { formatNumber } from '@/lib/format'
import { TONE_INK, TONE_TINT, type Tone } from './tones'
import { cn } from '@/lib/cn'

/**
 * Something to do, with how much of it there is.
 *
 * Not a notification and not a metric: it is a task, so it has a count, a verb
 * (the title), and one way in. Coloured by urgency so a row of them can be read
 * as a priority order before any word is. An action with a count of zero is not
 * shown — an empty task is not a task.
 */
export function ActionCard({
  icon: Icon,
  title,
  count,
  tone = 'brand',
  to,
  testId,
}: {
  icon: LucideIcon
  title: string
  count?: number
  tone?: Tone
  to: string
  testId?: string
}) {
  return (
    <Link
      to={to}
      data-testid={testId}
      className={cn(
        'group flex items-center gap-3 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 pe-4 shadow-[var(--shadow-card)]',
        'transition-shadow hover:shadow-[var(--shadow-raised)]',
      )}
    >
      <span className="grid size-11 shrink-0 place-items-center rounded-full" style={{ background: TONE_TINT[tone], color: TONE_INK[tone] }} aria-hidden>
        <Icon className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        {count !== undefined && <span className="block text-[1.375rem] font-bold leading-tight tabular-nums">{formatNumber(count)}</span>}
        <span className="block truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{title}</span>
      </span>
      <ArrowUpRight className="size-4 shrink-0 text-[var(--color-ink-subtle)] transition-transform group-hover:text-[var(--color-ink)] rtl:-scale-x-100" aria-hidden />
    </Link>
  )
}
