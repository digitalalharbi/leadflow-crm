import { cn } from '@/lib/cn'

export interface LegendItem {
  key: string
  label: string
  color: string
}

/**
 * A legend whose entries are switches.
 *
 * Hiding a series is the fastest way to answer "what would this look like
 * without Snapchat?", and it costs nothing — so the entries are buttons, and a
 * hidden one dims rather than disappears so the set stays stable.
 */
export function ChartLegend({
  items,
  hidden,
  onToggle,
  className,
}: {
  items: LegendItem[]
  hidden?: Set<string>
  onToggle?: (key: string) => void
  className?: string
}) {
  return (
    <ul className={cn('flex flex-wrap items-center gap-x-4 gap-y-1.5', className)}>
      {items.map((item) => {
        const off = hidden?.has(item.key) ?? false
        const content = (
          <>
            <span
              className="size-2.5 shrink-0 rounded-[3px] transition-opacity"
              style={{ background: item.color, opacity: off ? 0.25 : 1 }}
              aria-hidden
            />
            <span className={cn('t-caption transition-colors', off && 'line-through opacity-55')}>
              {item.label}
            </span>
          </>
        )

        return (
          <li key={item.key}>
            {onToggle ? (
              <button
                type="button"
                onClick={() => onToggle(item.key)}
                aria-pressed={!off}
                className="flex items-center gap-1.5 rounded-[var(--radius-control)] px-1 py-0.5 hover:bg-[var(--color-surface-sunken)]"
              >
                {content}
              </button>
            ) : (
              <span className="flex items-center gap-1.5 px-1">{content}</span>
            )}
          </li>
        )
      })}
    </ul>
  )
}
