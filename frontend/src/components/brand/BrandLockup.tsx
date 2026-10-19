import { Link } from 'react-router-dom'
import { useAuth } from '@/stores/auth'
import { cn } from '@/lib/cn'
import { BrandMark } from './BrandMark'
import { BrandWordmark } from './BrandWordmark'

/**
 * Mark plus name, and the one place that decides whose name it is.
 *
 * مهتم is the platform's default identity, not a watermark: a tenant with its
 * own logo or system name gets theirs, with no trace of ours. That is the
 * whole product promise of a white label, and a "powered by" line would break
 * it — if it is ever wanted, it belongs behind a setting the customer controls.
 */
export function BrandLockup({
  className,
  size = 'md',
  to,
  animated = false,
}: {
  className?: string
  size?: 'sm' | 'md' | 'lg'
  /** Renders as a link when given — the usual home affordance in a sidebar. */
  to?: string
  animated?: boolean
}) {
  const branding = useAuth((s) => s.branding)

  const markSize = { sm: 20, md: 26, lg: 34 }[size]
  const logo = branding?.logo_url
  const name = branding?.system_name

  const content = (
    <span className={cn('flex min-w-0 items-center gap-2.5', className)}>
      {logo ? (
        <img
          src={logo}
          alt=""
          style={{ height: markSize, width: markSize }}
          className="shrink-0 rounded-[0.4rem] object-contain"
        />
      ) : (
        <BrandMark size={markSize} animated={animated} />
      )}

      {name ? (
        <span
          className={cn(
            'truncate font-semibold text-[var(--color-ink)]',
            size === 'sm' ? 'text-[1.0625rem]' : size === 'lg' ? 'text-[1.5rem]' : 'text-[1.125rem]',
          )}
        >
          {name}
        </span>
      ) : (
        <BrandWordmark size={size} />
      )}
    </span>
  )

  return to ? (
    <Link to={to} className="min-w-0 rounded-[var(--radius-control)]">
      {content}
    </Link>
  ) : (
    content
  )
}
