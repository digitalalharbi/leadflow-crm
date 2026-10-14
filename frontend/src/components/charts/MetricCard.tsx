import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

/**
 * One number, with everything needed to judge it.
 *
 * The hierarchy is deliberate and the whole reason this exists as a component:
 * the metric is large, tight and tabular; the label is small and quiet; the
 * change is a chip rather than another number competing for attention. A
 * dashboard where the label and the value are the same weight is a dashboard
 * nobody can skim.
 */
export function MetricCard({
  label,
  value,
  previous,
  hint,
  icon,
  tone = 'neutral',
  to,
  spark,
  testId,
  /** Up is usually good; for overdue work the opposite is true. */
  invertChange = false,
}: {
  label: string
  value: number
  previous?: number | null
  hint?: ReactNode
  icon?: ReactNode
  tone?: 'neutral' | 'brand' | 'warning' | 'danger' | 'success'
  to?: string
  spark?: number[]
  testId?: string
  invertChange?: boolean
}) {
  const { t, i18n } = useTranslation()

  const change =
    previous !== undefined && previous !== null && previous > 0
      ? ((value - previous) / previous) * 100
      : null

  const good = change === null ? null : invertChange ? change <= 0 : change >= 0

  const iconChip = {
    neutral: 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]',
    brand: 'bg-[var(--brand-soft)] text-[var(--brand)]',
    warning: 'bg-[color-mix(in_srgb,var(--color-warning)_12%,transparent)] text-[var(--color-warning)]',
    danger: 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
    success: 'bg-[color-mix(in_srgb,var(--color-success)_12%,transparent)] text-[var(--color-success)]',
  }[tone]

  const valueTone = {
    neutral: 'text-[var(--color-ink)]',
    brand: 'text-[var(--brand)]',
    warning: 'text-[var(--color-warning)]',
    danger: 'text-[var(--color-danger)]',
    success: 'text-[var(--color-success)]',
  }[tone]

  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="t-caption leading-snug font-medium text-balance">{label}</p>
        {/*
          A soft tinted chip rather than a bare glyph.
          The icon is not information — the label already says what the number
          is — so it should read as a marker that helps the eye find the card
          again, and a tinted square does that without competing with the
          number for attention.
        */}
        {icon && (
          <span
            className={cn(
              'grid size-7 shrink-0 place-items-center rounded-[var(--radius-control)]',
              iconChip,
            )}
          >
            {icon}
          </span>
        )}
      </div>

      <div className="mt-2 flex items-end justify-between gap-3">
        <div className="min-w-0">
          {/*
            The figure is addressable on its own. The tile also carries a
            period-over-period change, so reading the whole card's text and
            stripping non-digits glues the two together — a test comparing
            "39" against "87.5%" read 398750 and nobody noticed, because it
            was comparing that number against another one just like it.
          */}
          <p
            className={cn('t-metric', valueTone)}
            data-testid={testId ? `${testId}-value` : undefined}
          >
            {formatNumber(value, i18n.language)}
          </p>

          {change !== null && (
            <p className="mt-1.5 flex items-center gap-1" dir="ltr">
              <span
                className={cn(
                  'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-semibold tabular',
                  good
                    ? 'bg-[color-mix(in_srgb,var(--color-success)_12%,transparent)] text-[var(--color-success)]'
                    : 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
                )}
              >
                {change >= 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
                {Math.abs(change).toFixed(1)}%
              </span>
            </p>
          )}

          {change === null && hint && <p className="t-caption mt-1.5">{hint}</p>}
        </div>

        {spark && spark.length > 1 && <Sparkline values={spark} tone={tone} />}
      </div>

      {change !== null && (
        <p className="t-caption mt-1 text-[0.6875rem]">{t('dashboard.vsPrevious')}</p>
      )}
    </>
  )

  const className = cn(
    'block rounded-[var(--radius-card)] border border-[var(--color-line)]',
    'bg-[var(--color-surface)] p-4 text-start shadow-[var(--shadow-card)]',
    to && [
      'pressable hover:-translate-y-px hover:shadow-[var(--shadow-raised)]',
      'hover:border-[color-mix(in_srgb,var(--brand)_35%,transparent)] active:translate-y-0',
    ],
  )

  return to ? (
    <Link to={to} className={className} data-testid={testId}>
      {body}
    </Link>
  ) : (
    <div className={className} data-testid={testId}>
      {body}
    </div>
  )
}

/**
 * The shape of the last two weeks, at the size of a thumbnail.
 *
 * No axes and no numbers: it answers "rising or falling?" and nothing else,
 * which is all there is room to answer honestly at this size.
 */
function Sparkline({ values, tone }: { values: number[]; tone: string }) {
  const width = 72
  const height = 30
  const peak = Math.max(...values, 1)
  const step = width / Math.max(values.length - 1, 1)

  const points = values.map((value, i) => [i * step, height - (value / peak) * (height - 4) - 2])
  const line = points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')
  const area = `${line} L${width} ${height} L0 ${height} Z`

  const stroke = tone === 'brand' ? 'var(--brand)' : 'var(--chart-1)'

  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} className="shrink-0" aria-hidden>
      <path d={area} fill={stroke} opacity="0.1" />
      <path d={line} fill="none" stroke={stroke} strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}
