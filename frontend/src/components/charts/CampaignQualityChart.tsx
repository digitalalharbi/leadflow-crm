import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { ChartTooltip } from './ChartTooltip'
import { cn } from '@/lib/cn'
import type { CampaignQualityRow } from '@/types/api'

/**
 * Which campaigns bring buyers.
 *
 * The chart is built around the comparison that actually decides where money
 * goes: volume against quality. Each row shows the lead count as a pale track
 * — the spend, in effect — with the qualified share filled in on top of it and
 * the sales marked on the same scale. A campaign with a long track and a short
 * fill is the expensive mistake, and it is visible without reading a number.
 *
 * A table would make a reader compare digits across columns. This makes them
 * compare lengths in one place, which is the whole point.
 */
export function CampaignQualityChart({
  rows,
  limit = 8,
}: {
  rows: CampaignQualityRow[]
  limit?: number
}) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [hovered, setHovered] = useState<string | null>(null)

  const shown = rows.slice(0, limit)
  const peak = Math.max(...shown.map((row) => row.leads), 1)

  return (
    <div className="space-y-1 px-3 py-1" data-testid="campaign-quality-chart">
      {shown.map((row) => {
        const id = `${row.platform}-${row.campaign}`
        const leadWidth = (row.leads / peak) * 100
        const qualifiedWidth = (row.qualified / peak) * 100
        const wonLeft = (row.won / peak) * 100

        return (
          <div
            key={id}
            className="relative"
            onMouseEnter={() => setHovered(id)}
            onMouseLeave={() => setHovered(null)}
          >
            <button
              type="button"
              onClick={() => navigate(`/leads?campaign_name=${encodeURIComponent(row.campaign)}`)}
              data-testid={`campaign-row-${row.campaign}`}
              className={cn(
                'block w-full rounded-[var(--radius-control)] px-2 py-2 text-start transition-colors',
                'hover:bg-[var(--color-surface-sunken)] focus-visible:bg-[var(--color-surface-sunken)]',
              )}
            >
              <span className="mb-1.5 flex items-baseline justify-between gap-3">
                <span className="t-small truncate font-medium">{row.campaign}</span>
                <span className="flex items-baseline gap-2">
                  <span
                    className={cn(
                      'tabular text-[0.75rem] font-semibold',
                      row.qualification_rate >= 30
                        ? 'text-[var(--color-success)]'
                        : row.qualification_rate < 10
                          ? 'text-[var(--color-danger)]'
                          : 'text-[var(--color-ink-muted)]',
                    )}
                    dir="ltr"
                  >
                    {row.qualification_rate}%
                  </span>
                  <span className="tabular text-[0.75rem] text-[var(--color-ink-subtle)]" dir="ltr">
                    {formatNumber(row.leads, i18n.language)}
                  </span>
                </span>
              </span>

              <span className="relative block h-2.5 w-full overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                {/* Volume: how many leads this campaign produced at all. */}
                <span
                  className="absolute inset-y-0 start-0 rounded-full"
                  style={{
                    width: `${leadWidth}%`,
                    background: 'color-mix(in srgb, var(--chart-2) 22%, transparent)',
                  }}
                />
                {/* Quality: how many of them were worth a rep's time. */}
                <span
                  className="absolute inset-y-0 start-0 rounded-full transition-[width] duration-[var(--duration-slow)]"
                  style={{ width: `${qualifiedWidth}%`, background: 'var(--chart-1)' }}
                />
                {/* And the sales, marked on the same scale. */}
                {row.won > 0 && (
                  <span
                    className="absolute inset-y-0 w-[3px] rounded-full bg-[var(--color-ink)]"
                    style={{ insetInlineStart: `calc(${wonLeft}% - 1.5px)` }}
                  />
                )}
              </span>
            </button>

            {hovered === id && (
              <div className="absolute end-2 top-full z-20 -mt-1">
                <ChartTooltip
                  title={`${row.campaign} · ${t(`platforms.${row.platform}`, { defaultValue: row.platform })}`}
                  rows={[
                    { label: t('campaignQuality.leads'), value: formatNumber(row.leads, i18n.language), color: 'color-mix(in srgb, var(--chart-2) 40%, transparent)' },
                    { label: t('campaignQuality.contacted'), value: formatNumber(row.contacted, i18n.language) },
                    { label: t('campaignQuality.qualified'), value: formatNumber(row.qualified, i18n.language), color: 'var(--chart-1)', emphasis: true },
                    { label: t('campaignQuality.appointments'), value: formatNumber(row.appointments, i18n.language) },
                    { label: t('campaignQuality.won'), value: formatNumber(row.won, i18n.language), color: 'var(--color-ink)' },
                    { label: t('campaignQuality.qualificationRate'), value: `${row.qualification_rate}%`, emphasis: true },
                  ]}
                />
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}
