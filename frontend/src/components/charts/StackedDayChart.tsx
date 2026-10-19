import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChartTooltip } from './ChartTooltip'
import { useChartWidth } from './useChartWidth'

/** A day, plus one count per series key. */
export type DayPoint<K extends string> = { date: string } & Record<K, number>

/**
 * A stack per day: how much happened, and what it was made of.
 *
 * Extracted when a second screen needed the same picture. Two copies of a
 * hand-built SVG do not stay the same copy — the first one to get a 2px gap
 * between segments gets it alone, and then two screens disagree about what a
 * boundary looks like.
 *
 * Stacked rather than one line per key, because the question these answer is a
 * proportion — "how much ran, and how much of it did anything", "how many
 * arrived, and how many were the same lead twice". A stack shows that; separate
 * lines make the reader compute it.
 *
 * `dir="ltr"` on the plot: dates ascend left to right, like the numbers
 * themselves. Mirrored by the RTL page, a rising month looks like a falling one.
 */
export function StackedDayChart<K extends string>({
  days,
  keys,
  color,
  label,
  aria,
  height = 200,
}: {
  days: DayPoint<K>[]
  /** Drawn bottom to top in this order. */
  keys: readonly K[]
  color: Record<K, string>
  /** The human name of a key, for the tooltip. */
  label: (key: K) => string
  aria: string
  height?: number
}) {
  const { i18n } = useTranslation()
  const [hover, setHover] = useState<number | null>(null)
  const { ref, width } = useChartWidth()

  const geometry = useMemo(() => {
    const padding = { top: 10, right: 8, bottom: 20, left: 28 }
    const plotHeight = height - padding.top - padding.bottom
    const plotWidth = width - padding.left - padding.right
    const max = Math.max(1, ...days.map((d) => keys.reduce((sum, k) => sum + d[k], 0)))
    const slot = plotWidth / Math.max(days.length, 1)
    // A 2px gap between bars, and never a negative width on a crowded range.
    const barWidth = Math.max(2, slot - 2)

    return { width, padding, plotHeight, plotWidth, max, slot, barWidth }
  }, [days, height, keys, width])

  if (days.length === 0) {
    return null
  }

  const active = hover === null ? null : days[hover]
  const day = (iso: string) =>
    new Date(iso).toLocaleDateString(i18n.language, { day: 'numeric', month: 'short' })

  return (
    <div className="relative" dir="ltr" ref={ref}>
      <svg
        viewBox={`0 0 ${geometry.width} ${height}`}
        className="w-full"
        style={{ height }}
        role="img"
        aria-label={aria}
        onMouseLeave={() => setHover(null)}
      >
        <line
          x1={geometry.padding.left}
          x2={geometry.width - geometry.padding.right}
          y1={height - geometry.padding.bottom}
          y2={height - geometry.padding.bottom}
          stroke="var(--color-line)"
          strokeWidth={1}
        />
        <text
          x={geometry.padding.left - 6}
          y={geometry.padding.top + 8}
          textAnchor="end"
          className="fill-[var(--color-ink-muted)] text-[0.8125rem] tabular-nums"
        >
          {geometry.max}
        </text>

        {days.map((point, index) => {
          const x = geometry.padding.left + index * geometry.slot + 1
          let cursor = height - geometry.padding.bottom

          return (
            <g key={point.date} onMouseEnter={() => setHover(index)}>
              {/* Full-height target, so a one-lead day is still hoverable. */}
              <rect
                x={geometry.padding.left + index * geometry.slot}
                y={geometry.padding.top}
                width={geometry.slot}
                height={geometry.plotHeight}
                fill="transparent"
              />
              {keys.map((key) => {
                const value = point[key]

                if (value === 0) return null

                const segment = (value / geometry.max) * geometry.plotHeight
                cursor -= segment

                return (
                  <rect
                    key={key}
                    x={x}
                    y={cursor}
                    width={geometry.barWidth}
                    // A 2px surface gap between stacked segments, so the
                    // boundary is a boundary rather than a colour change.
                    height={Math.max(1, segment - 2)}
                    rx={2}
                    fill={color[key]}
                    opacity={hover === null || hover === index ? 1 : 0.45}
                  />
                )
              })}
            </g>
          )
        })}

        {[0, days.length - 1].map((index) => (
          <text
            key={index}
            x={geometry.padding.left + index * geometry.slot + geometry.barWidth / 2}
            y={height - 5}
            textAnchor={index === 0 ? 'start' : 'end'}
            className="fill-[var(--color-ink-muted)] text-[0.8125rem]"
          >
            {day(days[index].date)}
          </text>
        ))}
      </svg>

      {active && (
        <div
          className="pointer-events-none absolute top-1"
          style={{
            left: `${((geometry.padding.left + hover! * geometry.slot) / geometry.width) * 100}%`,
            transform: 'translateX(-50%)',
          }}
        >
          <ChartTooltip
            title={day(active.date)}
            rows={keys.map((key) => ({
              label: label(key),
              value: active[key],
              color: color[key],
            }))}
          />
        </div>
      )}
    </div>
  )
}
