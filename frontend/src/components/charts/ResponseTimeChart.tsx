import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

export interface ResponseTime {
  buckets: Array<{ key: string; count: number; share: number }>
  contacted: number
  uncontacted: number
  average_seconds: number | null
  median_seconds: number | null
}

/**
 * How fast the team answers, as the shape of the distribution.
 *
 * The headline is the median rather than the mean: one lead answered five
 * hours late drags an average past an hour while most were answered in
 * minutes, and the average is the number that makes a good team look bad and
 * a bad one look consistent.
 *
 * Buckets run fast-to-slow and are coloured by how good they are, so the shape
 * carries the judgement without needing a legend.
 */
export function ResponseTimeChart({ data }: { data: ResponseTime }) {
  const { t, i18n } = useTranslation()

  const tone: Record<string, string> = {
    under_5: 'var(--color-success)',
    under_15: 'var(--chart-1)',
    under_30: 'var(--color-warning)',
    over_30: 'var(--color-danger)',
  }

  const peak = Math.max(...data.buckets.map((b) => b.count), 1)

  return (
    <div className="space-y-4 px-3 py-2" data-testid="response-time">
      <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
        <span className="flex items-baseline gap-2">
          <span className="t-metric" data-testid="response-median">
            {formatDuration(data.median_seconds, t)}
          </span>
          <span className="t-caption">{t('response.median')}</span>
        </span>

        {data.average_seconds !== null && (
          <span className="t-caption">
            {t('response.average')} {formatDuration(data.average_seconds, t)}
          </span>
        )}
      </div>

      <ul className="space-y-2">
        {data.buckets.map((bucket) => (
          <li key={bucket.key} className="flex items-center gap-3">
            <span className="t-caption w-20 shrink-0 text-end">{t(`response.${bucket.key}`)}</span>

            <span className="h-5 min-w-0 flex-1 overflow-hidden rounded-[0.3rem] bg-[var(--color-surface-sunken)]">
              <span
                className="block h-full rounded-[0.3rem] transition-[width] duration-[var(--duration-slow)]"
                style={{
                  width: `${Math.max((bucket.count / peak) * 100, bucket.count > 0 ? 3 : 0)}%`,
                  background: tone[bucket.key],
                }}
              />
            </span>

            <span className="tabular w-16 shrink-0 text-[0.8125rem]" dir="ltr">
              {formatNumber(bucket.count, i18n.language)}
              <span className="ms-1 text-[var(--color-ink-subtle)]">{bucket.share}%</span>
            </span>
          </li>
        ))}
      </ul>

      {data.uncontacted > 0 && (
        <p
          className={cn('notice notice-warning')}
          data-testid="response-uncontacted"
        >
          {t('response.uncontacted', { count: data.uncontacted })}
        </p>
      )}
    </div>
  )
}

/** Seconds as something a person says out loud. */
export function formatDuration(seconds: number | null, t: (key: string) => string): string {
  if (seconds === null) return '—'
  if (seconds < 90) return `${Math.round(seconds)} ${t('units.seconds')}`
  if (seconds < 5400) return `${Math.round(seconds / 60)} ${t('units.minutes')}`
  if (seconds < 172_800) return `${Math.round(seconds / 3600)} ${t('units.hours')}`

  return `${Math.round(seconds / 86_400)} ${t('units.days')}`
}
