import type { HTMLAttributes, ThHTMLAttributes, TdHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * A plain, dense table. Horizontal scroll is kept inside the wrapper so the
 * page itself never scrolls sideways on a phone.
 */
export function TableWrap({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('scroll-x', className)} {...props} />
}

/**
 * `stickyHeader` is for a table whose wrapper scrolls vertically — it pins the
 * header row to the top of that wrapper. It is opt-in rather than always on
 * because a sticky header in a table that does not scroll pins itself to the
 * *page* instead, which is a different and unwanted behaviour.
 */
export function Table({
  stickyHeader = false,
  className,
  ...props
}: HTMLAttributes<HTMLTableElement> & { stickyHeader?: boolean }) {
  return (
    <table
      className={cn('w-full border-collapse text-sm', stickyHeader && 'table-sticky-head', className)}
      {...props}
    />
  )
}

export function Th({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cn(
        'whitespace-nowrap border-b border-[var(--color-line)] bg-[var(--color-surface-muted)]',
        // `.label-caps` reads as a label rather than another row of data, and
        // leaves Arabic alone — see the comment on the class.
        'label-caps px-4 py-2 text-start text-[var(--color-ink-subtle)]',
        className,
      )}
      {...props}
    />
  )
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cn('border-b border-[var(--color-line)] px-4 py-2 align-middle', className)}
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
