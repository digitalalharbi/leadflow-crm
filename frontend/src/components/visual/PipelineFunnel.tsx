import { Link } from 'react-router-dom'
import { formatNumber } from '@/lib/format'
import { brandRamp } from './tones'

export interface FunnelStep {
  key: string
  label: string
  count: number
  href?: string
}

export interface FunnelRow extends FunnelStep {
  /** Width as a share of the first step, 0–100. */
  width: number
  /** Share of the previous step that made it here, 0–100; null for the first. */
  keep: number | null
}

/** Pure, so the proportions can be tested without drawing anything. */
export function funnelRows(steps: FunnelStep[]): FunnelRow[] {
  const top = Math.max(1, steps[0]?.count ?? 0, ...steps.map((s) => s.count))

  return steps.map((step, index) => {
    const previous = index > 0 ? steps[index - 1].count : null

    return {
      ...step,
      // A floor, so a step with a handful of leads is still a visible bar.
      width: step.count > 0 ? Math.max(6, (step.count / top) * 100) : 0,
      keep: previous === null ? null : previous > 0 ? Math.round((step.count / previous) * 100) : null,
    }
  })
}

/**
 * The path a lead takes, as bars that narrow.
 *
 * Each step is a bar as wide as its share of the first, its count beside it,
 * and between steps the one number that explains the narrowing: what share of
 * the step before it got this far. The ramp runs pale to deep, so "further
 * along" is also "heavier" — the order is carried by tone, not by a legend.
 */
export function PipelineFunnel({ steps, testId }: { steps: FunnelStep[]; testId?: string }) {
  const rows = funnelRows(steps)
  const colors = brandRamp(rows.length)

  return (
    <ol className="space-y-1" data-testid={testId}>
      {rows.map((row, index) => {
        const bar = (
          <div className="flex items-center gap-3">
            <span className="w-24 shrink-0 truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{row.label}</span>
            <div className="h-7 flex-1 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]">
              <span
                className="flex h-full items-center justify-end rounded-full px-3"
                data-chart-mark
                style={{
                  width: `${row.width}%`,
                  background: colors[index],
                  transition: 'width var(--duration-slow) var(--ease-out-soft)',
                }}
              />
            </div>
            <span className="w-12 shrink-0 text-end text-[0.9375rem] font-semibold tabular-nums">{formatNumber(row.count)}</span>
          </div>
        )

        return (
          <li key={row.key}>
            {row.keep !== null && (
              <p className="ps-24 text-[0.6875rem] leading-4 text-[var(--color-ink-subtle)] tabular-nums" dir="ltr" data-testid={`funnel-keep-${row.key}`}>
                ↓ {row.keep}%
              </p>
            )}
            {row.href ? (
              <Link to={row.href} className="block rounded-full hover:bg-[var(--color-surface-muted)]">
                {bar}
              </Link>
            ) : (
              bar
            )}
          </li>
        )
      })}
    </ol>
  )
}
