import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { ChartLegend, type LegendItem } from './ChartLegend'
import { ChartTooltip } from './ChartTooltip'
import { cn } from '@/lib/cn'
import type { CampaignQualityRow } from '@/types/api'

const MEASURES = ['leads', 'qualified', 'appointments', 'won'] as const
type Measure = (typeof MEASURES)[number]

/**
 * Each advertising platform as a group of bars, one per funnel measure.
 *
 * Grouped rather than stacked: stacking would make the segments share a
 * baseline only at the bottom, and the comparison everyone wants here is
 * "which platform produced more qualified leads?" — which needs bars that
 * start from the same line.
 *
 * The legend is a set of switches, because the fastest way to answer "what
 * does this look like without the raw lead counts?" is to turn them off.
 */
export function PlatformPerformanceChart({ rows }: { rows: CampaignQualityRow[] }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [hidden, setHidden] = useState<Set<string>>(new Set())
  const [hovered, setHovered] = useState<string | null>(null)

  const colors: Record<Measure, string> = {
    leads: 'var(--chart-2)',
    qualified: 'var(--chart-1)',
    appointments: 'var(--chart-4)',
    won: 'var(--color-success)',
  }

  // One row per platform, summed across its campaigns.
  const platforms = useMemo(() => {
    const totals = new Map<string, Record<Measure, number>>()

    for (const row of rows) {
      const current = totals.get(row.platform) ?? { leads: 0, qualified: 0, appointments: 0, won: 0 }

      current.leads += row.leads
      current.qualified += row.qualified
      current.appointments += row.appointments
      current.won += row.won
      totals.set(row.platform, current)
    }

    return [...totals.entries()]
      .map(([platform, measures]) => ({ platform, ...measures }))
      .toSorted((a, b) => b.leads - a.leads)
      .slice(0, 6)
  }, [rows])

  const visible = MEASURES.filter((measure) => !hidden.has(measure))
  const peak = Math.max(
    ...platforms.flatMap((row) => visible.map((measure) => row[measure])),
    1,
  )

  const legend: LegendItem[] = MEASURES.map((measure) => ({
    key: measure,
    label: t(`campaignQuality.${measure}`),
    color: colors[measure],
  }))

  const toggle = (key: string) =>
    setHidden((current) => {
      const next = new Set(current)
      // Never let the last series be switched off: an empty chart is not an
      // answer, it is a bug report.
      if (next.has(key)) next.delete(key)
      else if (next.size < MEASURES.length - 1) next.add(key)

      return next
    })

  return (
    <div data-testid="platform-performance">
      <ChartLegend items={legend} hidden={hidden} onToggle={toggle} className="px-3 pb-3" />

      <div className="space-y-3 px-3 pb-2">
        {platforms.map((row) => (
          <div
            key={row.platform}
            className="relative"
            onMouseEnter={() => setHovered(row.platform)}
            onMouseLeave={() => setHovered(null)}
          >
            <button
              type="button"
              onClick={() => navigate(`/leads?platform[]=${encodeURIComponent(row.platform)}`)}
              data-testid={`platform-${row.platform}`}
              className={cn(
                'block w-full rounded-[var(--radius-control)] px-2 py-1.5 text-start transition-colors',
                'hover:bg-[var(--color-surface-sunken)]',
              )}
            >
              <span className="t-small mb-1.5 block font-medium">
                {t(`platforms.${row.platform}`, { defaultValue: row.platform })}
              </span>

              <span className="flex flex-col gap-1">
                {visible.map((measure) => (
                  <span key={measure} className="flex items-center gap-2">
                    <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                      <span
                        className="block h-full rounded-full transition-[width] duration-[var(--duration-slow)]"
                        style={{
                          width: `${Math.max((row[measure] / peak) * 100, row[measure] > 0 ? 2 : 0)}%`,
                          background: colors[measure],
                        }}
                      />
                    </span>
                    <span className="tabular w-10 shrink-0 text-end text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
                      {formatNumber(row[measure], i18n.language)}
                    </span>
                  </span>
                ))}
              </span>
            </button>

            {hovered === row.platform && (
              <div className="absolute end-2 top-full z-20 -mt-1">
                <ChartTooltip
                  title={t(`platforms.${row.platform}`, { defaultValue: row.platform })}
                  rows={MEASURES.map((measure) => ({
                    label: t(`campaignQuality.${measure}`),
                    value: formatNumber(row[measure], i18n.language),
                    color: colors[measure],
                    emphasis: measure === 'qualified',
                  }))}
                  footer={
                    row.leads > 0
                      ? `${t('campaignQuality.qualificationRate')} ${((row.qualified / row.leads) * 100).toFixed(1)}%`
                      : undefined
                  }
                />
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
