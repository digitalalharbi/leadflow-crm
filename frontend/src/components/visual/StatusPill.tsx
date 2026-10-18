import {
  AlertCircle, AlertTriangle, CalendarDays, CheckCircle2, Clock3, Hourglass, Pause, Play, Check,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { TONE_INK, TONE_TINT, type Tone } from './tones'
import { cn } from '@/lib/cn'

/**
 * The words a state is allowed to have.
 *
 * Nine, each one word or two, and each with its own icon and tone: a state is
 * read from the badge's colour and shape first and its word second, so the
 * vocabulary is short enough to learn once. No sentence explains a state —
 * if it needs explaining, the screen has the wrong state.
 */
export type StatusKey =
  | 'healthy'
  | 'attention'
  | 'risk'
  | 'overdue'
  | 'pending'
  | 'completed'
  | 'active'
  | 'paused'
  | 'today'

export const STATUS: Record<StatusKey, { tone: Tone; Icon: LucideIcon }> = {
  healthy: { tone: 'good', Icon: CheckCircle2 },
  attention: { tone: 'warn', Icon: AlertCircle },
  risk: { tone: 'bad', Icon: AlertTriangle },
  overdue: { tone: 'bad', Icon: Clock3 },
  pending: { tone: 'neutral', Icon: Hourglass },
  completed: { tone: 'good', Icon: Check },
  active: { tone: 'good', Icon: Play },
  paused: { tone: 'neutral', Icon: Pause },
  today: { tone: 'brand', Icon: CalendarDays },
}

export function StatusPill({
  status,
  className,
  iconOnly = false,
  testId,
}: {
  status: StatusKey
  className?: string
  /** A dot-sized badge for dense rows; the word stays as the accessible name. */
  iconOnly?: boolean
  testId?: string
}) {
  const { t } = useTranslation()
  const { tone, Icon } = STATUS[status]
  const label = t(`visual.status.${status}`)

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 whitespace-nowrap rounded-full text-[0.75rem] font-medium',
        iconOnly ? 'size-6 justify-center' : 'px-2.5 py-0.5',
        className,
      )}
      style={{ background: TONE_TINT[tone], color: TONE_INK[tone] }}
      data-status={status}
      data-tone={tone}
      data-testid={testId}
      title={iconOnly ? label : undefined}
    >
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {iconOnly ? <span className="sr-only">{label}</span> : label}
    </span>
  )
}
