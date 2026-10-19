import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MiniTrend } from '@/components/charts/MiniTrend'
import { platformColor } from '@/components/charts'
import { RingProgress, StatusPill, healthTone, type StatusKey } from '@/components/visual'
import type { CampaignRow } from '@/features/campaigns'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

const STATUS: Record<CampaignRow['status'], StatusKey | null> = {
  active: 'active',
  paused: 'paused',
  ended: 'completed',
  unknown: null,
}

/**
 * One campaign as a card: what it cost, what it brought, and — the bar — how
 * much of what it brought was real.
 *
 * The bar is the point of the card. Two campaigns with the same lead count look
 * identical in a table; here one is mostly green and one is mostly grey, and the
 * difference between buying forms and buying customers is visible before any
 * figure is read.
 */
export function CampaignCard({ campaign, currency }: { campaign: CampaignRow; currency: string }) {
  const { t, i18n } = useTranslation()
  const money = (value: number | null) => formatMoneyCompact(value, campaign.currency ?? currency, i18n.language)
  const status = STATUS[campaign.status]

  const total = Math.max(1, campaign.leads)
  const qualified = (campaign.qualified / total) * 100
  const cold = (campaign.cold / total) * 100

  return (
    <Link
      to={`/campaigns/${campaign.id}`}
      data-testid="campaign-card"
      className="flex flex-col gap-3 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-card)] transition-shadow hover:shadow-[var(--shadow-raised)]"
    >
      <div className="flex items-start gap-2.5">
        <span className="mt-1.5 size-2.5 shrink-0 rounded-full" style={{ background: platformColor(campaign.platform) }} aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[1rem] font-semibold" dir="auto">{campaign.name}</p>
          <p className="truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">
            {t(`platforms.${campaign.platform}`, { defaultValue: campaign.platform })}
            {campaign.project && ` · ${campaign.project.name}`}
          </p>
        </div>
        {campaign.quality_score !== null && (
          <span title={t('campaigns.qualityScore')} data-testid="quality-score">
            <RingProgress size={34} thickness={4} value={campaign.quality_score} tone={healthTone(campaign.quality_score)}>
              <span className="text-[0.8125rem] font-bold tabular-nums">{campaign.quality_score}</span>
            </RingProgress>
          </span>
        )}
        {status && <StatusPill status={status} iconOnly />}
      </div>

      <div className="flex items-end justify-between gap-2">
        <div>
          <p className="text-[1.375rem] font-bold leading-none tabular-nums" dir="ltr">{formatNumber(campaign.leads, i18n.language)}</p>
          <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-muted)]">{t('campaigns.leads')}</p>
        </div>
        {campaign.daily.length > 1 && <MiniTrend values={campaign.daily.map((d) => d.spend)} />}
      </div>

      {/* Quality: qualified · in between · cold, as one bar. */}
      <div className="flex h-2 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" role="img" aria-label={t('campaigns.qualityBar', { qualified: Math.round(qualified), cold: Math.round(cold) })} data-testid="quality-bar">
        <span style={{ width: `${qualified}%`, background: 'var(--color-status-qualified)' }} data-chart-mark />
        <span style={{ width: `${Math.max(0, 100 - qualified - cold)}%`, background: 'var(--color-temp-warm)', opacity: 0.55 }} data-chart-mark />
        <span style={{ width: `${cold}%`, background: 'var(--color-status-invalid)', opacity: 0.45 }} data-chart-mark />
      </div>

      <dl className="grid grid-cols-4 gap-1 text-center">
        {[
          ['qualifiedShort', campaign.qualified_rate === null ? '—' : `${campaign.qualified_rate}%`],
          ['cpl', money(campaign.cpl)],
          ['cpql', money(campaign.cpql)],
          ['won', formatNumber(campaign.won, i18n.language)],
        ].map(([key, value]) => (
          <div key={key} className="min-w-0">
            <dd className="truncate text-[0.9375rem] font-semibold tabular-nums" dir="ltr">{value}</dd>
            <dt className="truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`campaigns.${key}`)}</dt>
          </div>
        ))}
      </dl>
    </Link>
  )
}
