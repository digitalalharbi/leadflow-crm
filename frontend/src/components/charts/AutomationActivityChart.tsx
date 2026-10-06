import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChartTooltip } from './ChartTooltip'
import { SERIES } from './palette'
import { useChartWidth } from './useChartWidth'

export interface AutomationDay {
  date: string
  applied: number
  skipped: number
  failed: number
}

type Key = 'applied' | 'skipped' | 'failed'

const KEYS: Key[] = ['applied', 'skipped', 'failed']

/**
 * What the rules did, day by day.
 *
 * Stacked rather than three lines, because the question is "how much ran, and
 * how much of it did anything" — a proportion, which a stack shows and three
 * separate lines make you compute.
 *
 * Skips are drawn, not hidden. A week of two hundred skipped runs and no
 * applications is a workspace whose conditions stopped matching its leads, and
 * on a chart of successes alone that is indistinguishable from a quiet week.
 * Failures get the last slot and the warning colour because they are the only
 * part anybody needs to act on today.
 */
export function AutomationActivityChart({
  days,
  height = 200,
}: {
  days: AutomationDay[]
  height?: number
}) {
  const { t, i18n } = useTranslation()
  const [hover, setHover] = useState<number | null>(null)
  const { ref, width } = useChartWidth()

  const color: Record<Key, string> = {
    applied: SERIES[0],
    skipped: SERIES[1],
    failed: 'var(--color-status-lost)',
  }

  const geometry = useMemo(() => {
    const padding = { top: 10, right: 8, bottom: 20, left: 28 }
    const plotHeight = height - padding.top - padding.bottom
    const plotWidth = width - padding.left - padding.right
    const max = Math.max(1, ...days.map((d) => d.applied + d.skipped + d.failed))
    const slot = plotWidth / Math.max(days.length, 1)
    // A 2px gap between bars, and never a negative width on a crowded range.
    const barWidth = Math.max(2, slot - 2)

    return { width, padding, plotHeight, plotWidth, max, slot, barWidth }
  }, [days, height, width])

  if (days.length === 0) {
    return null
  }

  const active = hover === null ? null : days[hover]

  return (
    <div className="relative" dir="ltr" ref={ref}>
      <svg
        viewBox={`0 0 ${geometry.width} ${height}`}
        className="w-full"
        style={{ height }}
        role="img"
        aria-label={t('charts.automation.aria')}
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
          className="fill-[var(--color-ink-subtle)] text-[9px] tabular-nums"
        >
          {geometry.max}
        </text>

        {days.map((day, index) => {
          const x = geometry.padding.left + index * geometry.slot + 1
          let cursor = height - geometry.padding.bottom

          return (
            <g key={day.date} onMouseEnter={() => setHover(index)}>
              {/* Full-height target, so a one-lead day is still hoverable. */}
              <rect
                x={geometry.padding.left + index * geometry.slot}
                y={geometry.padding.top}
                width={geometry.slot}
                height={geometry.plotHeight}
                fill="transparent"
              />
              {KEYS.map((key) => {
                const value = day[key]

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
            className="fill-[var(--color-ink-subtle)] text-[9px]"
          >
            {new Date(days[index].date).toLocaleDateString(i18n.language, {
              day: 'numeric',
              month: 'short',
            })}
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
            title={new Date(active.date).toLocaleDateString(i18n.language, {
              day: 'numeric',
              month: 'short',
            })}
            rows={KEYS.map((key) => ({
              label: t(`charts.automation.${key}`),
              value: active[key],
              color: color[key],
            }))}
          />
        </div>
      )}
    </div>
  )
}
