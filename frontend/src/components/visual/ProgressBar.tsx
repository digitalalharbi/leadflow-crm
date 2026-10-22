import { clampPercent } from './RingProgress'
import { TONE_COLOR, type Tone } from './tones'
import { cn } from '@/lib/cn'

/** A thin bar for "how far", in a row where a ring would be too much. */
export function ProgressBar({
  value,
  tone = 'good',
  label,
  className,
}: {
  value: number
  tone?: Tone
  label?: string
  className?: string
}) {
  const percent = clampPercent(value)

  return (
    <div
      className={cn('h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-chrome-fill)]', className)}
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.round(percent)}
      aria-label={label}
    >
      <span
        className="block h-full rounded-full"
        data-chart-mark
        style={{
          width: `${percent}%`,
          background: TONE_COLOR[tone],
          transition: 'width var(--duration-slow) var(--ease-out-soft)',
        }}
      />
    </div>
  )
}
