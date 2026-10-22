import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { humaniseDuration } from './duration'
import { foldSeries, platformColor, SERIES } from './palette'
import { StackedDayChart } from './StackedDayChart'

export interface IngestionDay {
  date: string
  accepted: number
  duplicates: number
}

export interface IngestionSource {
  provider: string
  platform: string | null
  accepted: number
  duplicates: number
  last_received_at: string | null
}

type Key = 'accepted' | 'duplicates'

const KEYS: readonly Key[] = ['accepted', 'duplicates']

/*
 * Duplicates are a measure, not a state, so they take a palette slot rather
 * than a status colour. Calling them "bad" in red is a judgement the product
 * has no basis for: a retrying webhook and a buyer who filled the form twice
 * look identical here, and only one of them is a fault.
 */
const COLOR: Record<Key, string> = {
  accepted: SERIES[0],
  duplicates: SERIES[3],
}

/**
 * Whether leads are still arriving.
 *
 * The list of connected sources above this cannot answer that. A form that
 * stopped posting eleven days ago still shows a green badge and a valid
 * secret; the only evidence of arrival is arrivals.
 *
 * Duplicates are stacked on top rather than netted out. A day of sixty events
 * where fifty were the same payload is a retrying integration, and with the
 * repeats removed it reads exactly like a good day.
 */
export function IngestionChart({ days, height = 200 }: { days: IngestionDay[]; height?: number }) {
  const { t } = useTranslation()

  return (
    <StackedDayChart
      days={days}
      keys={KEYS}
      color={COLOR}
      label={(key) => t(`charts.ingestion.${key}`)}
      aria={t('charts.ingestion.aria')}
      height={height}
    />
  )
}

/**
 * Each source: how much it delivered, and when it was last heard from.
 *
 * The "last heard from" is the column a reader acts on, so it is text rather
 * than a position on a scale. A source with a healthy total and nothing for
 * nine days is the broken one, and the bar — which is a total — cannot say so.
 */
export function IngestionSources({
  sources,
  now = new Date(),
}: {
  sources: IngestionSource[]
  now?: Date
}) {
  const { t, i18n } = useTranslation()

  if (sources.length === 0) {
    return null
  }

  /*
   * Six platforms is one more than the palette has slots, and a generated
   * sixth hue is exactly the colour nobody can tell from the fifth. The tail
   * folds into one labelled bucket that still carries its whole total.
   */
  const folded = foldSeries(sources, {
    key: (source) => `${source.provider}:${source.platform ?? '-'}`,
    label: (source) =>
      source.platform
        ? t(`platforms.${source.platform}`, { defaultValue: source.platform })
        : // The integrations page labels a provider from the same namespace;
          // a second one would drift from it the first time either changed.
          t(`platforms.${source.provider}`, { defaultValue: source.provider }),
    value: (source) => source.accepted + source.duplicates,
    color: (source) => platformColor(source.platform),
    otherLabel: t('charts.ingestion.otherSources'),
  })

  const peak = Math.max(...folded.map((slice) => slice.value), 1)

  return (
    <ul className="space-y-3 px-5 pb-4" data-testid="ingestion-sources">
      {folded.map((slice) => {
        const last = slice.members
          .map((source) => source.last_received_at)
          .filter((value): value is string => value !== null)
          .toSorted()
          .at(-1)

        const silentFor =
          last === undefined ? null : Math.max(0, (now.getTime() - Date.parse(last)) / 1000)

        return (
          <li key={slice.key} className="space-y-1">
            <div className="flex items-baseline justify-between gap-3">
              <span className="t-small min-w-0 truncate font-medium">{slice.label}</span>
              <span className="tabular shrink-0 text-[0.9375rem]" dir="ltr">
                {formatNumber(slice.value, i18n.language)}
              </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
              <div
                data-chart-mark
                className="h-full rounded-full"
                style={{
                  width: `${Math.max((slice.value / peak) * 100, slice.value > 0 ? 2 : 0)}%`,
                  background: slice.color,
                }}
              />
            </div>

            <p className="t-caption">
              {silentFor === null
                ? t('charts.ingestion.neverReceived')
                : t('charts.ingestion.lastReceived', {
                    duration: humaniseDuration(silentFor, t),
                  })}
            </p>
          </li>
        )
      })}
    </ul>
  )
}
