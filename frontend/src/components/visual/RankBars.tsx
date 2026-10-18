import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { formatNumber } from '@/lib/format'

export interface RankItem {
  key: string
  label: string
  value: number
  /** A second, quieter figure: "37%", "12 won". */
  note?: string
  leading?: ReactNode
  href?: string
}

/** Sorted high to low, each with its bar as a share of the leader. Pure. */
export function rank(items: RankItem[], limit = items.length) {
  const sorted = [...items].sort((a, b) => b.value - a.value).slice(0, limit)
  const top = Math.max(1, sorted[0]?.value ?? 0)

  return sorted.map((item) => ({ ...item, width: item.value > 0 ? Math.max(4, (item.value / top) * 100) : 0 }))
}

/**
 * A ranking: who or what is ahead, by how much.
 *
 * For "which source", "which rep", "which project" — any comparison. The bar is
 * the comparison; the number is for confirming it. The leader is the heaviest
 * bar and the rest are measured against it, so a reader sees the gap before
 * reading either figure.
 */
export function RankBars({
  items,
  limit = 5,
  color = 'var(--brand)',
  testId,
}: {
  items: RankItem[]
  limit?: number
  color?: string
  testId?: string
}) {
  const rows = rank(items, limit)

  return (
    <ul className="space-y-2.5" data-testid={testId}>
      {rows.map((row, index) => {
        const body = (
          <div className="flex items-center gap-3">
            {row.leading ?? (
              <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--color-chrome-fill)] text-[0.6875rem] font-semibold tabular-nums text-[var(--color-ink-muted)]">
                {index + 1}
              </span>
            )}
            <div className="min-w-0 flex-1">
              <div className="mb-1 flex items-baseline justify-between gap-2">
                <span className="truncate text-[0.8125rem] font-medium">{row.label}</span>
                <span className="shrink-0 text-[0.8125rem] font-semibold tabular-nums">
                  {formatNumber(row.value)}
                  {row.note && <span className="ms-1.5 text-[0.6875rem] font-normal text-[var(--color-ink-muted)]">{row.note}</span>}
                </span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]">
                <span
                  className="block h-full rounded-full"
                  data-chart-mark
                  style={{ width: `${row.width}%`, background: color, opacity: index === 0 ? 1 : 0.55 }}
                />
              </div>
            </div>
          </div>
        )

        return (
          <li key={row.key}>
            {row.href ? (
              <Link to={row.href} className="block rounded-[var(--radius-control)] hover:bg-[var(--color-surface-muted)]">
                {body}
              </Link>
            ) : (
              body
            )}
          </li>
        )
      })}
    </ul>
  )
}
