import { Link } from 'react-router-dom'
import { useAuth } from '@/stores/auth'
import { cn } from '@/lib/cn'
import { Logo, type LogoLayout, type LogoVariant } from './Logo'

/**
 * The logo, and the one place that decides whose it is.
 *
 * مهتم is the platform's default identity, not a watermark: a tenant with its own logo or system
 * name gets theirs, with no trace of ours. That is the whole product promise of a white label, and
 * a "powered by" line would break it — if it is ever wanted, it belongs behind a setting the
 * customer controls. Everyone else gets the official logo (see Logo), never a drawing of it.
 */
export function BrandLockup({
  className,
  size = 'md',
  to,
  variant = 'auto',
  layout = 'horizontal',
  /** Where the name sits for a tenant's own identity: on pine it must be light. */
  onDark = false,
}: {
  className?: string
  size?: 'sm' | 'md' | 'lg'
  /** Renders as a link when given — the usual home affordance in a sidebar. */
  to?: string
  variant?: LogoVariant
  layout?: LogoLayout
  onDark?: boolean
}) {
  const branding = useAuth((s) => s.branding)

  const height = { sm: 44, md: 44, lg: layout === 'stacked' ? 112 : 56 }[size]
  const markSize = { sm: 24, md: 28, lg: 36 }[size]
  const logo = branding?.logo_url
  const name = branding?.system_name

  // A workspace that supplied either its logo or its own name is shown as itself.
  const own = Boolean(logo || name)

  const content = own ? (
    <span className={cn('flex min-w-0 items-center gap-2.5', className)}>
      {logo ? (
        <img src={logo} alt="" style={{ height: markSize, width: markSize }} className="shrink-0 rounded-[0.4rem] object-contain" />
      ) : (
        <Logo variant={variant === 'reverse' ? 'reverse' : 'auto'} layout="mark" height={markSize} />
      )}

      {name && (
        <span
          className={cn(
            'truncate font-semibold',
            onDark ? 'text-[var(--chrome-ink)]' : 'text-[var(--color-ink)]',
            size === 'sm' ? 'text-[1.0625rem]' : size === 'lg' ? 'text-[1.5rem]' : 'text-[1.125rem]',
          )}
        >
          {name}
        </span>
      )}
    </span>
  ) : (
    <Logo variant={variant} layout={layout} height={height} className={className} />
  )

  return to ? (
    <Link to={to} className="min-w-0 rounded-[var(--radius-control)]">
      {content}
    </Link>
  ) : (
    content
  )
}
