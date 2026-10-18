import { useState } from 'react'
import { Link } from 'react-router-dom'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

export interface DonutSegment {
  key: string
  label: string
  value: number
  color: string
  /** Where pressing this segment's legend row goes. */
  href?: string
}

export interface Arc {
  key: string
  /** Fraction of the whole, 0–1. */
  fraction: number
  /** Where it starts, as a fraction of the circle. */
  start: number
}

/**
 * Segment values to arcs. Pure, so the arithmetic is testable without a DOM.
 *
 * Zero and negative values are dropped: a ring cannot draw "minus three", and a
 * zero-width arc is a stray dot. An empty or all-zero set yields no arcs, which
 * the component turns into an honest empty ring rather than a full one.
 */
export function arcs(segments: Pick<DonutSegment, 'key' | 'value'>[]): Arc[] {
  const usable = segments.filter((segment) => Number.isFinite(segment.value) && segment.value > 0)
  const total = usable.reduce((sum, segment) => sum + segment.value, 0)

  if (total <= 0) return []

  let cursor = 0

  return usable.map((segment) => {
    const fraction = segment.value / total
    const arc = { key: segment.key, fraction, start: cursor }
    cursor += fraction

    return arc
  })
}

/**
 * A ring for a distribution: statuses, sources, workload, health.
 *
 * The centre carries the one number that matters (the total, or whatever the
 * caller says) and the legend carries the names, so the ring itself never needs
 * a label squeezed onto an arc. Hovering or focusing a legend row lifts its
 * arc and puts that segment's figure in the centre — the answer to "how many
 * is that slice" without a tooltip to aim at.
 *
 * Identity is never colour alone: every segment is in the legend by name and
 * count, and the ring carries a text alternative that says the same.
 */
export function Donut({
  segments,
  size = 168,
  thickness = 20,
  centerValue,
  centerLabel,
  legend = 'side',
  className,
  testId,
}: {
  segments: DonutSegment[]
  size?: number
  thickness?: number
  /** Replaces the total in the middle. */
  centerValue?: string
  centerLabel?: string
  legend?: 'side' | 'below' | 'none'
  className?: string
  testId?: string
}) {
  const [active, setActive] = useState<string | null>(null)

  const radius = (size - thickness) / 2
  const circumference = 2 * Math.PI * radius
  const total = segments.reduce((sum, segment) => sum + Math.max(0, segment.value), 0)
  const computed = arcs(segments)
  const focused = segments.find((segment) => segment.key === active)

  // A hairline between neighbours, so two shades of one hue do not blur into one.
  const gap = computed.length > 1 ? Math.min(3, circumference * 0.006) : 0

  const shownValue = focused ? formatNumber(focused.value) : (centerValue ?? formatNumber(total))
  const shownLabel = focused ? focused.label : centerLabel

  const summary = segments
    .filter((segment) => segment.value > 0)
    .map((segment) => `${segment.label} ${segment.value}`)
    .join('، ')

  return (
    <div
      className={cn(
        'flex items-center gap-5',
        legend === 'below' && 'flex-col',
        legend === 'none' && 'justify-center',
        className,
      )}
      data-testid={testId}
    >
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg
          viewBox={`0 0 ${size} ${size}`}
          width={size}
          height={size}
          role="img"
          aria-label={summary || '—'}
          className="-rotate-90"
        >
          <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--color-chrome-fill)" strokeWidth={thickness} />

          {computed.map((arc) => {
            const segment = segments.find((s) => s.key === arc.key)!
            const length = Math.max(0, arc.fraction * circumference - gap)
            const dimmed = active !== null && active !== arc.key

            return (
              <circle
                key={arc.key}
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke={segment.color}
                strokeWidth={active === arc.key ? thickness + 3 : thickness}
                strokeDasharray={`${length} ${circumference - length}`}
                strokeDashoffset={-arc.start * circumference}
                opacity={dimmed ? 0.35 : 1}
                data-chart-mark
                style={{ transition: 'opacity var(--duration-fast), stroke-width var(--duration-fast)' }}
              />
            )
          })}
        </svg>

        <div className="pointer-events-none absolute inset-0 grid place-content-center text-center" dir="ltr">
          <span className="text-[1.75rem] font-bold leading-none tabular-nums">{shownValue}</span>
          {shownLabel && (
            <span className="mt-1 max-w-[7rem] truncate text-[0.6875rem] text-[var(--color-ink-muted)]">{shownLabel}</span>
          )}
        </div>
      </div>

      {legend !== 'none' && (
        <ul className="min-w-0 flex-1 space-y-1.5" data-testid={testId ? `${testId}-legend` : undefined}>
          {segments.map((segment) => {
            const content = (
              <>
                <span aria-hidden className="size-2.5 shrink-0 rounded-full" style={{ background: segment.color }} />
                <span className="min-w-0 flex-1 truncate text-[0.8125rem]">{segment.label}</span>
                <span className="text-[0.8125rem] font-semibold tabular-nums">{formatNumber(segment.value)}</span>
              </>
            )
            const row = cn(
              'flex items-center gap-2 rounded-full px-2 py-0.5',
              segment.href && 'hover:bg-[var(--color-chrome-fill)]',
            )

            return (
              <li
                key={segment.key}
                onMouseEnter={() => setActive(segment.key)}
                onMouseLeave={() => setActive(null)}
                onFocus={() => setActive(segment.key)}
                onBlur={() => setActive(null)}
              >
                {segment.href ? (
                  <Link to={segment.href} className={row}>
                    {content}
                  </Link>
                ) : (
                  <div className={row}>{content}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
