import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { AlertCircle, Inbox, RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, buttonClasses } from './Button'
import { cn } from '@/lib/cn'

/** Shimmer placeholder. Pages never render blank while loading. */
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton rounded-[var(--radius-control)]', className)} aria-hidden />
}

export function SkeletonTable({ rows = 6, cols = 6 }: { rows?: number; cols?: number }) {
  return (
    <div className="divide-y divide-[var(--color-border)]" aria-busy="true">
      {Array.from({ length: rows }).map((_row, r) => (
        <div key={r} className="flex items-center gap-4 px-5 py-3.5">
          {Array.from({ length: cols }).map((_col, c) => (
            <Skeleton key={c} className={cn('h-4', c === 0 ? 'w-40' : 'w-20')} />
          ))}
        </div>
      ))}
    </div>
  )
}

export function SkeletonCards({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy="true">
      {Array.from({ length: count }).map((_, i) => (
        <div
          key={i}
          className="rounded-[var(--radius-card)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
        >
          <Skeleton className="h-3 w-20" />
          <Skeleton className="mt-3 h-7 w-16" />
        </div>
      ))}
    </div>
  )
}

/**
 * Empty states tell the user what to do next, never just "no data".
 * This is the first thing a brand-new tenant sees, so it has to carry its weight.
 */
export function EmptyState({
  icon,
  title,
  body,
  actions,
  className,
}: {
  icon?: ReactNode
  title: string
  body?: string
  actions?: ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex flex-col items-center px-6 py-14 text-center', className)}>
      <div className="mb-4 grid size-12 place-items-center rounded-full bg-[var(--color-surface-sunken)] text-[var(--color-ink-subtle)]">
        {icon ?? <Inbox className="size-6" aria-hidden />}
      </div>
      <h3 className="text-[1.0625rem] font-semibold">{title}</h3>
      {body && (
        <p className="mt-1.5 max-w-sm text-[0.9375rem] leading-relaxed text-[var(--color-ink-muted)]">
          {body}
        </p>
      )}
      {actions && <div className="mt-5 flex flex-wrap justify-center gap-2">{actions}</div>}
    </div>
  )
}

/** Error state with a retry — a dead end is never acceptable. */
export function ErrorState({
  message,
  onRetry,
  className,
  back,
}: {
  message?: string
  onRetry?: () => void
  className?: string
  /** Where to go instead — a page that is not there is not fixed by trying again. */
  back?: { to: string; label: string }
}) {
  const { t } = useTranslation()

  return (
    <div className={cn('flex flex-col items-center px-6 py-14 text-center', className)}>
      <div className="mb-4 grid size-12 place-items-center rounded-full bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] text-[var(--color-status-lost)]">
        <AlertCircle className="size-6" aria-hidden />
      </div>
      <h3 className="text-[1.0625rem] font-semibold">{t('errors.title')}</h3>
      <p className="mt-1.5 max-w-sm text-[0.9375rem] text-[var(--color-ink-muted)]">
        {message ?? t('errors.unknown')}
      </p>
      <div className="mt-5 flex flex-wrap justify-center gap-2">
        {back && (
          <Link to={back.to} className={buttonClasses('primary', 'sm')} data-testid="error-back">
            {back.label}
          </Link>
        )}
        {onRetry && (
          <Button variant="outline" size="sm" icon={<RefreshCw className="size-4" />} onClick={onRetry}>
            {t('common.retry')}
          </Button>
        )}
      </div>
    </div>
  )
}
