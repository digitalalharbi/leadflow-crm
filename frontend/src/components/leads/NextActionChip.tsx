import {
  CalendarPlus, ClipboardCheck, Clock, FileText, Mail, MessageCircle, MessageCircleReply, Phone, PhoneCall, Sprout, UserPlus, XCircle,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

const ICON: Record<string, LucideIcon> = {
  call_now: PhoneCall,
  reply_now: MessageCircleReply,
  follow_up: Phone,
  book_meeting: CalendarPlus,
  send_whatsapp: MessageCircle,
  send_email: Mail,
  send_brochure: FileText,
  nurture: Sprout,
  close_lost: XCircle,
  schedule_follow_up: Clock,
  assign: UserPlus,
  review_fit: ClipboardCheck,
}

const TONE: Record<string, string> = {
  critical: 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
  high: 'bg-[color-mix(in_srgb,var(--color-warning)_14%,transparent)] text-[var(--color-warning)]',
  medium: 'bg-[var(--brand-soft)] text-[var(--brand)]',
  low: 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]',
}

/**
 * What to do with this lead, in a word, coloured by how soon.
 *
 * "Wait" and "none" are not shown: a chip on every row that says there is
 * nothing to do teaches people to ignore the chip, and the rows that *do* have
 * something to do are the ones it exists for. The reason is the tooltip — it is
 * the sentence the work queue shows, so the list and the queue cannot disagree.
 */
export function NextActionChip({
  action,
}: {
  action: { action: string; urgency: string; reason: string } | undefined
}) {
  const { t } = useTranslation()

  if (!action || action.action === 'wait' || action.action === 'none') return null

  const Icon = ICON[action.action] ?? Clock

  return (
    <span
      title={action.reason}
      data-testid="next-action"
      data-urgency={action.urgency}
      className={cn(
        'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[0.875rem] font-medium',
        TONE[action.urgency] ?? TONE.low,
      )}
    >
      <Icon className="size-3" aria-hidden />
      {t(`work.action.${action.action}`)}
    </span>
  )
}
