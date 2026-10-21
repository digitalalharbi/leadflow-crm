import { cn } from '@/lib/cn'

/** How dark a cell is for a share (0–100): pale for a few, heavy for most, never fully opaque. */
export function heatShade(percent: number): number {
  return Math.round(8 + Math.max(0, Math.min(100, percent)) * 0.8)
}

/**
 * Rows × columns, each cell shaded by how much of the whole it holds. The same reading as the
 * campaign heatmap, for any grid: a dark row that goes pale at one column is where it narrows.
 */
export function HeatGrid({
  columns,
  rows,
  testId,
  className,
}: {
  columns: string[]
  rows: { key: string; label: string; cells: (number | null)[]; note?: string }[]
  testId?: string
  className?: string
}) {
  return (
    <div
      data-testid={testId}
      className={cn('grid items-center gap-1', className)}
      style={{ gridTemplateColumns: `minmax(6rem, 1.4fr) repeat(${columns.length}, minmax(3rem, 1fr))` }}
    >
      <span />
      {columns.map((column) => <span key={column} className="t-caption text-center">{column}</span>)}

      {rows.map((row) => (
        <HeatRow key={row.key} row={row} />
      ))}
    </div>
  )
}

function HeatRow({ row }: { row: { key: string; label: string; cells: (number | null)[]; note?: string } }) {
  return (
    <>
      <span className="min-w-0 truncate text-[0.9375rem] font-medium" title={row.note}>{row.label}</span>
      {row.cells.map((value, index) => (
        <span
          key={index}
          data-testid="heat-cell"
          data-value={value ?? ''}
          dir="ltr"
          className="grid h-8 place-items-center rounded-lg text-[0.875rem] font-semibold tabular-nums"
          style={value === null
            ? { background: 'var(--color-chrome-fill)', color: 'var(--color-ink-subtle)' }
            : { background: `color-mix(in srgb, var(--brand) ${heatShade(value)}%, transparent)`, color: value >= 55 ? 'var(--brand-ink)' : 'var(--color-ink)' }}
        >
          {value === null ? '—' : `${value}%`}
        </span>
      ))}
    </>
  )
}
