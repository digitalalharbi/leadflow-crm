import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/cn'

/*
 * A white object on a shaded canvas.
 *
 * The header has no rule under it. A line between a title and the thing it
 * titles is a seam, and on a card that is already separated from the page by
 * its own edge it is one separation too many — the whitespace says it better
 * and costs less.
 *
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
        /*
         * No border. The shadow already carries a 1px ring, and a border on
         * top of it reads as two edges — which is the difference between a
         * card that sits on the canvas and one that is drawn on it.
         */
        'rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]',
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
        'flex flex-wrap items-center justify-between gap-2 px-4 pb-1 pt-3',
        className,
      )}
    >
      <div className="min-w-0">
        <h3 className="truncate text-[1.0625rem] font-semibold leading-tight">{title}</h3>
        {description && (
          <p className="mt-0.5 text-[0.875rem] leading-snug text-[var(--color-ink-muted)]">{description}</p>
        )}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('px-4 pb-4 pt-2', className)} {...props} />
}
