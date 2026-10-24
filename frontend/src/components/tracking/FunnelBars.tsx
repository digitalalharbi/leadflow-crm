import { useTranslation } from 'react-i18next'
import type { FunnelRow } from '@/features/hub'
import { formatNumber } from '@/lib/format'

/**
 * The funnel, step by step: how many reached it, what share of the first step that is, and how many were lost on the way in.
 * A step with nobody in it is still drawn — "nobody reached the form" is the finding — and the biggest loss is marked, because
 * it is the one worth fixing first.
 */
export function FunnelBars({ rows, testId = 'funnel' }: { rows: FunnelRow[]; testId?: string }) {
  const { t, i18n } = useTranslation()
  // The step that lost the most people on the way in is the one worth fixing first.
  const worst = rows.reduce<FunnelRow | null>((w, r) => (r.drop_off !== null && r.drop_off > 0 && (w === null || r.drop_off > (w.drop_off ?? 0)) ? r : w), null)

  return (
    <ol className="space-y-1.5" data-testid={testId} aria-label={t('hub.funnel.title')}>
      {rows.map((r) => (
        <li key={r.key} data-testid={`${testId}-${r.key}`} data-worst={worst?.key === r.key || undefined}>
          <div className="flex items-baseline justify-between gap-2 text-[0.9375rem]">
            <span className="font-semibold">{t(`hub.funnel.step.${r.key}`)}</span>
            <span className="tabular-nums"><strong data-testid={`${testId}-${r.key}-count`}>{formatNumber(r.count, i18n.language)}</strong>{r.of_start !== null && <span className="ms-1.5 text-[var(--color-ink-subtle)]">{r.of_start}%</span>}</span>
          </div>
          <div className="mt-0.5 h-3 overflow-hidden rounded-full bg-[var(--color-surface-muted)]" role="presentation">
            <div className="h-full rounded-full bg-[var(--brand)]" style={{ width: `${Math.max(r.count > 0 ? 2 : 0, Math.min(100, r.of_start ?? 0))}%` }} />
          </div>
          {r.drop_off !== null && r.drop_off > 0 && (
            <p className="t-caption mt-0.5 text-[var(--color-ink-subtle)]" data-testid={`${testId}-${r.key}-drop`}>
              {t('hub.funnel.dropOff', { percent: r.drop_off })}{worst?.key === r.key && <strong className="ms-1.5 text-[var(--color-warning)]">{t('hub.funnel.worst')}</strong>}
            </p>
          )}
        </li>
      ))}
    </ol>
  )
}
