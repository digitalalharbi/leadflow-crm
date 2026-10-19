import { Link } from 'react-router-dom'
import { formatNumber } from '@/lib/format'

export interface FunnelStep {
  key: string
  label: string
  count: number
  href?: string
  /** What one of this step cost, already formatted ("SAR 120"). */
  costPer?: string | null
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
      width: step.count > 0 ? Math.max(8, (step.count / top) * 100) : 0,
      keep: previous === null ? null : previous > 0 ? Math.round((step.count / previous) * 100) : null,
    }
  })
}

/**
 * The path a lead takes, as bars that narrow.
 *
 * Each step is a bar as wide as its share of the first, its count written on
 * the bar; beside it, the share of the step before that got this far and —
 * when known — what one of these cost. The bars deepen as the funnel goes on,
 * so "further along" is also "heavier": the order is carried by tone, not by a
 * legend. A step nobody reached is an empty track, not a missing row.
 */
export function PipelineFunnel({ steps, testId }: { steps: FunnelStep[]; testId?: string }) {
  const rows = funnelRows(steps)

  return (
    <ol className="space-y-2" data-testid={testId}>
      {rows.map((row, index) => {
        const shade = Math.max(55, 100 - index * 8)
        const inside = row.width >= 22

        const bar = (
          <div className="flex items-center gap-3">
            <span className="w-24 shrink-0 truncate text-[0.8125rem] font-medium text-[var(--color-ink-muted)]">{row.label}</span>
            <div className="relative h-8 flex-1 overflow-hidden rounded-xl bg-[var(--color-chrome-fill)]" dir="ltr">
              {row.width > 0 && (
                <span
                  className="absolute inset-y-0 start-0 flex items-center justify-end rounded-xl px-2.5"
                  data-chart-mark
                  style={{
                    width: `${row.width}%`,
                    background: `color-mix(in oklab, var(--brand) ${shade}%, var(--brand-deep, var(--brand)))`,
                    opacity: 0.35 + (shade / 100) * 0.65,
                    transition: 'width var(--duration-slow) var(--ease-out-soft)',
                  }}
                >
                  {inside && <span className="text-[0.8125rem] font-semibold tabular-nums text-white">{formatNumber(row.count)}</span>}
                </span>
              )}
              {!inside && (
                <span className="absolute inset-y-0 flex items-center text-[0.8125rem] font-semibold tabular-nums" style={{ left: `calc(${row.width}% + 0.5rem)` }}>
                  {formatNumber(row.count)}
                </span>
              )}
            </div>
            <span className="w-20 shrink-0 text-end text-[0.6875rem] tabular-nums text-[var(--color-ink-subtle)]" dir="ltr">
              {row.keep !== null && <span data-testid={`funnel-keep-${row.key}`}>{row.keep}%</span>}
              {row.costPer && <span className="block">{row.costPer}</span>}
            </span>
          </div>
        )

        return (
          <li key={row.key} data-count={row.count}>
            {row.href ? (
              <Link to={row.href} className="block rounded-xl hover:bg-[var(--color-surface-muted)]">
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
