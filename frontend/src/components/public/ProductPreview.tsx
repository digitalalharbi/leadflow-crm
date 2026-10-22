import { useTranslation } from 'react-i18next'
import { LANDING } from '@/content/landing'
import { cn } from '@/lib/cn'

/**
 * A small picture of the product, drawn from sample numbers.
 *
 * An outcome, not a mechanism: the figures a business wants and the road from interest to deal. Built from the
 * product's own tokens (white cards, the kit's border, emerald as the accent, copper only on the revenue
 * figure) so what a visitor sees is what a customer gets — and it is labelled
 * «بيانات توضيحية» in the corner, because none of these numbers are real. No image: markup and CSS only,
 * so it costs nothing to load.
 */
const SAMPLE = {
  kpis: [1248, 312, 86, 24] as const,
  revenue: { ar: '4.8M ر.س', en: 'SAR 4.8M' },
  funnel: [1248, 540, 312, 86, 24, 11] as const,
}

const number = (value: number | string) => (typeof value === 'number' ? new Intl.NumberFormat('en-US').format(value) : value)

export function ProductPreview({ className }: { className?: string }) {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'
  const copy = LANDING[lang].preview
  const kpis: Array<[string, string, boolean]> = [
    [copy.kpis.customers, number(SAMPLE.kpis[0]), false],
    [copy.kpis.interested, number(SAMPLE.kpis[1]), false],
    [copy.kpis.appointments, number(SAMPLE.kpis[2]), false],
    [copy.kpis.reservations, number(SAMPLE.kpis[3]), false],
    [copy.kpis.revenue, SAMPLE.revenue[lang], true],
  ]
  const top = SAMPLE.funnel[0]

  return (
    <figure
      className={cn('overflow-hidden rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] shadow-[0_12px_40px_-20px_rgb(20_32_29/0.25)]', className)}
      aria-label={`${copy.title} — ${copy.sample}`}
      data-testid="product-preview"
    >
      <figcaption className="flex items-center justify-between gap-2 border-b border-[var(--color-line)] px-3.5 py-2">
        <span className="text-[0.9375rem] font-semibold">{copy.title}</span>
        <span className="rounded-full bg-[var(--status-warn-bg)] px-2.5 py-0.5 text-[0.8125rem] font-semibold text-[var(--status-warn-ink)]" data-testid="preview-sample">{copy.sample}</span>
      </figcaption>

      <div className="space-y-2.5 p-3 sm:p-3.5">
        <dl className="grid grid-cols-3 gap-1.5 sm:grid-cols-5" data-testid="preview-kpis">
          {kpis.map(([label, value, money]) => (
            <div key={label} className={cn('min-w-0 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface)] px-1.5 py-1.5 text-center', money && 'border-t-[3px] border-t-[var(--accent)]')}>
              <dt className={cn('truncate text-[0.8125rem]', money ? 'font-medium text-[var(--accent-text)]' : 'text-[var(--color-ink-muted)]')}>{label}</dt>
              <dd className="truncate text-[1.0625rem] font-bold tabular-nums sm:text-[1.1875rem]" dir="ltr">{value}</dd>
            </div>
          ))}
        </dl>

        <div data-testid="preview-funnel">
          <p className="mb-1.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">{copy.funnelTitle}</p>
          <ol className="space-y-1">
            {copy.funnel.map((stage, index) => (
              <li key={stage} className="grid grid-cols-[3.5rem_1fr_3rem] items-center gap-2 text-[0.875rem]">
                <span className={cn('truncate', index === copy.funnel.length - 1 ? 'font-semibold text-[var(--accent-text)]' : 'text-[var(--color-ink-muted)]')}>{stage}</span>
                <span className="h-2.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]" aria-hidden>
                  <span className={cn('block h-full rounded-full', index === copy.funnel.length - 1 ? 'bg-[var(--accent)]' : 'bg-[var(--brand)]')} style={{ width: `${Math.max(4, (SAMPLE.funnel[index] / top) * 100)}%`, opacity: 1 - index * 0.1 }} />
                </span>
                <span className="text-end font-semibold tabular-nums" dir="ltr">{number(SAMPLE.funnel[index])}</span>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </figure>
  )
}
