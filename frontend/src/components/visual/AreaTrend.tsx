import { useState } from 'react'
import { formatNumber } from '@/lib/format'

export interface TrendPoint {
  label: string
  value: number
}

/**
 * A trend with one job: show the shape, and say the number under the pointer.
 *
 * Time runs left to right in every language — mirroring a timeline in Arabic
 * would put yesterday on the wrong side of today — so the plot is pinned to
 * `ltr` while the label and the numbers inside the tooltip follow the page.
 * Flat fill and a single line; no axes, because the tooltip is the scale.
 */
export function AreaTrend({
  points,
  height = 112,
  formatValue = (value: number) => formatNumber(value),
  testId,
}: {
  points: TrendPoint[]
  height?: number
  formatValue?: (value: number) => string
  testId?: string
}) {
  const [active, setActive] = useState<number | null>(null)

  const width = 320
  const pad = 8

  if (points.length < 2) return <div style={{ height }} aria-hidden />

  const max = Math.max(...points.map((p) => p.value), 1)
  const step = width / (points.length - 1)
  const coords = points.map((p, i) => [i * step, height - pad - (p.value / max) * (height - pad * 2)] as const)

  const line = coords.reduce((path, [x, y], i) => {
    if (i === 0) return `M${x.toFixed(1)},${y.toFixed(1)}`
    const [px, py] = coords[i - 1]
    const cx = (px + x) / 2
    return `${path} C${cx.toFixed(1)},${py.toFixed(1)} ${cx.toFixed(1)},${y.toFixed(1)} ${x.toFixed(1)},${y.toFixed(1)}`
  }, '')

  function onMove(event: React.PointerEvent<HTMLDivElement>) {
    const box = event.currentTarget.getBoundingClientRect()
    const ratio = (event.clientX - box.left) / box.width
    setActive(Math.max(0, Math.min(points.length - 1, Math.round(ratio * (points.length - 1)))))
  }

  const current = active === null ? null : points[active]
  const [cx, cy] = active === null ? [0, 0] : coords[active]

  return (
    <div
      dir="ltr"
      className="relative"
      style={{ height }}
      onPointerMove={onMove}
      onPointerLeave={() => setActive(null)}
      data-testid={testId}
    >
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="size-full" role="img" aria-label={points.map((p) => `${p.label}: ${p.value}`).join(', ')}>
        <path d={`${line} L${width},${height} L0,${height} Z`} fill="var(--brand-tint)" opacity="0.4" data-chart-mark />
        <path d={line} fill="none" stroke="var(--brand-deep)" strokeWidth="1.75" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        {current && <line x1={cx} x2={cx} y1={0} y2={height} stroke="var(--color-line)" strokeWidth="1" vectorEffect="non-scaling-stroke" />}
      </svg>

      {current && (
        <>
          <span
            className="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--color-surface)] bg-[var(--brand-deep)]"
            style={{ left: `${(cx / width) * 100}%`, top: `${(cy / height) * 100}%` }}
            aria-hidden
          />
          <div
            className="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-[var(--radius-control)] bg-[var(--color-surface)] px-2.5 py-1.5 shadow-[var(--shadow-pop)]"
            style={{ left: `${Math.min(88, Math.max(12, (cx / width) * 100))}%` }}
            role="status"
            dir="auto"
          >
            <span className="block text-[0.6875rem] text-[var(--color-ink-muted)]">{current.label}</span>
            <span className="block text-[0.9375rem] font-semibold tabular-nums">{formatValue(current.value)}</span>
          </div>
        </>
      )}
    </div>
  )
}
