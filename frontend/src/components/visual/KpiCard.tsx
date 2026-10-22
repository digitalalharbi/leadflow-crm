import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'
import { MiniTrend } from '@/components/charts/MiniTrend'
import { TONE_TINT, TONE_INK, type Tone } from './tones'
import { cn } from '@/lib/cn'

/** A change against the last period; nothing when there is nothing to compare. */
export function Delta({ value, previous, invert = false }: { value: number; previous?: number | null; invert?: boolean }) {
  if (previous === undefined || previous === null || previous <= 0) return null

  const change = ((value - previous) / previous) * 100
  const good = invert ? change <= 0 : change >= 0

  return (
    <span
      // The sign belongs before the number in either direction.
      dir="ltr"
      data-testid="delta"
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold tabular-nums',
        // A change is a figure worth noticing: dark copper on its soft fill. A fall stays danger.
        good ? 'bg-[var(--accent-soft)] text-[var(--accent-text)]' : 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
      )}
    >
      {change >= 0 ? '+' : ''}
      {change.toFixed(0)}%
    </span>
  )
}

/**
 * One number, one icon, one verdict.
 *
 * The icon says what it counts, the figure is the largest thing on the card,
 * the delta says whether it is better or worse than before, and an optional
 * sparkline says which way it has been heading. Four words at most: a KPI card
 * that needs a sentence is a paragraph with a number in it.
 */
export function KpiCard({
  label,
  value,
  icon: Icon,
  tone = 'brand',
  delta,
  spark,
  to,
  testId,
  valueTestId,
  revenue = false,
  children,
}: {
  label: string
  value: ReactNode
  icon: LucideIcon
  tone?: Tone
  delta?: ReactNode
  spark?: number[]
  to?: string
  testId?: string
  valueTestId?: string
  /** Money or deals: the one kind of card that wears the copper rule and label (never every card). */
  revenue?: boolean
  children?: ReactNode
}) {
  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <span
          className="grid size-8 place-items-center rounded-full sm:size-10"
          style={{ background: TONE_TINT[tone], color: TONE_INK[tone] }}
          aria-hidden
        >
          <Icon className="size-[1.125rem]" />
        </span>
        {delta}
      </div>

      <p className={cn('mt-2 text-[0.9375rem] sm:mt-3', revenue ? 'font-medium text-[var(--accent-text)]' : 'text-[var(--color-ink-muted)]')}>{label}</p>
      <div className="flex items-end justify-between gap-2">
        <div className="t-metric" data-testid={valueTestId}>
          {value}
        </div>
        {spark && spark.length > 1 && <MiniTrend values={spark} color={TONE_INK[tone]} />}
      </div>
      {children}
    </>
  )

  const shell = cn('block rounded-[var(--radius-card)] border border-[var(--color-line)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] sm:p-4', revenue && 'border-t-[3px] border-t-[var(--accent)]')

  return to ? (
    <Link to={to} className={cn(shell, 'transition-shadow hover:shadow-[var(--shadow-raised)]')} data-testid={testId}>
      {body}
    </Link>
  ) : (
    <div className={shell} data-testid={testId}>
      {body}
    </div>
  )
}
