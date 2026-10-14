import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/cn'

/*
 * Dense by default.
 *
 * A card used to cost 40px of padding before it said anything. On a 768px-tall
 * screen that is a fifth of the viewport spent on breathing room, and the first
 * thing to fall below the fold was always the thing somebody opened the page
 * for. The padding here is what a dashboard can afford; a screen that needs
 * more says so itself with a className.
 */

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'rounded-[var(--radius-card)] border border-[var(--color-line)]',
        'bg-[var(--color-surface)] shadow-[var(--shadow-card)]',
        className,
      )}
      {...props}
    />
  )
}

export function CardHeader({
  title,
  description,
  action,
  className,
}: {
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] px-3.5 py-2',
        className,
      )}
    >
      <div className="min-w-0">
        <h3 className="truncate text-[0.875rem] font-semibold leading-tight">{title}</h3>
        {description && (
          <p className="mt-0.5 text-[0.75rem] leading-snug text-[var(--color-ink-muted)]">{description}</p>
        )}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('p-3.5', className)} {...props} />
}
