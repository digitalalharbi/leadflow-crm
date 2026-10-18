import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChartTooltip } from './ChartTooltip'
import { useChartWidth } from './useChartWidth'
import { formatNumber } from '@/lib/format'

export interface TimelinePoint {
  date: string
  leads: number
  qualified: number
  won: number
}

export type FlowSeries = 'leads' | 'qualified' | 'won'

export const FLOW_SERIES: FlowSeries[] = ['leads', 'qualified', 'won']

/** Days per bar. Thirty days in six bars: few enough to be shapes, many enough to be a trend. */
const BUCKET_DAYS = 5

interface Bucket {
  /** First day of the bucket, for the axis. */
  date: string
  leads: number
  qualified: number
  won: number
}

/** Daily points into fat bars, summed — counts add, so nothing is invented. */
function bucketise(points: TimelinePoint[]): Bucket[] {
  const buckets: Bucket[] = []

  for (let i = 0; i < points.length; i += BUCKET_DAYS) {
    const slice = points.slice(i, i + BUCKET_DAYS)

    buckets.push({
      date: slice[0].date,
      leads: slice.reduce((sum, p) => sum + p.leads, 0),
      qualified: slice.reduce((sum, p) => sum + p.qualified, 0),
      won: slice.reduce((sum, p) => sum + p.won, 0),
    })
  }

  return buckets
}

/** A round axis ceiling, so the gridlines land on numbers a person would say. */
function niceMax(value: number): number {
  if (value <= 5) return 5

  const magnitude = 10 ** Math.floor(Math.log10(value))
  const normalised = value / magnitude

  return (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) * magnitude
}

/**
 * What the month looked like, as the reference draws it: a few fat rounded
 * bars on dashed gridlines, one of them darker with its change floating above.
 *
 * Bars rather than the line it replaced because the question is "is this
 * getting better", and a reader answers that by comparing heights, which bars
 * make easy and a 30-point line makes a squint. The price is resolution, paid
 * knowingly: thirty days become six five-day totals here, and the table behind
 * the card still has all thirty.
 *
 * One series at a time, chosen by the caller. The three are all counts of leads
 * but they are different sizes by construction — won is a sliver of leads — and
 * on one axis the sliver is invisible. Switching is a pill, and every series is
 * still named, so colour never carries identity alone.
 *
 * The highlighted bar is the tallest, not a selection: the chart says where the
 * peak was before anyone points at anything. Hovering a column names the five
 * days and all three figures.
 *
 * The time axis runs left-to-right inside an RTL page, like the numbers
 * themselves: mirrored, a rising month looks like a falling one.
 */
export function LeadFlowChart({
  points,
  series = 'leads',
  height = 240,
}: {
  points: TimelinePoint[]
  series?: FlowSeries
  height?: number
}) {
  const { t, i18n } = useTranslation()
  const [hover, setHover] = useState<number | null>(null)
  const { ref, width } = useChartWidth()

  const buckets = useMemo(() => bucketise(points), [points])

  const geometry = useMemo(() => {
    const padding = { top: 30, right: 8, bottom: 26, left: 32 }
    const plotWidth = width - padding.left - padding.right
    const plotHeight = height - padding.top - padding.bottom
    const max = niceMax(Math.max(1, ...buckets.map((b) => b[series])))
    const slot = plotWidth / Math.max(buckets.length, 1)
    // Fat bars with air between them; never wider than a tidy capsule.
    const barWidth = Math.min(slot * 0.62, 52)

    const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight
    const x = (index: number) => padding.left + slot * index + slot / 2

    return { padding, plotWidth, plotHeight, max, slot, barWidth, x, y }
  }, [buckets, series, height, width])

  if (buckets.length === 0) return null

  const values = buckets.map((b) => b[series])
  const peak = values.indexOf(Math.max(...values))
  const active = hover === null ? null : buckets[hover]

  // The change into the peak, from the bucket before it: the figure the
  // reference floats over its dark bar.
  const peakChange =
    peak > 0 && values[peak - 1] > 0 ? Math.round(((values[peak] - values[peak - 1]) / values[peak - 1]) * 100) : null

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(geometry.max * f))
  const dayLabel = (date: string) =>
    new Date(date).toLocaleDateString(i18n.language, { day: 'numeric', month: 'short' })

  return (
    <div className="relative" dir="ltr" ref={ref}>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        className="w-full"
        style={{ height }}
        role="img"
        aria-label={t('charts.leadFlow.aria')}
        onMouseLeave={() => setHover(null)}
      >
        <defs>
          {/* The reference's diagonal hatching: a texture, not a gradient. */}
          <pattern id="flow-hatch" patternUnits="userSpaceOnUse" width="7" height="7" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="7" stroke="var(--brand-deep)" strokeWidth="1.4" opacity="0.22" />
          </pattern>
        </defs>

        {/* Dashed and recessive: the data is the subject, the grid is the paper. */}
        {ticks.map((value) => (
          <g key={value}>
            <line
              x1={geometry.padding.left}
              x2={width - geometry.padding.right}
              y1={geometry.y(value)}
              y2={geometry.y(value)}
              stroke="var(--color-border-strong)"
              strokeWidth={1}
              strokeDasharray="4 5"
            />
            <text
              x={geometry.padding.left - 8}
              y={geometry.y(value) + 3.5}
              textAnchor="end"
              className="fill-[var(--color-ink-muted)] text-[10px] tabular-nums"
            >
              {value}
            </text>
          </g>
        ))}

        {buckets.map((bucket, index) => {
          const value = bucket[series]
          const top = geometry.y(value)
          const barHeight = Math.max(geometry.y(0) - top, value > 0 ? geometry.barWidth : 0)
          const isPeak = index === peak && value > 0
          const left = geometry.x(index) - geometry.barWidth / 2

          return (
            <g key={bucket.date} data-chart-bar={isPeak ? 'peak' : undefined}>
              {value > 0 && (
                <>
                  <rect
                    x={left}
                    y={geometry.y(0) - barHeight}
                    width={geometry.barWidth}
                    height={barHeight}
                    rx={geometry.barWidth / 2}
                    fill={isPeak ? 'var(--brand-deep)' : 'var(--brand-tint)'}
                    opacity={hover === null || hover === index ? 1 : 0.7}
                  />
                  <rect
                    x={left}
                    y={geometry.y(0) - barHeight}
                    width={geometry.barWidth}
                    height={barHeight}
                    rx={geometry.barWidth / 2}
                    fill="url(#flow-hatch)"
                  />
                </>
              )}

              {/* The peak, announced: a dot on the bar and the change into it. */}
              {isPeak && (
                <g>
                  <circle cx={geometry.x(index)} cy={geometry.y(0) - barHeight} r={6} fill="var(--brand-deep)" stroke="var(--color-surface)" strokeWidth={3} />
                  {peakChange !== null && (
                    <g transform={`translate(${geometry.x(index)} ${geometry.y(0) - barHeight - 18})`}>
                      <rect x={-27} y={-12} width={54} height={22} rx={11} fill="var(--brand)" />
                      <text textAnchor="middle" y={3} className="fill-white text-[11px] font-medium tabular-nums">
                        {peakChange > 0 ? '+' : ''}
                        {peakChange}%
                      </text>
                    </g>
                  )}
                </g>
              )}

              <text
                x={geometry.x(index)}
                y={height - 8}
                textAnchor="middle"
                className="fill-[var(--color-ink-muted)] text-[10.5px]"
              >
                {dayLabel(bucket.date)}
              </text>
            </g>
          )
        })}

        {/* Full-height columns: a bar is a fine thing to look at and a poor target. */}
        {buckets.map((bucket, index) => (
          <rect
            key={`hit-${bucket.date}`}
            x={geometry.x(index) - geometry.slot / 2}
            y={geometry.padding.top - 10}
            width={geometry.slot}
            height={geometry.plotHeight + 10}
            fill="transparent"
            onMouseEnter={() => setHover(index)}
          />
        ))}
      </svg>

      {/* What the bars are, with a swatch that is a real background so it survives being printed. */}
      <p className="mt-1 flex items-center justify-center gap-1.5 text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">
        <span
          data-chart-mark
          className="size-2.5 rounded-full bg-[var(--brand-deep)] [print-color-adjust:exact]"
          style={{ printColorAdjust: 'exact', WebkitPrintColorAdjust: 'exact' }}
          aria-hidden
        />
        {t(`charts.leadFlow.${series}`)}
      </p>

      {active && (
        <div
          className="pointer-events-none absolute top-2"
          style={{
            left: `${(geometry.x(hover!) / width) * 100}%`,
            transform: 'translateX(-50%)',
          }}
        >
          <ChartTooltip
            title={`${dayLabel(active.date)} – ${dayLabel(
              new Date(new Date(active.date).getTime() + (BUCKET_DAYS - 1) * 86_400_000).toISOString(),
            )}`}
            rows={FLOW_SERIES.map((key) => ({
              label: t(`charts.leadFlow.${key}`),
              value: formatNumber(active[key], i18n.language),
              emphasis: key === series,
            }))}
          />
        </div>
      )}
    </div>
  )
}
