import { Star } from 'lucide-react'
import { initials } from '@/lib/format'
import { cn } from '@/lib/cn'

const RING: Record<string, string> = {
  hot: 'var(--color-temp-hot)',
  warm: 'var(--color-temp-warm)',
  cold: 'var(--color-temp-cold)',
}

/**
 * A lead at a glance: whose, and how warm.
 *
 * The ring is the temperature — the one property of a lead that decides whether
 * somebody looks at the row twice — so it is readable down a whole column before
 * a single word is. A priority gets a star on the ring rather than a fourth
 * colour: it is a hot lead with one more reason to be first.
 *
 * Decorative to a screen reader: the name is right beside it, and the
 * temperature is in words in the same row.
 */
export function LeadAvatar({
  name,
  temperature,
  priority = false,
  size = 'md',
}: {
  name: string
  temperature: string
  priority?: boolean
  size?: 'sm' | 'md'
}) {
  const color = RING[temperature] ?? 'var(--color-border-strong)'

  return (
    <span
      aria-hidden
      className={cn(
        'relative grid shrink-0 place-items-center rounded-full bg-[var(--color-surface)] font-semibold text-[var(--color-ink-muted)]',
        size === 'sm' ? 'size-8 text-[0.6875rem]' : 'size-10 text-[0.8125rem]',
      )}
      style={{ boxShadow: `0 0 0 2px ${color}` }}
    >
      {initials(name)}
      {priority && (
        <span
          className="absolute -end-1 -top-1 grid size-4 place-items-center rounded-full text-white"
          style={{ background: 'var(--color-temp-hot)' }}
          data-testid="avatar-priority"
        >
          <Star className="size-2.5 fill-current" />
        </span>
      )}
    </span>
  )
}
