import type { ReactNode } from 'react'
import { TONE_COLOR, type Tone } from './tones'

/** Clamped, and never NaN: a ring handed a bad number draws empty, not broken. */
export function clampPercent(value: number): number {
  if (!Number.isFinite(value)) return 0

  return Math.min(100, Math.max(0, value))
}

/**
 * One number as a ring: how far along, how healthy, how full.
 *
 * For a single proportion — a project's health, a queue worked through, the
 * share of integrations that are live. The centre takes whatever the caller
 * wants to say; by default the percentage itself.
 */
export function RingProgress({
  value,
  size = 72,
  thickness = 8,
  tone = 'good',
  children,
  label,
  testId,
}: {
  value: number
  size?: number
  thickness?: number
  tone?: Tone
  children?: ReactNode
  /** What the ring measures, for a screen reader. */
  label?: string
  testId?: string
}) {
  const percent = clampPercent(value)
  const radius = (size - thickness) / 2
  const circumference = 2 * Math.PI * radius

  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} data-testid={testId}>
      <svg
        viewBox={`0 0 ${size} ${size}`}
        width={size}
        height={size}
        className="-rotate-90"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(percent)}
        aria-label={label}
      >
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--color-chrome-fill)" strokeWidth={thickness} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={TONE_COLOR[tone]}
          strokeWidth={thickness}
          strokeLinecap="round"
          strokeDasharray={`${(percent / 100) * circumference} ${circumference}`}
          data-chart-mark
          style={{ transition: 'stroke-dasharray var(--duration-slow) var(--ease-out-soft)' }}
        />
      </svg>

      <div className="absolute inset-0 grid place-items-center text-center" dir="ltr">
        {children ?? <span className="text-[1.0625rem] font-bold tabular-nums">{Math.round(percent)}%</span>}
      </div>
    </div>
  )
}
