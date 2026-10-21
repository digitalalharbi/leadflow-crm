import { useTranslation } from 'react-i18next'
import { RingProgress } from '@/components/visual'
import type { Tone } from '@/components/visual'
import type { CampaignQualityScore, QualityClass } from '@/features/campaignOps'
import { cn } from '@/lib/cn'

export const CLASS_TONE: Record<QualityClass, Tone> = { excellent: 'good', healthy: 'good', watch: 'warn', at_risk: 'bad', critical: 'bad' }

/** A campaign's quality as a ring with its class beside it — the verdict before the reasons. */
export function QualityBadge({ quality, size = 38 }: { quality: CampaignQualityScore; size?: number }) {
  const { t } = useTranslation()

  if (quality.score === null || quality.class === null) {
    return <span className="t-caption" data-testid="quality-none">{t('campaignOps.notEnough')}</span>
  }

  return (
    <span className="inline-flex items-center gap-2" data-testid="quality-badge" data-class={quality.class}>
      <RingProgress size={size} thickness={4.5} value={quality.score} tone={CLASS_TONE[quality.class]} label={t('campaignOps.score')}>
        <span className="text-[0.8125rem] font-bold tabular-nums" dir="ltr">{quality.score}</span>
      </RingProgress>
      <span className={cn('text-[0.875rem] font-bold', quality.class === 'critical' || quality.class === 'at_risk' ? 'text-[var(--color-danger)]' : quality.class === 'watch' ? 'text-[var(--color-warning)]' : 'text-[var(--brand)]')}>
        {t(`campaignOps.class.${quality.class}`)}
      </span>
    </span>
  )
}
