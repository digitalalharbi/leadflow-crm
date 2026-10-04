import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { ChartTooltip } from './ChartTooltip'
import { platformColor } from './palette'
import type { CampaignQualityRow } from '@/types/api'

/**
 * The middle value, used for the quadrant lines — so "good" is relative to this
 * account's own performance rather than an industry benchmark nobody agreed to.
 */
function median(values: number[]): number {
  const sorted = values.toSorted((a, b) => a - b)
  const mid = Math.floor(sorted.length / 2)

  return sorted.length % 2 === 0 ? (sorted[mid - 1] + sorted[mid]) / 2 : sorted[mid]
}

/**
 * Volume against quality, one bubble per campaign.
 *
 * This is the chart the product is really for. A table of campaigns makes a
 * reader hold six numbers in their head and compare them; this puts every
 * campaign in one picture where position carries the judgement:
 *
 *   top-left     few leads, high quality      — spend more here
 *   top-right    many leads, high quality     — the winner
 *   bottom-right many leads, low quality      — the expensive mistake
 *   bottom-left  few leads, low quality       — stop
 *
 * Bubble size is sales, so a campaign that converts is visibly heavier than
 * one that merely qualifies. The quadrant lines are the medians rather than
 * fixed thresholds — "good" is relative to this account's own performance.
 */
export function CampaignMatrix({ rows }: { rows: CampaignQualityRow[] }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [hovered, setHovered] = useState<string | null>(null)

  const points = rows.filter((row) => row.leads > 0)

  if (points.length < 2) {
    return null
  }

  const maxLeads = Math.max(...points.map((row) => row.leads))
  const maxRate = Math.max(...points.map((row) => row.qualification_rate), 10)
  const maxWon = Math.max(...points.map((row) => row.won), 1)

  const midLeads = median(points.map((row) => row.leads))
  const midRate = median(points.map((row) => row.qualification_rate))

  const x = (leads: number) => 8 + (leads / maxLeads) * 84
  const y = (rate: number) => 92 - (rate / maxRate) * 82

  return (
    <div className="relative px-3 py-2" data-testid="campaign-matrix">
      <svg viewBox="0 0 100 100" className="h-[230px] w-full overflow-visible" role="img">
        <title>{t('campaignQuality.matrixTitle')}</title>

        {/* Quadrant guides, drawn faintly — they orient without competing. */}
        <line
          x1={x(midLeads)} y1="4" x2={x(midLeads)} y2="96"
          stroke="var(--color-border)" strokeWidth="0.4" strokeDasharray="1.5 1.5"
        />
        <line
          x1="4" y1={y(midRate)} x2="96" y2={y(midRate)}
          stroke="var(--color-border)" strokeWidth="0.4" strokeDasharray="1.5 1.5"
        />

        {points.map((row) => {
          const id = `${row.platform}-${row.campaign}`
          const radius = 1.8 + (row.won / maxWon) * 3.6

          return (
            <g key={id}>
              <circle
                cx={x(row.leads)}
                cy={y(row.qualification_rate)}
                r={radius}
                fill={platformColor(row.platform)}
                fillOpacity={hovered === id ? 0.95 : 0.6}
                stroke={platformColor(row.platform)}
                strokeWidth="0.5"
                className="cursor-pointer transition-[fill-opacity]"
                onMouseEnter={() => setHovered(id)}
                onMouseLeave={() => setHovered(null)}
                onClick={() => navigate(`/leads?campaign_name=${encodeURIComponent(row.campaign)}`)}
                data-testid={`matrix-${row.campaign}`}
              />
            </g>
          )
        })}
      </svg>

      {/* Axis labels, outside the plot so they never collide with a bubble. */}
      <div className="flex items-center justify-between px-1 pt-1">
        <span className="t-caption text-[0.6875rem]">{t('campaignQuality.axisVolume')}</span>
        <span className="t-caption text-[0.6875rem]">{t('campaignQuality.axisQuality')}</span>
      </div>

      {hovered && (
        <div className="pointer-events-none absolute end-4 top-2 z-20">
          {(() => {
            const row = points.find((candidate) => `${candidate.platform}-${candidate.campaign}` === hovered)

            if (!row) return null

            return (
              <ChartTooltip
                title={row.campaign}
                rows={[
                  {
                    label: t('campaignQuality.platform'),
                    value: t(`platforms.${row.platform}`, { defaultValue: row.platform }),
                    color: platformColor(row.platform),
                  },
                  { label: t('campaignQuality.leads'), value: formatNumber(row.leads, i18n.language) },
                  { label: t('campaignQuality.qualified'), value: formatNumber(row.qualified, i18n.language) },
                  { label: t('campaignQuality.qualificationRate'), value: `${row.qualification_rate}%`, emphasis: true },
                  { label: t('campaignQuality.appointments'), value: formatNumber(row.appointments, i18n.language) },
                  { label: t('campaignQuality.won'), value: formatNumber(row.won, i18n.language), emphasis: true },
                ]}
              />
            )
          })()}
        </div>
      )}
    </div>
  )
}
