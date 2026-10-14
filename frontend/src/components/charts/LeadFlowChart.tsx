import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChartTooltip } from './ChartTooltip'
import { SERIES } from './palette'
import { useChartWidth } from './useChartWidth'
import { formatNumber } from '@/lib/format'

export interface TimelinePoint {
  date: string
  leads: number
  qualified: number
  won: number
}

type Key = 'leads' | 'qualified' | 'won'

const KEYS: Key[] = ['leads', 'qualified', 'won']

/**
 * What the month actually looked like.
 *
 * The product could show a funnel, a conversion rate and a dozen tiles, and
 * still not answer the first question a sales manager asks: is this getting
 * better or worse? A single number cannot — 43 leads is good or terrible
 * depending entirely on last week.
 *
 * Three series on one axis because all three are counts of leads. Two scales on
 * one plot would let the shapes be arranged to tell any story you like, which
 * is why this has one.
 *
 * The time axis runs left-to-right inside an RTL page, like the numbers
 * themselves: Arabic readers read a chart's time axis the way everyone does,
 * and mirroring it makes "rising" look like "falling".
 */
export function LeadFlowChart({
  points,
  height = 240,
}: {
  points: TimelinePoint[]
  height?: number
}) {
  const { t, i18n } = useTranslation()
  const [hover, setHover] = useState<number | null>(null)
  const { ref, width } = useChartWidth()

  const color: Record<Key, string> = {
    leads: SERIES[0],
    qualified: SERIES[1],
    won: SERIES[3],
  }

  const geometry = useMemo(() => {
    const padding = { top: 12, right: 12, bottom: 24, left: 34 }
    const plotWidth = width - padding.left - padding.right
    const plotHeight = height - padding.top - padding.bottom

    // Never zero: a flat line at the bottom of an empty chart is still a line,
    // and dividing by a zero maximum puts every point at infinity.
    const max = Math.max(1, ...points.flatMap((p) => KEYS.map((k) => p[k])))

    const x = (index: number) =>
      padding.left + (points.length <= 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth)
    const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight

    const path = (key: Key) =>
      points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(p[key]).toFixed(1)}`).join(' ')

    return { width, padding, plotWidth, plotHeight, max, x, y, path }
  }, [points, height, width])

  if (points.length === 0) {
    return null
  }

  const active = hover === null ? null : points[hover]

  /** Four gridlines, including zero — enough to judge a height against. */
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => Math.round(geometry.max * fraction))

  return (
    <div className="relative" dir="ltr" ref={ref}>
      <svg
        viewBox={`0 0 ${geometry.width} ${height}`}
        className="w-full"
        style={{ height }}
        role="img"
        aria-label={t('charts.leadFlow.aria')}
        onMouseLeave={() => setHover(null)}
      >
        {/* Recessive: the data is the subject, the grid is the paper. */}
        {ticks.map((value) => (
          <g key={value}>
            <line
              x1={geometry.padding.left}
              x2={geometry.width - geometry.padding.right}
              y1={geometry.y(value)}
              y2={geometry.y(value)}
              stroke="var(--color-line)"
              strokeWidth={1}
            />
            <text
              x={geometry.padding.left - 6}
              y={geometry.y(value) + 3}
              textAnchor="end"
              className="fill-[var(--color-ink-muted)] text-[10px] tabular-nums"
            >
              {value}
            </text>
          </g>
        ))}

        {KEYS.map((key) => (
          <path
            key={key}
            d={geometry.path(key)}
            fill="none"
            stroke={color[key]}
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        ))}

        {/* The crosshair, and a marker per series on the hovered day. */}
        {hover !== null && (
          <g>
            <line
              x1={geometry.x(hover)}
              x2={geometry.x(hover)}
              y1={geometry.padding.top}
              y2={height - geometry.padding.bottom}
              stroke="var(--color-border-strong)"
              strokeWidth={1}
            />
            {KEYS.map((key) => (
              <circle
                key={key}
                cx={geometry.x(hover)}
                cy={geometry.y(points[hover][key])}
                r={4}
                fill={color[key]}
                // A 2px surface ring, so overlapping markers stay countable.
                stroke="var(--color-surface)"
                strokeWidth={2}
              />
            ))}
          </g>
        )}

        {/*
          Invisible columns rather than hit-testing the lines: a 2px stroke is
          an impossible target, and a chart you have to aim at is a chart people
          stop using.
        */}
        {points.map((point, index) => (
          <rect
            key={point.date}
            x={geometry.x(index) - geometry.plotWidth / Math.max(points.length, 1) / 2}
            y={geometry.padding.top}
            width={geometry.plotWidth / Math.max(points.length, 1)}
            height={geometry.plotHeight}
            fill="transparent"
            onMouseEnter={() => setHover(index)}
          />
        ))}

        {/* First and last day only — a label per day is a solid grey bar. */}
        {[0, points.length - 1].map((index) => (
          <text
            key={index}
            x={geometry.x(index)}
            y={height - 6}
            textAnchor={index === 0 ? 'start' : 'end'}
            className="fill-[var(--color-ink-muted)] text-[10px]"
          >
            {new Date(points[index].date).toLocaleDateString(i18n.language, {
              day: 'numeric',
              month: 'short',
            })}
          </text>
        ))}
      </svg>

      {active && (
        <div
          className="pointer-events-none absolute top-2"
          style={{
            left: `${(geometry.x(hover!) / geometry.width) * 100}%`,
            transform: 'translateX(-50%)',
          }}
        >
          <ChartTooltip
            title={new Date(active.date).toLocaleDateString(i18n.language, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
            })}
            rows={KEYS.map((key) => ({
              label: t(`charts.leadFlow.${key}`),
              value: formatNumber(active[key], i18n.language),
              color: color[key],
            }))}
          />
        </div>
      )}
    </div>
  )
}
