import type { ButtonHTMLAttributes, ReactNode } from 'react'
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
  sm: 'h-8 px-3 text-[0.8125rem] gap-1.5',
  md: 'h-10 px-4 text-sm gap-2',
  lg: 'h-11 px-5 text-[0.9375rem] gap-2',
  icon: 'size-9',
}

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
      className={cn(
        'pressable inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap',
        'rounded-[var(--radius-control)]',
        'disabled:pointer-events-none disabled:opacity-45',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {size !== 'icon' && children}
    </button>
  )
}
