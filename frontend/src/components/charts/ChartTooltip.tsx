import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/**
 * One tooltip shape for every chart.
 *
 * Rows are label-left, value-right in both directions, with the values aligned
 * on a tabular column — a tooltip whose numbers wander is harder to read than
 * no tooltip. It renders as a surface rather than a dark box, so it belongs to
 * the same product in both themes.
 */
export function ChartTooltip({
  title,
  rows,
  footer,
  className,
}: {
  title?: ReactNode
  rows: Array<{ label: string; value: ReactNode; color?: string; emphasis?: boolean }>
  footer?: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'pointer-events-none min-w-[10rem] rounded-[var(--radius-control)] px-3 py-2.5',
        'bg-[var(--color-surface-raised)] shadow-[var(--shadow-pop)]',
        className,
      )}
    >
      {title && <p className="t-small mb-1.5 font-semibold">{title}</p>}

      <dl className="space-y-1">
        {rows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-4">
            <dt className="flex min-w-0 items-center gap-1.5">
              {row.color && (
                <span
                  className="size-2 shrink-0 rounded-[2px]"
                  style={{ background: row.color }}
                  aria-hidden
                />
              )}
              <span className="t-caption truncate">{row.label}</span>
            </dt>
            <dd
              className={cn(
                'tabular text-[0.8125rem] whitespace-nowrap',
                row.emphasis ? 'font-semibold text-[var(--color-ink)]' : 'text-[var(--color-ink)]',
              )}
              dir="ltr"
            >
              {row.value}
            </dd>
          </div>
        ))}
      </dl>

      {footer && (
        <p className="t-caption mt-2 border-t border-[var(--color-line)] pt-1.5">{footer}</p>
      )}
    </div>
  )
}
