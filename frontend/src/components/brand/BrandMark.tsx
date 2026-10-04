import { useId } from 'react'
import { cn } from '@/lib/cn'

/**
 * The مهتم mark.
 *
 * The idea it has to carry is the product's whole argument: an interest
 * arrives, it moves, and it becomes a qualified opportunity. So the mark is a
 * dot that has travelled along a path and arrived — the path drawn as the
 * hooked stroke of م, which in Arabic is a loop with a tail.
 *
 * What it deliberately is not: a target, a funnel, a speech bubble, or a chart
 * going up. Those are the four marks every CRM already uses, and a buyer who
 * has seen three competitors cannot tell them apart.
 *
 * Drawn on a 32-unit grid with 3-unit strokes so it holds at 16px, where the
 * loop has to stay open and the dot has to stay a dot.
 */
export function BrandMark({
  className,
  size = 24,
  tone = 'brand',
  animated = false,
}: {
  className?: string
  size?: number
  /** `brand` uses the accent; `mono` inherits currentColor for one-colour use. */
  tone?: 'brand' | 'mono'
  animated?: boolean
}) {
  const id = useId()
  const stroke = tone === 'mono' ? 'currentColor' : 'var(--brand)'
  const accent = tone === 'mono' ? 'currentColor' : 'var(--brand)'

  return (
    <svg
      viewBox="0 0 32 32"
      width={size}
      height={size}
      fill="none"
      role="img"
      aria-label="مهتم"
      className={cn('shrink-0', className)}
    >
      <title>مهتم</title>

      {/*
        The loop of م, drawn as a single open circle with the tail that makes it
        a letter rather than a ring. The opening faces the dot: the mark is a
        held shape with one way in, which is the product's claim in a glyph.
      */}
      <path
        d="M18.96 7.75 A6.9 6.9 0 1 0 21.87 12.80 M13.57 20.15 Q14.00 23.80 9.60 25.60"
        stroke={stroke}
        strokeWidth="3.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/*
        The lead: outside the opening, aligned on the arc it is about to enter.
        One dot and one loop is the whole mark — anything more disappears at
        16px, which is the size it is seen at most often.
      */}
      <circle
        cx="24.79"
        cy="7.75"
        r="3.3"
        fill={accent}
        className={animated ? 'brand-mark-dot' : undefined}
      />

      <defs>
        <clipPath id={`${id}-clip`}>
          <rect width="32" height="32" />
        </clipPath>
      </defs>
    </svg>
  )
}
