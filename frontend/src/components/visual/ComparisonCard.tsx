import type { ReactNode } from 'react'
import { Delta } from './KpiCard'
import { TONE_COLOR, type Tone } from './tones'
import { cn } from '@/lib/cn'

/**
 * This period against the last, in one glance: the figure, the change, and two bars of the same
 * scale so "up a third" is something you see before you read it.
 */
export function ComparisonCard({
  label,
  value,
  previous,
  current,
  before,
  invert = false,
  tone = 'brand',
  nowLabel,
  beforeLabel,
  testId,
  className,
}: {
  label: string
  /** The figure as it should read (formatted). */
  value: ReactNode
  /** The raw numbers: this period and the one before. */
  current: number
  before: number | null | undefined
  previous?: ReactNode
  /** Lower is better (cost, response time). */
  invert?: boolean
  tone?: Tone
  nowLabel: string
  beforeLabel: string
  testId?: string
  className?: string
}) {
  const top = Math.max(current, before ?? 0, 1)

  return (
    <div data-testid={testId} className={cn('rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]', className)}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{label}</p>
        <Delta value={current} previous={before} invert={invert} />
      </div>
      <p className="t-metric mt-1" dir="ltr">{value}</p>
      <div className="mt-2 space-y-1" aria-hidden>
        <Bar width={(current / top) * 100} color={TONE_COLOR[tone]} caption={nowLabel} />
        {before !== null && before !== undefined && (
          <Bar width={(before / top) * 100} color="var(--color-border-strong)" caption={beforeLabel} trailing={previous} />
        )}
      </div>
    </div>
  )
}

function Bar({ width, color, caption, trailing }: { width: number; color: string; caption: string; trailing?: ReactNode }) {
  return (
    <div className="flex items-center gap-2">
      <span className="t-caption w-16 shrink-0 truncate">{caption}</span>
      <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]">
        <span className="block h-full rounded-full" style={{ width: `${Math.max(2, Math.min(100, width))}%`, background: color }} />
      </span>
      {trailing !== undefined && <span className="t-caption tabular-nums" dir="ltr">{trailing}</span>}
    </div>
  )
}
