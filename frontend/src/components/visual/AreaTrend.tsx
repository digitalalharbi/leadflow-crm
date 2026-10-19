import { useId, useState } from 'react'
import { ChartTooltip } from '@/components/charts/ChartTooltip'
import { formatCompact, formatNumber } from '@/lib/format'

export interface TrendPoint {
  label: string
  value: number
  /** The same day in the previous period, for the comparison line. */
  previous?: number | null
}

/**
 * The shared trend: a smooth 2px line over a fading area, a quiet dashed grid,
 * compact numbers on one axis, and a tooltip card that names the day, the value
 * and — when there is one — the previous period.
 *
 * Time runs left to right in every language: mirroring a timeline in Arabic
 * would put yesterday on the wrong side of today, so the plot is pinned to
 * `ltr` while the words inside it follow the page. One axis only: two measures
 * of different size on one chart flatten one of them into a line along the
 * floor, which reads as "zero".
 */
export function AreaTrend({
  points,
  height = 112,
  formatValue = (value: number) => formatNumber(value),
  axes = false,
  valueLabel,
  previousLabel,
  testId,
}: {
  points: TrendPoint[]
  height?: number
  formatValue?: (value: number) => string
  /** Grid lines, the value axis and the first / middle / last dates. */
  axes?: boolean
  valueLabel?: string
  previousLabel?: string
  testId?: string
}) {
  const [active, setActive] = useState<number | null>(null)
  const gradient = useId().replace(/:/g, '')

  const width = 320
  const padTop = 8
  const padBottom = axes ? 18 : 4
  const plot = height - padTop - padBottom

  if (points.length < 2) return <div style={{ height }} aria-hidden />

  const max = Math.max(...points.map((p) => Math.max(p.value, p.previous ?? 0)), 1)
  const step = width / (points.length - 1)
  const y = (v: number) => padTop + plot - (v / max) * plot
  const coords = points.map((p, i) => [i * step, y(p.value)] as const)

  const smooth = (pts: readonly (readonly [number, number])[]) =>
    pts.reduce((path, [x, yy], i) => {
      if (i === 0) return `M${x.toFixed(1)},${yy.toFixed(1)}`
      const [px, py] = pts[i - 1]
      const cx = (px + x) / 2
      return `${path} C${cx.toFixed(1)},${py.toFixed(1)} ${cx.toFixed(1)},${yy.toFixed(1)} ${x.toFixed(1)},${yy.toFixed(1)}`
    }, '')

  const line = smooth(coords)
  const hasPrevious = points.some((p) => p.previous !== undefined && p.previous !== null)
  const previousLine = hasPrevious ? smooth(points.map((p, i) => [i * step, y(p.previous ?? 0)] as const)) : null

  function onMove(event: React.PointerEvent<HTMLDivElement>) {
    const box = event.currentTarget.getBoundingClientRect()
    const ratio = (event.clientX - box.left) / box.width
    setActive(Math.max(0, Math.min(points.length - 1, Math.round(ratio * (points.length - 1)))))
  }

  const current = active === null ? null : points[active]
  const [cx, cy] = active === null ? [0, 0] : coords[active]
  const ticks = [0.5, 1].map((f) => ({ f, v: max * f }))
  const dateIdx = [0, Math.floor((points.length - 1) / 2), points.length - 1]

  return (
    <div dir="ltr" className="relative" style={{ height }} onPointerMove={onMove} onPointerLeave={() => setActive(null)} data-testid={testId}>
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="size-full" role="img" aria-label={points.map((p) => `${p.label}: ${p.value}`).join(', ')}>
        <defs>
          <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="var(--brand)" stopOpacity={0.28} />
            <stop offset="100%" stopColor="var(--brand)" stopOpacity={0} />
          </linearGradient>
        </defs>
        {axes && ticks.map(({ f }) => (
          <line key={f} x1={0} x2={width} y1={y(max * f)} y2={y(max * f)} stroke="var(--color-line)" strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />
        ))}
        {axes && <line x1={0} x2={width} y1={padTop + plot} y2={padTop + plot} stroke="var(--color-line)" vectorEffect="non-scaling-stroke" />}
        <path d={`${line} L${width},${padTop + plot} L0,${padTop + plot} Z`} fill={`url(#${gradient})`} data-chart-mark />
        {previousLine && <path d={previousLine} fill="none" stroke="var(--color-ink-subtle)" strokeWidth="1.5" strokeDasharray="4 4" vectorEffect="non-scaling-stroke" data-testid="trend-previous" />}
        <path d={line} fill="none" stroke="var(--brand)" strokeWidth="2" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        {current && <line x1={cx} x2={cx} y1={padTop} y2={padTop + plot} stroke="var(--color-border-strong)" strokeWidth="1" vectorEffect="non-scaling-stroke" />}
      </svg>

      {axes && (
        <>
          {ticks.map(({ f, v }) => (
            <span key={f} className="pointer-events-none absolute start-0 -translate-y-1/2 whitespace-nowrap text-[0.625rem] text-[var(--color-ink-subtle)] tabular-nums" style={{ top: `${(y(max * f) / height) * 100}%` }}>
              {formatCompact(v)}
            </span>
          ))}
          {dateIdx.map((i, n) => (
            <span
              key={`${i}-${n}`}
              className="pointer-events-none absolute bottom-0 whitespace-nowrap text-[0.625rem] text-[var(--color-ink-subtle)]"
              style={{ left: `${(coords[i][0] / width) * 100}%`, transform: n === 0 ? 'none' : n === 2 ? 'translateX(-100%)' : 'translateX(-50%)' }}
              dir="auto"
            >
              {points[i].label}
            </span>
          ))}
        </>
      )}

      {current && (
        <>
          <span
            className="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--color-surface)] bg-[var(--brand)]"
            style={{ left: `${(cx / width) * 100}%`, top: `${(cy / height) * 100}%` }}
            aria-hidden
          />
          <div className="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full" style={{ left: `${Math.min(85, Math.max(15, (cx / width) * 100))}%` }} role="status" dir="auto">
            <ChartTooltip
              title={current.label}
              rows={[
                { label: valueLabel ?? '', value: formatValue(current.value), color: 'var(--brand)', emphasis: true },
                ...(hasPrevious ? [{ label: previousLabel ?? '', value: current.previous === null || current.previous === undefined ? '—' : formatValue(current.previous), color: 'var(--color-ink-subtle)' }] : []),
              ]}
            />
          </div>
        </>
      )}
    </div>
  )
}
