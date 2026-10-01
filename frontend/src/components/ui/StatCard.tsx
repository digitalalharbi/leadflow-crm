import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/**
 * A single number with its label. Deliberately plain: the dashboard's job is
 * to be read in two seconds, not to be decorated.
 */
export function StatCard({
  label,
  value,
  hint,
  icon,
  tone = 'neutral',
  onClick,
}: {
  label: string
  value: string | number
  hint?: string
  icon?: ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'brand'
  onClick?: () => void
}) {
  const tones = {
    neutral: 'text-[var(--color-ink)]',
    success: 'text-emerald-600',
    warning: 'text-amber-600',
    danger: 'text-red-600',
    brand: 'text-[var(--brand)]',
  }

  const Element = onClick ? 'button' : 'div'

  return (
    <Element
      {...(onClick ? { type: 'button' as const, onClick } : {})}
      className={cn(
        'rounded-[var(--radius-card)] border border-[var(--color-border)] bg-[var(--color-surface)]',
        'p-4 text-start shadow-[var(--shadow-card)]',
        onClick && 'transition-colors hover:border-[var(--color-border-strong)] hover:bg-[var(--color-surface-muted)]',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[0.75rem] leading-snug font-medium text-balance text-[var(--color-ink-muted)]">
          {label}
        </p>
        {icon && <span className="mt-0.5 shrink-0 text-[var(--color-ink-subtle)]">{icon}</span>}
      </div>
      <p className={cn('tabular mt-2 text-2xl font-semibold', tones[tone])}>{value}</p>
      {hint && <p className="mt-0.5 text-[0.75rem] text-[var(--color-ink-subtle)]">{hint}</p>}
    </Element>
  )
}
