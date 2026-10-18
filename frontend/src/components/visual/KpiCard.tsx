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
        'inline-flex items-center rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold tabular-nums',
        good ? 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]' : 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
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
  children?: ReactNode
}) {
  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <span
          className="grid size-10 place-items-center rounded-full"
          style={{ background: TONE_TINT[tone], color: TONE_INK[tone] }}
          aria-hidden
        >
          <Icon className="size-[1.125rem]" />
        </span>
        {delta}
      </div>

      <p className="mt-3 text-[0.8125rem] text-[var(--color-ink-muted)]">{label}</p>
      <div className="flex items-end justify-between gap-2">
        <div className="t-metric" data-testid={valueTestId}>
          {value}
        </div>
        {spark && spark.length > 1 && <MiniTrend values={spark} color={TONE_INK[tone]} />}
      </div>
      {children}
    </>
  )

  const shell = 'block rounded-[var(--radius-card)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-card)]'

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
