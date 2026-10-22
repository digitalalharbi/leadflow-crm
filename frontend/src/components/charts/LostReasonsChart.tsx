import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { ReportRow } from '@/types/api'
import { OTHER } from './palette'

/**
 * Why deals are lost, as ranked horizontal bars.
 *
 * Not a pie: the question is "what is the biggest problem?", which is a
 * comparison of lengths against a shared baseline, and a pie makes people
 * compare angles instead. Ranked descending so the answer is the first row.
 *
 * The share matters more than the count — "budget" meaning 8% of losses and
 * meaning 60% are different problems with different answers.
 */
export function LostReasonsChart({ rows }: { rows: ReportRow[] }) {
  const { i18n } = useTranslation()
  const navigate = useNavigate()

  const ranked = rows.filter((row) => row.total > 0).toSorted((a, b) => b.total - a.total)
  const peak = Math.max(...ranked.map((row) => row.total), 1)

  return (
    <ul className="space-y-2 px-3 py-2" data-testid="lost-reasons">
      {ranked.map((row, index) => (
        <li key={row.key}>
          <button
            type="button"
            onClick={() => navigate('/leads?status[]=lost')}
            className={cn(
              'block w-full rounded-[var(--radius-control)] px-2 py-1.5 text-start transition-colors',
              'hover:bg-[var(--color-surface-sunken)]',
            )}
            data-testid={`lost-reason-${index}`}
          >
            <span className="mb-1 flex items-baseline justify-between gap-3">
              <span className="t-small truncate">{row.label}</span>
              <span className="flex items-baseline gap-2">
                <span className="tabular text-[0.875rem] text-[var(--color-ink-muted)]" dir="ltr">
                  {formatNumber(row.total, i18n.language)}
                </span>
                <span className="tabular text-[0.9375rem] font-semibold" dir="ltr">
                  {row.share}%
                </span>
              </span>
            </span>

            <span className="block h-2.5 w-full overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
              <span
                className="block h-full rounded-full transition-[width] duration-[var(--duration-slow)]"
                data-chart-mark
                style={{
                  width: `${(row.total / peak) * 100}%`,
                  // The leading reason is the problem; the rest are context.
                  // --chart-8 used to live here. The palette is five slots
                  // now, and an undefined custom property is not an error —
                  // it is simply no background, so every bar but the first
                  // rendered empty and nothing said a word.
                  background: index === 0 ? 'var(--color-danger)' : OTHER,
                  opacity: index === 0 ? 1 : 0.85 - index * 0.08,
                }}
              />
            </span>
          </button>
        </li>
      ))}
    </ul>
  )
}
