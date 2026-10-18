import {
  CalendarPlus, ClipboardCheck, Clock, MessageCircleReply, Phone, PhoneCall, UserPlus,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { NextAction } from '@/types/api'
import { cn } from '@/lib/cn'

const ICON: Record<string, LucideIcon> = {
  call_now: PhoneCall,
  reply_now: MessageCircleReply,
  follow_up: Phone,
  book_meeting: CalendarPlus,
  schedule_follow_up: Clock,
  assign: UserPlus,
  review_fit: ClipboardCheck,
}

const TONE: Record<string, string> = {
  critical: 'var(--color-danger)',
  high: 'var(--color-warning)',
  medium: 'var(--brand)',
  low: 'var(--color-ink-muted)',
}

/**
 * The one thing to do with this lead, as a button.
 *
 * "Next best action" is only useful if it is also the way to do it: a line of
 * advice above a row of unrelated buttons makes the rep translate. Here the
 * advice is the button — coloured by how urgent it is, with the reason beneath
 * in a few words. It is not drawn at all when there is nothing to do; a banner
 * that says "nothing to do" on every quiet lead is a banner people stop seeing.
 */
export function NextActionBanner({
  action,
  onAct,
}: {
  action: NextAction | undefined
  onAct: (action: string) => void
}) {
  const { t } = useTranslation()

  if (!action || action.action === 'wait' || action.action === 'none') return null

  const Icon = ICON[action.action] ?? Clock
  const color = TONE[action.urgency] ?? TONE.low

  return (
    <button
      type="button"
      onClick={() => onAct(action.action)}
      data-testid="next-best-action"
      data-urgency={action.urgency}
      className={cn(
        'flex w-full items-center gap-3 rounded-[var(--radius-card)] p-3 pe-4 text-start transition-shadow',
        'bg-[var(--color-surface)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-raised)]',
      )}
      style={{ boxShadow: `inset 0 0 0 1.5px color-mix(in srgb, ${color} 45%, transparent)` }}
    >
      <span
        className="grid size-11 shrink-0 place-items-center rounded-full"
        style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
        aria-hidden
      >
        <Icon className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[1rem] font-semibold leading-tight">{t(`work.action.${action.action}`)}</span>
        <span className="mt-0.5 block truncate text-[0.75rem] text-[var(--color-ink-muted)]">{action.reason}</span>
      </span>
    </button>
  )
}
