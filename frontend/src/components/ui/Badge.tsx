import { cn } from '@/lib/cn'
import type { LeadStatus, LeadTemperature } from '@/types/api'
import { useTranslation } from 'react-i18next'

/**
 * Status and temperature are the two things a rep scans for, so they get a
 * consistent colour everywhere — table, board, detail header, dashboard.
 */
const statusTone: Record<LeadStatus, string> = {
  new: 'bg-blue-50 text-blue-700 ring-blue-600/15',
  contacted: 'bg-violet-50 text-violet-700 ring-violet-600/15',
  qualified: 'bg-cyan-50 text-cyan-700 ring-cyan-600/15',
  appointment: 'bg-amber-50 text-amber-700 ring-amber-600/15',
  won: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
  lost: 'bg-red-50 text-red-700 ring-red-600/15',
}

const tempTone: Record<LeadTemperature, string> = {
  hot: 'bg-red-50 text-red-700 ring-red-600/15',
  warm: 'bg-amber-50 text-amber-700 ring-amber-600/15',
  cold: 'bg-sky-50 text-sky-700 ring-sky-600/15',
}

const base =
  'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[0.75rem] font-medium ring-1 ring-inset whitespace-nowrap'

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
          temperature === 'hot' && 'bg-red-500',
          temperature === 'warm' && 'bg-amber-500',
          temperature === 'cold' && 'bg-sky-500',
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
}: {
  children: React.ReactNode
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
  className?: string
}) {
  const tones = {
    neutral: 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)] ring-[var(--color-border)]',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
    warning: 'bg-amber-50 text-amber-700 ring-amber-600/15',
    danger: 'bg-red-50 text-red-700 ring-red-600/15',
    info: 'bg-blue-50 text-blue-700 ring-blue-600/15',
  }

  return <span className={cn(base, tones[tone], className)}>{children}</span>
}
