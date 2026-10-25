import { useTranslation } from 'react-i18next'
import type { FunnelStage, RealEstateFunnelData } from '@/features/reportShares'
import { cn } from '@/lib/cn'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

/**
 * The funnel of the product: وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة.
 *
 * One component for every place a funnel is drawn — the dashboard, a project, a report. A stage whose data does not exist
 * says so ("غير متاح" and why); it is never drawn as an empty bar that reads as zero. Awareness always names whose number
 * it is (the ad platforms' or Mohtam's own measurement); referral is set apart because it is not a share of the stage before.
 */
export function RealEstateFunnel({ funnel, currency = 'SAR', showRevenue = true, testId = 're-funnel' }: { funnel: RealEstateFunnelData; currency?: string; showRevenue?: boolean; testId?: string }) {
  const { t, i18n } = useTranslation()
  const chain = funnel.stages.filter((s) => s.key !== 'referral')
  const top = Math.max(1, ...chain.map((s) => s.count ?? 0))
  const referral = funnel.stages.find((s) => s.key === 'referral')

  return (
    <div className="space-y-3" data-testid={testId}>
      <ol className="space-y-2">
        {chain.map((stage, index) => <StageRow key={stage.key} stage={stage} index={index} top={top} currency={currency} showRevenue={showRevenue} lang={i18n.language} />)}
      </ol>

      {referral && (
        <div className="border-t border-dashed border-[var(--color-border)] pt-3">
          <StageRow stage={referral} index={chain.length} top={top} currency={currency} showRevenue={showRevenue} lang={i18n.language} detached />
        </div>
      )}

      {funnel.lost.count > 0 && (
        <p className="t-caption" data-testid={`${testId}-lost`}>
          {t('reFunnel.lost', { count: funnel.lost.count })}{funnel.lost.rate !== null ? ` · ${funnel.lost.rate}%` : ''}
        </p>
      )}
    </div>
  )
}

function StageRow({ stage, index, top, currency, showRevenue, lang, detached = false }: { stage: FunnelStage; index: number; top: number; currency: string; showRevenue: boolean; lang: string; detached?: boolean }) {
  const { t } = useTranslation()
  const count = stage.count ?? 0
  const width = stage.available && count > 0 ? Math.max(8, (count / top) * 100) : 0
  const shade = Math.max(55, 100 - index * 9)
  const change = stage.change

  return (
    <li data-testid={`${'re-funnel'}-${stage.key}`} data-available={stage.available} data-count={stage.available ? count : ''}>
      <div className="flex items-center gap-3">
        <span className="w-24 shrink-0 text-[0.9375rem] font-medium text-[var(--color-ink-muted)]">{t(`reFunnel.stage.${stage.key}`, { defaultValue: stage.label })}</span>
        <div className={cn('relative h-8 flex-1 overflow-hidden rounded-xl bg-[var(--color-chrome-fill)]', detached && 'border border-dashed border-[var(--color-border)] bg-transparent')} dir="ltr">
          {width > 0 && (
            <span
              className="absolute inset-y-0 start-0 flex items-center justify-end rounded-xl px-2.5"
              data-chart-mark
              style={{ width: `${width}%`, background: `color-mix(in oklab, var(--brand) ${shade}%, var(--brand-deep, var(--brand)))`, opacity: 0.35 + (shade / 100) * 0.65, transition: 'width var(--duration-slow) var(--ease-out-soft)' }}
            >
              {width >= 22 && <span className="text-[0.9375rem] font-semibold tabular-nums text-white">{formatNumber(count, lang)}</span>}
            </span>
          )}
          {width > 0 && width < 22 && (
            <span className="absolute inset-y-0 flex items-center text-[0.9375rem] font-semibold tabular-nums" style={{ left: `calc(${width}% + 0.5rem)` }}>{formatNumber(count, lang)}</span>
          )}
          {!stage.available && <span className="absolute inset-0 flex items-center px-3 text-[0.875rem] text-[var(--color-ink-subtle)]" dir="auto">{t('reFunnel.unavailable')}</span>}
          {stage.available && count === 0 && <span className="absolute inset-0 flex items-center px-3 text-[0.875rem] text-[var(--color-ink-subtle)]" dir="auto">{formatNumber(0, lang)}</span>}
        </div>
        <span className="w-24 shrink-0 text-end text-[0.8125rem] tabular-nums text-[var(--color-ink-subtle)]" dir="ltr">
          {stage.available && stage.rate !== null && !detached && <span data-testid={`re-funnel-rate-${stage.key}`}>{stage.rate}%</span>}
          {stage.available && change !== null && change !== undefined && (
            <span className={cn('block', change > 0 ? 'text-[var(--color-success)]' : change < 0 ? 'text-[var(--color-danger)]' : '')}>{change > 0 ? '+' : ''}{change}%</span>
          )}
        </span>
      </div>

      <div className="ms-[6.75rem] mt-1 space-y-0.5">
        {stage.key === 'awareness' && (
          <p className="t-caption" data-testid="re-funnel-basis">
            {stage.available ? t(`reFunnel.basis.${stage.basis ?? 'mohtam'}`) : t(`reFunnel.reason.${stage.reason ?? 'no_traffic_data'}`, { defaultValue: t('reFunnel.reason.no_traffic_data') })}
          </p>
        )}
        {detached && <p className="t-caption">{t('reFunnel.referralNote')}</p>}
        {stage.available && stage.sub_stages.length > 0 && (
          <p className="t-caption flex flex-wrap gap-x-3">
            {stage.sub_stages.filter((s) => s.count !== null).map((s) => <span key={s.key}>{t(`reFunnel.sub.${s.key}`, { defaultValue: s.key })} <b className="tabular-nums" dir="ltr">{formatNumber(s.count, lang)}</b></span>)}
          </p>
        )}
        {showRevenue && stage.revenue != null && stage.revenue > 0 && <p className="t-caption" dir="auto">{t('reFunnel.revenue')}: <b dir="ltr">{formatMoneyCompact(stage.revenue, currency, lang)}</b></p>}
      </div>
    </li>
  )
}
