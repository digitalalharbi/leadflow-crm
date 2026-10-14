import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

export interface Segment {
  key: string
  label: string
  count: number
  share: number
  color: string
  to?: string
}

/**
 * A proportional bar with its parts named underneath.
 *
 * Used instead of a pie or a donut wherever the question is "how is this split?".
 * A pie makes a reader compare angles, which nobody does accurately; a single
 * bar makes them compare lengths, which everyone does — and it costs a quarter
 * of the vertical space, which is why the dashboard can afford to show it next
 * to something else.
 */
export function DistributionBar({
  segments,
  testId,
}: {
  segments: Segment[]
  testId?: string
}) {
  const { i18n } = useTranslation()
  const total = segments.reduce((sum, segment) => sum + segment.count, 0)

  if (total === 0) {
    return null
  }

  return (
    <div className="space-y-4 px-3 py-2" data-testid={testId}>
      <div className="flex h-3 w-full overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
        {segments.map((segment) => (
          <span
            key={segment.key}
            data-chart-mark
            className="h-full transition-[width] duration-[var(--duration-slow)] first:rounded-s-full last:rounded-e-full"
            style={{ width: `${segment.share}%`, background: segment.color }}
            title={`${segment.label}: ${segment.count}`}
          />
        ))}
      </div>

      <ul className="grid gap-2 sm:grid-cols-3">
        {segments.map((segment) => {
          const body = (
            <>
              <span className="flex items-center gap-1.5">
                <span
                  className="size-2 shrink-0 rounded-[2px]"
                  style={{ background: segment.color }}
                  aria-hidden
                />
                <span className="t-caption truncate">{segment.label}</span>
              </span>
              <span className="mt-1 flex items-baseline gap-1.5">
                <span className="t-metric-sm">{formatNumber(segment.count, i18n.language)}</span>
                <span className="tabular text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
                  {segment.share}%
                </span>
              </span>
            </>
          )

          return (
            <li key={segment.key}>
              {segment.to ? (
                <Link
                  to={segment.to}
                  className={cn(
                    'block rounded-[var(--radius-control)] px-2 py-1.5 transition-colors',
                    'hover:bg-[var(--color-surface-sunken)]',
                  )}
                  data-testid={`segment-${segment.key}`}
                >
                  {body}
                </Link>
              ) : (
                <span className="block px-2 py-1.5">{body}</span>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
