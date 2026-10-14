import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react'
import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'outline'
type Size = 'sm' | 'md' | 'lg' | 'icon'

/*
 * The primary button carries a one-pixel inner highlight along its top edge.
 * It is the cheapest way to make a solid fill read as a physical surface
 * catching light rather than a coloured rectangle.
 */
const variants: Record<Variant, string> = {
  primary: [
    'bg-[var(--brand)] text-[var(--brand-contrast)]',
    'shadow-[inset_0_1px_0_0_rgb(255_255_255/0.16),var(--shadow-card)]',
    'hover:brightness-[1.07] active:brightness-95',
  ].join(' '),
  secondary: [
    'bg-[var(--color-surface-sunken)] text-[var(--color-ink)]',
    'hover:bg-[var(--color-border)]',
  ].join(' '),
  outline: [
    'border border-[var(--color-border-strong)] bg-[var(--color-surface)] text-[var(--color-ink)]',
    'shadow-[var(--shadow-card)] hover:bg-[var(--color-surface-muted)]',
  ].join(' '),
  ghost:
    'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]',
  danger: [
    'bg-[var(--color-status-lost)] text-white',
    'shadow-[inset_0_1px_0_0_rgb(255_255_255/0.16),var(--shadow-card)]',
    'hover:brightness-[1.07]',
  ].join(' '),
}

const sizes: Record<Size, string> = {
  sm: 'h-8 px-3 text-[0.75rem] gap-1.5',
  md: 'h-9 px-4 text-[0.8125rem] gap-2',
  lg: 'h-10 px-5 text-[0.875rem] gap-2',
  icon: 'size-9 rounded-[var(--radius-control)]',
}

/*
 * Pill-shaped, which is the one decision that carries most of this theme.
 *
 * A rounded rectangle reads as a form control; a pill reads as an action. On a
 * dashboard where most buttons are verbs — call, send, sync — the second is
 * what they are, and it is also what stops a row of them looking like a
 * toolbar.
 */
const base = [
  'pressable inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap',
  'rounded-[var(--radius-pill)]',
  'disabled:pointer-events-none disabled:opacity-45',
].join(' ')

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  icon?: ReactNode
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  icon,
  className,
  children,
  disabled,
  ...props
}: ButtonProps) {
  return (
    <button
      type="button"
      // A loading button stays mounted and keeps its width, so the layout
      // never jumps while a request is in flight.
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(base, variants[variant], sizes[size], className)}
      {...props}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {size !== 'icon' && children}
    </button>
  )
}

/**
 * The button's own classes, for the one case neither Button nor ButtonLink
 * covers: internal navigation, which belongs to the router's <Link> so the app
 * does not reload. Exported rather than copied so a change to the button's look
 * reaches those links too.
 */
export function buttonClasses(variant: Variant = 'primary', size: Size = 'md', className?: string) {
  return cn(base, variants[variant], sizes[size], className)
}

/**
 * A link that looks like a button, for the cases where the action really is
 * navigation the browser must handle itself — `tel:` and `https://wa.me/…`
 * among them. A <button> with an onClick cannot open those; a styled <a> can,
 * and keeps the keyboard and long-press behaviour the platform already gives
 * links. It shares `base`, `variants` and `sizes` with Button rather than
 * restating them, so the two cannot drift apart.
 */
export function ButtonLink({
  variant = 'primary',
  size = 'md',
  icon,
  className,
  children,
  ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & {
  variant?: Variant
  size?: Size
  icon?: ReactNode
}) {
  return (
    <a className={cn(base, variants[variant], sizes[size], className)} {...props}>
      {icon}
      {size !== 'icon' && children}
    </a>
  )
}
