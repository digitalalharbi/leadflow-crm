import { useLayoutEffect, useRef } from 'react'
import type { HTMLAttributes, ThHTMLAttributes, TdHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

const PHONE = '(max-width: 639.98px)'

/**
 * On a phone, a table wider than the screen becomes a stack of cards: each row a card, each cell under the name of its
 * column. A table that fits stays a table. Nobody has to swipe sideways to read a row, and nothing is cut off.
 *
 * Measured, not guessed: the wrapper is un-stacked, measured and re-stacked in one go (no paint in between), again
 * whenever its width or its rows change. The column names are copied onto the cells, so the cards read correctly
 * whichever page built the table.
 */
function useStackOnPhone() {
  const ref = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const wrap = ref.current
    if (!wrap || typeof window.matchMedia !== 'function') return

    const media = window.matchMedia(PHONE)

    const label = () => {
      for (const table of Array.from(wrap.querySelectorAll('table'))) {
        const heads = Array.from(table.querySelectorAll('thead tr:last-child > th')).map((th) => (th.textContent ?? '').trim())
        for (const row of Array.from(table.querySelectorAll('tbody tr'))) {
          Array.from(row.children).forEach((cell, index) => {
            const name = heads[index]
            if (cell.tagName === 'TD' && name && cell.getAttribute('data-label') !== name) cell.setAttribute('data-label', name)
          })
        }
      }
    }

    const decide = () => {
      label()
      if (!media.matches) {
        wrap.removeAttribute('data-stacked')
        return
      }
      wrap.removeAttribute('data-stacked')
      const table = wrap.querySelector('table')
      const wide = table !== null && table.scrollWidth > wrap.clientWidth + 1
      if (wide) wrap.setAttribute('data-stacked', 'true')
    }

    decide()
    const resize = new ResizeObserver(() => decide())
    resize.observe(wrap)
    const rows = new MutationObserver(() => decide())
    rows.observe(wrap, { childList: true, subtree: true })
    media.addEventListener?.('change', decide)

    return () => {
      resize.disconnect()
      rows.disconnect()
      media.removeEventListener?.('change', decide)
    }
  }, [])

  return ref
}

/**
 * A plain, dense table. On a desktop it scrolls inside its wrapper if it must; on a phone, a table that does not fit
 * turns into cards (see useStackOnPhone), so the page never scrolls sideways and no row needs a sideways swipe.
 */
export function TableWrap({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  const ref = useStackOnPhone()

  return <div ref={ref} className={cn('scroll-x table-wrap', className)} {...props} />
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
