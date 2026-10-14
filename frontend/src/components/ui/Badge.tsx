import { cn } from '@/lib/cn'
import type { LeadStatus, LeadTemperature } from '@/types/api'
import { useTranslation } from 'react-i18next'

/**
 * Status and temperature are the two things a rep scans for, so they get a
 * consistent colour everywhere — table, board, detail header, dashboard.
 */
/*
 * Built from the stage tokens rather than Tailwind's palette, so a badge, a
 * pipeline column header and a chart segment are the same colour by
 * construction — and so dark mode is one variable swap, not sixty classes.
 */
function toneFor(variable: string): string {
  return [
    `bg-[color-mix(in_srgb,var(${variable})_12%,transparent)]`,
    `text-[color-mix(in_srgb,var(${variable})_82%,var(--color-ink))]`,
    `ring-[color-mix(in_srgb,var(${variable})_24%,transparent)]`,
  ].join(' ')
}

const statusTone: Record<LeadStatus, string> = {
  new: toneFor('--color-status-new'),
  contacted: toneFor('--color-status-contacted'),
  replied: toneFor('--color-status-replied'),
  qualified: toneFor('--color-status-qualified'),
  appointment: toneFor('--color-status-appointment'),
  negotiation: toneFor('--color-status-negotiation'),
  won: toneFor('--color-status-won'),
  lost: toneFor('--color-status-lost'),
  invalid: toneFor('--color-status-invalid'),
  no_response: toneFor('--color-status-no_response'),
}

const tempTone: Record<LeadTemperature, string> = {
  hot: toneFor('--color-temp-hot'),
  warm: toneFor('--color-temp-warm'),
  cold: toneFor('--color-temp-cold'),
}

/*
 * A tinted token, not an outline.
 *
 * The ring was doing the work of saying "this is a thing"; a soft fill says it
 * more quietly and survives being next to five others, which is what a status
 * column actually is.
 */
const base = [
  'inline-flex items-center gap-1.5 whitespace-nowrap',
  'rounded-[var(--radius-pill)] px-2.5 py-0.5 text-[0.75rem] font-medium',
  'ring-1 ring-inset',
].join(' ')

export function StatusBadge({
  status,
  className,
  testId,
}: {
  status: LeadStatus
  className?: string
  testId?: string
}) {
  const { t } = useTranslation()

  return (
    <span className={cn(base, statusTone[status], className)} data-testid={testId}>
      {t(`status.${status}`)}
    </span>
  )
}

export function TemperatureBadge({
  temperature,
  className,
  testId,
}: {
  temperature: LeadTemperature
  className?: string
  testId?: string
}) {
  const { t } = useTranslation()

  return (
    <span className={cn(base, tempTone[temperature], className)} data-testid={testId}>
      <span
        className={cn(
          'size-1.5 rounded-full',
          temperature === 'hot' && 'bg-[var(--color-temp-hot)]',
          temperature === 'warm' && 'bg-[var(--color-temp-warm)]',
          temperature === 'cold' && 'bg-[var(--color-temp-cold)]',
        )}
        aria-hidden
      />
      {t(`temperature.${temperature}`)}
    </span>
  )
}

export function Badge({
  children,
  tone = 'neutral',
  className,
  testId,
}: {
  children: React.ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
  className?: string
  testId?: string
}) {
  const tones = {
    neutral: 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)] ring-[var(--color-line)]',
    success: toneFor('--color-status-won'),
    warning: toneFor('--color-status-appointment'),
    danger: toneFor('--color-status-lost'),
    info: toneFor('--color-status-new'),
  }

  return (
    <span className={cn(base, tones[tone], className)} data-testid={testId}>
      {children}
    </span>
  )
}
