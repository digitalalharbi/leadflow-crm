import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'
import type { LeadTemperature } from '@/types/api'

/**
 * The score, as a ring rather than a number on its own.
 *
 * A bare "78" tells a rep nothing — out of what, and is that good? The ring
 * gives it a scale at a glance and the temperature gives it a verdict, so the
 * two questions a rep actually has are answered without reading anything.
 *
 * The arc is coloured by temperature rather than by value, so it agrees with
 * the badge beside it even when a workspace has moved its own thresholds.
 */
export function ScoreRing({
  score,
  temperature,
  size = 56,
  onClick,
  testId,
}: {
  score: number
  temperature: LeadTemperature
  size?: number
  onClick?: () => void
  testId?: string
}) {
  const { t } = useTranslation()

  const stroke = 5
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const filled = Math.max(0, Math.min(100, score)) / 100

  const color = {
    hot: 'var(--color-temp-hot)',
    warm: 'var(--color-temp-warm)',
    cold: 'var(--color-temp-cold)',
  }[temperature]

  const content = (
    <>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden>
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--color-surface-sunken)"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - filled)}
          // Start at twelve o'clock and run clockwise in both directions: a
          // progress ring that mirrors in RTL reads as counting down.
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
          className="transition-[stroke-dashoffset] duration-[var(--duration-slow)]"
        />
      </svg>

      <span className="absolute inset-0 grid place-items-center">
        <span className="tabular text-[0.9375rem] leading-none font-semibold" dir="ltr">
          {score}
        </span>
      </span>
    </>
  )

  const className = cn(
    'relative shrink-0',
    onClick && 'rounded-full transition-transform hover:scale-105 active:scale-100',
  )

  return onClick ? (
    <button
      type="button"
      onClick={onClick}
      className={className}
      title={t('qualification.scoreTitle')}
      aria-label={`${t('leads.score')}: ${score}`}
      data-testid={testId}
    >
      {content}
    </button>
  ) : (
    <span className={className} data-testid={testId}>
      {content}
    </span>
  )
}
