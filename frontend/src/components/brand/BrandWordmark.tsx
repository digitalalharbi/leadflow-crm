import { cn } from '@/lib/cn'

/**
 * The مهتم wordmark.
 *
 * Set in the product's own Arabic face rather than drawn as outlines, which is
 * a deliberate trade: outlined letterforms are immune to a font failing to
 * load, but hand-cut Arabic that nobody has proofed is worse than correct
 * type. The weight, tracking and optical size are locked here so it is a mark
 * rather than a heading that happens to say the name.
 *
 * There is no Latin spelling. The product is مهتم in an English interface too —
 * a second spelling is a second name, and it had already drifted into two.
 */
export function BrandWordmark({
  className,
  locale = 'ar',
  size = 'md',
}: {
  className?: string
  locale?: 'ar' | 'en'
  size?: 'sm' | 'md' | 'lg'
}) {
  const sizes = {
    sm: 'text-[0.9375rem]',
    md: 'text-[1.125rem]',
    lg: 'text-[1.5rem]',
  }

  return (
    <span
      dir="rtl"
      className={cn(
        'font-semibold whitespace-nowrap text-[var(--color-ink)]',
        // Arabic takes no tracking — it breaks the joins. Latin is tightened,
        // which is what makes a short word read as a mark.
        locale === 'ar' ? 'tracking-normal' : 'tracking-[-0.03em]',
        sizes[size],
        className,
      )}
    >
      مهتم
    </span>
  )
}
