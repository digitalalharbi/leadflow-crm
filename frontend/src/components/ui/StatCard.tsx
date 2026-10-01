import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/cn'

/**
 * A single number with its label.
 *
 * Deliberately plain: the dashboard's job is to be read in two seconds, not to
 * be decorated. When `to` is given the whole tile becomes a link — a number a
 * rep cannot act on is decoration, so most of them lead somewhere.
 */
export function StatCard({
  label,
  value,
  hint,
  icon,
  tone = 'neutral',
  to,
  onClick,
  testId,
}: {
  label: string
  value: string | number
  hint?: string
  icon?: ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'brand'
  to?: string
  onClick?: () => void
  testId?: string
}) {
  const tones = {
    neutral: 'text-[var(--color-ink)]',
    success: 'text-emerald-600',
    warning: 'text-amber-600',
    danger: 'text-red-600',
    brand: 'text-[var(--brand)]',
  }

  const interactive = Boolean(to || onClick)

  const className = cn(
    'block rounded-[var(--radius-card)] border border-[var(--color-border)]',
    'bg-[var(--color-surface)] p-4 text-start shadow-[var(--shadow-card)]',
    interactive &&
      'transition-colors hover:border-[var(--brand)] hover:bg-[var(--brand-soft)] focus-visible:border-[var(--brand)]',
  )

  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[0.75rem] leading-snug font-medium text-balance text-[var(--color-ink-muted)]">
          {label}
        </p>
        {icon && <span className="mt-0.5 shrink-0 text-[var(--color-ink-subtle)]">{icon}</span>}
      </div>
      <p className={cn('tabular mt-2 text-2xl font-semibold', tones[tone])}>{value}</p>
      {hint && <p className="mt-0.5 text-[0.75rem] text-[var(--color-ink-subtle)]">{hint}</p>}
    </>
  )

  if (to) {
    return (
      <Link to={to} className={className} data-testid={testId}>
        {body}
      </Link>
    )
  }

  if (onClick) {
    return (
      <button type="button" onClick={onClick} className={cn(className, 'w-full')} data-testid={testId}>
        {body}
      </button>
    )
  }

  return (
    <div className={className} data-testid={testId}>
      {body}
    </div>
  )
}
