import type { ReactNode } from 'react'
import { ArrowLeft, ArrowRight, CheckCircle2, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

export interface BarAction {
  href?: string
  external?: boolean
  onClick?: () => void
  disabled?: boolean
}

/**
 * The four things a rep does with a lead, at the thumb, always in view: WhatsApp, Call, Outcome, Next.
 *
 * Fixed to the bottom edge and padded by the device's own safe area (the iPhone's home indicator), so in
 * a home-screen app it never hides behind the system bar. Each control is at least 56px tall — the
 * platform guidelines ask for 44 — and the label is always written, never an icon alone. A control
 * that cannot be used says why in the line above the bar instead of quietly doing nothing.
 */
export function SalesActionBar({
  whatsapp,
  call,
  onOutcome,
  onNext,
  nextLabel,
  problem,
  busy = false,
  pulseOutcome = false,
}: {
  whatsapp: BarAction
  call: BarAction
  onOutcome: () => void
  onNext: () => void
  nextLabel?: string
  /** A reason one of the controls cannot be used, shown above the bar. */
  problem?: string | null
  busy?: boolean
  /** A call was started: the Outcome control is what the rep needs next. */
  pulseOutcome?: boolean
}) {
  const { t, i18n } = useTranslation()
  const Next = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight

  return (
    <div className="sales-bar fixed inset-x-0 bottom-0 z-40 lg:hidden print:hidden" data-testid="sales-bar">
      {problem && (
        <p role="status" className="px-4 pb-1.5 pt-2 text-center text-[0.9375rem] font-medium text-[var(--color-danger)]" data-testid="sales-problem">
          {problem}
        </p>
      )}

      <nav aria-label={t('mobileSales.bar.label')} className="grid grid-cols-4 gap-1.5 px-2 pt-2">
        <Control action={whatsapp} testId="sales-whatsapp" icon={<MessageCircle className="size-6" aria-hidden />} label={t('mobileSales.bar.whatsapp')} />
        <Control action={call} testId="sales-call" tone="primary" icon={<Phone className="size-6" aria-hidden />} label={t('mobileSales.bar.call')} />
        <Control action={{ onClick: onOutcome, disabled: busy }} testId="sales-outcome" pulse={pulseOutcome} icon={<CheckCircle2 className="size-6" aria-hidden />} label={t('mobileSales.bar.outcome')} />
        <Control action={{ onClick: onNext, disabled: busy }} testId="sales-next" icon={<Next className="size-6" aria-hidden />} label={nextLabel ?? t('mobileSales.bar.next')} />
      </nav>
    </div>
  )
}

function Control({
  action,
  icon,
  label,
  testId,
  tone = 'neutral',
  pulse = false,
}: {
  action: BarAction
  icon: ReactNode
  label: string
  testId: string
  tone?: 'neutral' | 'primary'
  pulse?: boolean
}) {
  const className = cn(
    'sales-action pressable flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 rounded-2xl px-1 text-[0.8125rem] font-semibold leading-tight',
    tone === 'primary'
      ? 'bg-[var(--brand)] text-[var(--brand-contrast)]'
      : 'bg-[var(--color-surface-muted)] text-[var(--color-ink)] ring-1 ring-inset ring-[var(--color-line)]',
    pulse && 'ring-2 ring-[var(--brand)]',
    action.disabled && 'pointer-events-none opacity-45',
  )

  const inner = (
    <>
      {icon}
      <span className="max-w-full truncate">{label}</span>
    </>
  )

  if (action.href && !action.disabled) {
    return (
      <a
        href={action.href}
        target={action.external ? '_blank' : undefined}
        rel={action.external ? 'noreferrer' : undefined}
        onClick={action.onClick}
        className={className}
        data-testid={testId}
      >
        {inner}
      </a>
    )
  }

  return (
    <button type="button" onClick={action.onClick} disabled={action.disabled} aria-disabled={action.disabled || undefined} className={className} data-testid={testId}>
      {inner}
    </button>
  )
}
