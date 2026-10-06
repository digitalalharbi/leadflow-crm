import { useState, type ReactNode } from 'react'
import { BarChart3, Table2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card } from '@/components/ui'
import { cn } from '@/lib/cn'

/** The numbers behind the picture. */
export interface ChartTable {
  head: string[]
  rows: (string | number)[][]
}

/**
 * The frame every chart in مهتم sits in.
 *
 * It exists so that a chart's title, its action, its empty state and its
 * loading state are decided once rather than per screen — which is how a
 * product ends up with six slightly different card headers. It also reserves
 * the plot's height before data arrives, so nothing jumps when it lands.
 */
export function ChartCard({
  title,
  description,
  action,
  legend,
  footer,
  table,
  height = 260,
  loading = false,
  empty = false,
  emptyMessage,
  emptyAction,
  testId,
  className,
  children,
}: {
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  legend?: ReactNode
  footer?: ReactNode
  /**
   * Required wherever colour alone would carry meaning.
   *
   * It is not a nicety. The palette is allowed to be as vivid as it is because
   * two of its slots sit below 3:1 against the surface, and that is only
   * permitted where the reader can get the values another way. It is also the
   * answer for anyone using a screen reader, for whom a `<svg>` of bars is
   * nothing at all.
   */
  table?: ChartTable
  height?: number
  loading?: boolean
  empty?: boolean
  emptyMessage?: string
  emptyAction?: ReactNode
  testId?: string
  className?: string
  children: ReactNode
}) {
  const { t } = useTranslation()
  const [showTable, setShowTable] = useState(false)

  return (
    <Card className={cn('flex flex-col overflow-hidden', className)} data-testid={testId}>
      <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-4 pb-3">
        <div className="min-w-0">
          <h3 className="t-card-title truncate">{title}</h3>
          {description && <p className="t-caption mt-0.5">{description}</p>}
        </div>
        {/*
          Controls only — a toggle and whatever the screen passed as an action.
          On paper the reader has already got whichever view was chosen, so
          these are two buttons that do nothing but take up the corner.
        */}
        <div className="flex shrink-0 items-center gap-1.5" data-print="hide">
          {action}
          {table && !loading && !empty && (
            <button
              type="button"
              onClick={() => setShowTable((previous) => !previous)}
              aria-pressed={showTable}
              aria-label={t(showTable ? 'charts.showChart' : 'charts.showTable')}
              data-testid={testId ? `${testId}-table-toggle` : undefined}
              className="pressable grid size-8 place-items-center rounded-[var(--radius-control)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]"
            >
              {showTable ? <BarChart3 className="size-4" aria-hidden /> : <Table2 className="size-4" aria-hidden />}
            </button>
          )}
        </div>
      </div>

      {legend && <div className="px-5 pb-3">{legend}</div>}

      {/*
        The height is reserved whatever the state, so the card does not resize
        between skeleton, empty and loaded. A dashboard that reflows as each
        request lands is a dashboard nobody can read while it loads.
      */}
      <div className="min-w-0 flex-1 px-2 pb-2" style={{ minHeight: height }}>
        {loading ? (
          <ChartSkeleton height={height} />
        ) : empty ? (
          <ChartEmpty message={emptyMessage} action={emptyAction} height={height} />
        ) : showTable && table ? (
          <ChartDataTable table={table} height={height} />
        ) : (
          children
        )}
      </div>

      {footer && <div className="border-t border-[var(--color-line)] px-5 py-3">{footer}</div>}
    </Card>
  )
}

/** The same figures, as figures. Scrolls rather than squeezing. */
function ChartDataTable({ table, height }: { table: ChartTable; height: number }) {
  return (
    <div className="overflow-auto px-3" style={{ maxHeight: height }}>
      <table className="w-full text-[0.8125rem]">
        <thead className="sticky top-0 bg-[var(--color-surface)]">
          <tr className="text-start text-[var(--color-ink-muted)]">
            {table.head.map((cell) => (
              <th key={cell} scope="col" className="px-2 py-1.5 text-start font-medium whitespace-nowrap">
                {cell}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {table.rows.map((row, index) => (
            <tr key={index} className="border-t border-[var(--color-line)]">
              {row.map((cell, cellIndex) => (
                <td
                  key={cellIndex}
                  className={cn('px-2 py-1.5', cellIndex > 0 && 'tabular-nums')}
                >
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Bars of plausible heights, not a grey block — it reads as a chart arriving. */
export function ChartSkeleton({ height }: { height: number }) {
  const bars = [52, 74, 38, 88, 61, 46, 70, 33]

  return (
    <div className="flex h-full items-end gap-2 px-3 pb-6" style={{ height }} aria-hidden>
      {bars.map((value, i) => (
        <div
          key={i}
          className="skeleton flex-1 rounded-t-[0.3rem]"
          style={{ height: `${value}%`, animationDelay: `${i * 60}ms` }}
        />
      ))}
    </div>
  )
}

export function ChartEmpty({
  message,
  action,
  height,
}: {
  message?: string
  action?: ReactNode
  height: number
}) {
  return (
    <div
      className="flex flex-col items-center justify-center gap-3 px-6 text-center"
      style={{ height }}
    >
      {/* A flat line is the honest picture of no data, and it belongs to the
          chart language rather than being a generic empty illustration. */}
      <svg viewBox="0 0 64 28" className="w-16 text-[var(--color-border-strong)]" aria-hidden>
        <path d="M2 20h60" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeDasharray="4 5" />
        <circle cx="16" cy="20" r="2.5" fill="currentColor" />
        <circle cx="32" cy="20" r="2.5" fill="currentColor" />
        <circle cx="48" cy="20" r="2.5" fill="currentColor" />
      </svg>
      <p className="t-small text-[var(--color-ink-muted)]">{message}</p>
      {action}
    </div>
  )
}
