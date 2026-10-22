import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { SERIES } from './palette'

export interface DriverRow {
  /** The band's key: a speed bucket or a score band. */
  key: string
  leads: number
  won: number
  win_rate: number | null
}

/**
 * A behaviour on one axis, the outcome on the other.
 *
 * Every other chart in the product measures effort — how fast the team called,
 * what the scoring engine thought, how many leads a platform sent. None of
 * them says whether the effort paid, and a workspace cannot act on a speed
 * figure until it knows speed is worth paying for.
 *
 * One hue, not one per band. These are five slices of a single variable, and
 * the order is already carried by position; a colour per band would imply five
 * unrelated things.
 *
 * The sample size sits under every bar because a win rate over four leads is
 * an anecdote, and the bands at the extremes are always the smallest.
 */
export function ConversionDriversChart({
  rows,
  label,
  testId,
}: {
  rows: DriverRow[]
  /** The human name of a band. */
  label: (key: string) => string
  testId: string
}) {
  const { t, i18n } = useTranslation()

  const measured = rows.filter((row) => row.win_rate !== null)

  if (measured.length === 0) {
    return null
  }

  /*
   * Scaled against the best band rather than against 100.
   *
   * Win rates in this product live between two and twenty per cent, and
   * against a fixed hundred every bar is a stub and the difference the chart
   * exists to show disappears. The axis maximum is stated so nobody reads the
   * longest bar as "most of them".
   */
  const peak = Math.max(...measured.map((row) => row.win_rate ?? 0), 1)

  return (
    <div className="space-y-3 px-5 pb-2" data-testid={testId}>
      {rows.map((row) => (
        <div key={row.key} className="space-y-1">
          <div className="flex items-baseline justify-between gap-3">
            <span className="t-small min-w-0 truncate">{label(row.key)}</span>
            <span className="tabular shrink-0 text-[0.9375rem] font-medium" dir="ltr">
              {row.win_rate === null ? '—' : `${row.win_rate}%`}
            </span>
          </div>

          <div className="h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
            {row.win_rate !== null && (
              <div
                data-chart-mark
                className="h-full rounded-full transition-[width] duration-[var(--duration-slow)]"
                style={{
                  width: `${Math.max((row.win_rate / peak) * 100, row.win_rate > 0 ? 2 : 0)}%`,
                  background: SERIES[0],
                }}
              />
            )}
          </div>

          <p className="t-caption">
            {row.leads === 0
              ? t('charts.drivers.noLeads')
              : t('charts.drivers.sample', { count: row.leads })}
          </p>
        </div>
      ))}

      <p className="t-caption pt-1">
        {t('charts.drivers.scale', { peak: formatNumber(Math.round(peak), i18n.language) })}
      </p>
    </div>
  )
}
