import type { ReactNode } from 'react'
import { Card } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * The frame every chart in مهتم sits in.
 *
 * It exists so that a chart's title, its action, its empty state and its
 * loading state are decided once rather than per screen — which is how a
 * product ends up with six slightly different card headers. It also reserves
 * the plot's height before data arrives, so nothing jumps when it lands.
 */
export function ChartCard({
  title,
  description,
  action,
  legend,
  footer,
  height = 260,
  loading = false,
  empty = false,
  emptyMessage,
  emptyAction,
  testId,
  className,
  children,
}: {
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  legend?: ReactNode
  footer?: ReactNode
  height?: number
  loading?: boolean
  empty?: boolean
  emptyMessage?: string
  emptyAction?: ReactNode
  testId?: string
  className?: string
  children: ReactNode
}) {
  return (
    <Card className={cn('flex flex-col overflow-hidden', className)} data-testid={testId}>
      <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-4 pb-3">
        <div className="min-w-0">
          <h3 className="t-card-title truncate">{title}</h3>
          {description && <p className="t-caption mt-0.5">{description}</p>}
        </div>
        {action}
      </div>

      {legend && <div className="px-5 pb-3">{legend}</div>}

      {/*
        The height is reserved whatever the state, so the card does not resize
        between skeleton, empty and loaded. A dashboard that reflows as each
        request lands is a dashboard nobody can read while it loads.
      */}
      <div className="min-w-0 flex-1 px-2 pb-2" style={{ minHeight: height }}>
        {loading ? (
          <ChartSkeleton height={height} />
        ) : empty ? (
          <ChartEmpty message={emptyMessage} action={emptyAction} height={height} />
        ) : (
          children
        )}
      </div>

      {footer && <div className="border-t border-[var(--color-line)] px-5 py-3">{footer}</div>}
    </Card>
  )
}

/** Bars of plausible heights, not a grey block — it reads as a chart arriving. */
export function ChartSkeleton({ height }: { height: number }) {
  const bars = [52, 74, 38, 88, 61, 46, 70, 33]

  return (
    <div className="flex h-full items-end gap-2 px-3 pb-6" style={{ height }} aria-hidden>
      {bars.map((value, i) => (
        <div
          key={i}
          className="skeleton flex-1 rounded-t-[0.3rem]"
          style={{ height: `${value}%`, animationDelay: `${i * 60}ms` }}
        />
      ))}
    </div>
  )
}

export function ChartEmpty({
  message,
  action,
  height,
}: {
  message?: string
  action?: ReactNode
  height: number
}) {
  return (
    <div
      className="flex flex-col items-center justify-center gap-3 px-6 text-center"
      style={{ height }}
    >
      {/* A flat line is the honest picture of no data, and it belongs to the
          chart language rather than being a generic empty illustration. */}
      <svg viewBox="0 0 64 28" className="w-16 text-[var(--color-border-strong)]" aria-hidden>
        <path d="M2 20h60" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeDasharray="4 5" />
        <circle cx="16" cy="20" r="2.5" fill="currentColor" />
        <circle cx="32" cy="20" r="2.5" fill="currentColor" />
        <circle cx="48" cy="20" r="2.5" fill="currentColor" />
      </svg>
      <p className="t-small text-[var(--color-ink-muted)]">{message}</p>
      {action}
    </div>
  )
}
