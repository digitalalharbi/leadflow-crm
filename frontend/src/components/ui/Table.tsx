import type { HTMLAttributes, ThHTMLAttributes, TdHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * A plain, dense table. Horizontal scroll is kept inside the wrapper so the
 * page itself never scrolls sideways on a phone.
 */
export function TableWrap({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('scroll-x', className)} {...props} />
}

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
  return <table className={cn('w-full border-collapse text-sm', className)} {...props} />
}

export function Th({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cn(
        'whitespace-nowrap border-b border-[var(--color-line)] bg-[var(--color-surface-muted)]',
        // `.label-caps` reads as a label rather than another row of data, and
        // leaves Arabic alone — see the comment on the class.
        'label-caps px-3 py-1.5 text-start text-[var(--color-ink-subtle)]',
        className,
      )}
      {...props}
    />
  )
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cn('border-b border-[var(--color-line)] px-3 py-1.5 align-middle', className)}
      {...props}
    />
  )
}

export function Tr({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
  return (
    <tr
      className={cn(
        'transition-colors duration-[var(--duration-fast)] hover:bg-[var(--color-surface-muted)]',
        'last:[&>td]:border-b-0',
        className,
      )}
      {...props}
    />
  )
}
