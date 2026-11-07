import { useEffect, useRef, type ReactNode } from 'react'
import { cn } from '@/lib/cn'

/**
 * A row of tabs, as a segmented control rather than as underlines.
 *
 * Underlined tabs are a navigation pattern borrowed from documents: they say
 * "there is more of this page below". A segmented pill says "this is one of
 * these", which is what a project's Overview and Development actually are —
 * two views of the same thing, not two pages.
 *
 * Built here rather than hand-rolled per screen because it had been hand-rolled
 * three times already, and the three were slightly different sizes.
 */
export interface TabItem<T extends string = string> {
  key: T
  label: ReactNode
  /** A count beside the label, for the tabs where "how many" is the question. */
  count?: number
}

export function Tabs<T extends string>({
  items,
  value,
  onChange,
  className,
  testId,
}: {
  items: TabItem<T>[]
  value: T
  onChange: (key: T) => void
  className?: string
  testId?: string
}) {
  const strip = useRef<HTMLDivElement>(null)

  /*
   * Keeps the chosen tab in view.
   *
   * With nine tabs the strip scrolls inside itself on a phone, and the selected
   * one can sit entirely off-screen — which reads as nothing being selected at
   * all. Scrolled by hand rather than with `scrollIntoView`, which would also
   * scroll the *page* to bring the strip into the viewport.
   */
  useEffect(() => {
    const container = strip.current
    const active = container?.querySelector<HTMLElement>('[aria-selected="true"]')

    if (!container || !active) return

    const left = active.offsetLeft - container.offsetLeft
    const target = left - (container.clientWidth - active.offsetWidth) / 2

    container.scrollTo({ left: target, behavior: 'auto' })
  }, [value])

  return (
    <div
      ref={strip}
      role="tablist"
      data-testid={testId}
      className={cn(
        // On a phone the tabs wrap onto rows rather than scrolling sideways, so every one is in view.
        'inline-flex max-w-full items-center gap-0.5 overflow-x-auto max-sm:flex max-sm:flex-wrap max-sm:overflow-visible',
        'rounded-[var(--radius-pill)] bg-[var(--color-surface-muted)] p-1',
        className,
      )}
    >
      {items.map((item) => {
        const active = item.key === value

        return (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={active}
            data-testid={`tab-${item.key}`}
            onClick={() => onChange(item.key)}
            className={cn(
              'pressable inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap',
              'rounded-[var(--radius-pill)] px-3.5 py-1.5 text-[0.9375rem] font-medium transition-colors',
              active
                ? // The active tab is a raised white chip, which is the same
                  // relationship the cards have to the canvas.
                  'bg-[var(--color-surface)] text-[var(--color-ink)] shadow-[var(--shadow-card)]'
                : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
            )}
          >
            {item.label}
            {item.count !== undefined && (
              <span
                className={cn(
                  'tabular-nums rounded-[var(--radius-pill)] px-1.5 text-[0.8125rem]',
                  active
                    ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                    : 'bg-[var(--color-surface)] text-[var(--color-ink-muted)]',
                )}
              >
                {item.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}
