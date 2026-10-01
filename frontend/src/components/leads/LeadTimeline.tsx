import {
  Ban, CalendarCheck, CalendarPlus, Check, ClipboardCheck, Flame, MessageCircle,
  Phone, PhoneOff, RefreshCw, Send, Sparkles, StickyNote, TrendingUp, UserPlus,
  X, Mail, Pencil,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatRelative } from '@/lib/format'
import { EmptyState } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { LeadActivity } from '@/types/api'

/** Icon + tone per activity type, so the timeline is scannable without reading. */
/**
 * Icon + tone per activity type, so the timeline is scannable without reading.
 *
 * Tones are mixed from the status tokens rather than taken from a palette:
 * the same swap that gives the app a dark mode gives the timeline one, and a
 * tenant's stage colours stay consistent with their badges.
 */
function dot(variable: string): string {
  return `bg-[color-mix(in_srgb,var(${variable})_14%,transparent)] text-[color-mix(in_srgb,var(${variable})_85%,var(--color-ink))]`
}

const NEUTRAL = 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]'

const STYLES: Record<string, { Icon: typeof Check; tone: string }> = {
  created: { Icon: Sparkles, tone: dot('--color-status-new') },
  assigned: { Icon: UserPlus, tone: dot('--color-status-contacted') },
  status_changed: { Icon: RefreshCw, tone: NEUTRAL },
  temperature_changed: { Icon: Flame, tone: dot('--color-temp-warm') },
  note_added: { Icon: StickyNote, tone: NEUTRAL },
  called: { Icon: Phone, tone: dot('--color-status-qualified') },
  no_answer: { Icon: PhoneOff, tone: NEUTRAL },
  whatsapp_clicked: { Icon: MessageCircle, tone: dot('--color-status-won') },
  whatsapp_sent: { Icon: Send, tone: dot('--color-status-won') },
  whatsapp_received: { Icon: MessageCircle, tone: dot('--color-status-won') },
  whatsapp_opted_out: { Icon: Ban, tone: dot('--color-status-lost') },
  email_sent: { Icon: Mail, tone: NEUTRAL },
  follow_up_created: { Icon: CalendarPlus, tone: dot('--color-status-contacted') },
  follow_up_completed: { Icon: CalendarCheck, tone: dot('--color-status-won') },
  follow_up_outcome: { Icon: CalendarCheck, tone: dot('--color-status-won') },
  appointment_created: { Icon: CalendarCheck, tone: dot('--color-status-appointment') },
  qualification_updated: { Icon: ClipboardCheck, tone: dot('--color-status-qualified') },
  score_changed: { Icon: TrendingUp, tone: dot('--color-temp-warm') },
  won: { Icon: Check, tone: dot('--color-status-won') },
  lost: { Icon: X, tone: dot('--color-status-lost') },
  re_engaged: { Icon: RefreshCw, tone: dot('--color-status-new') },
  updated: { Icon: Pencil, tone: NEUTRAL },
}

export function LeadTimeline({ activities }: { activities: LeadActivity[] }) {
  const { t, i18n } = useTranslation()

  if (activities.length === 0) {
    return <EmptyState title={t('leads.noNotes')} className="py-10" />
  }

  return (
    <ol className="relative space-y-0 p-5" data-testid="activity-timeline">
      {activities.map((activity, index) => {
        const style = STYLES[activity.type] ?? STYLES.updated
        const Icon = style.Icon
        const isLast = index === activities.length - 1

        // Status changes carry from/to in meta; render them as a readable sentence.
        const from = activity.meta?.from as string | undefined
        const to = activity.meta?.to as string | undefined

        return (
          <li key={activity.id} className="relative flex gap-3 pb-5 last:pb-0">
            {!isLast && (
              <span
                className="absolute top-8 bottom-0 start-[0.9375rem] w-px bg-[var(--color-border)]"
                aria-hidden
              />
            )}

            <span className={cn('relative z-10 grid size-8 shrink-0 place-items-center rounded-full', style.tone)}>
              <Icon className="size-4" aria-hidden />
            </span>

            <div className="min-w-0 flex-1 pt-1">
              <p className="text-[0.875rem]">
                <span className="font-medium">
                  {t(`activity.${activity.type}`, {
                    defaultValue: t(`status.${activity.type}`, { defaultValue: activity.type }),
                  })}
                </span>
                {activity.type === 'status_changed' && from && to && (
                  <span className="text-[var(--color-ink-muted)]">
                    {' '}
                    {t(`status.${from}`, { defaultValue: from })} → {t(`status.${to}`, { defaultValue: to })}
                  </span>
                )}

                {/* A score that moved is only interesting with both numbers;
                    delivery receipts are deliberately not here at all — they
                    live on the message itself, or they bury everything else. */}
                {activity.type === 'score_changed' && (
                  <span dir="ltr" className="tabular text-[var(--color-ink-muted)]">
                    {' '}
                    {String(activity.meta?.from ?? '')} → {String(activity.meta?.to ?? '')}
                  </span>
                )}

                {activity.type === 'follow_up_outcome' && Boolean(activity.meta?.outcome) && (
                  <span className="text-[var(--color-ink-muted)]">
                    {' '}
                    {t(`outcomes.${String(activity.meta?.outcome)}`, {
                      defaultValue: String(activity.meta?.outcome),
                    })}
                  </span>
                )}
              </p>

              {activity.description && (
                <p className="mt-0.5 text-[0.8125rem] text-[var(--color-ink-muted)]">{activity.description}</p>
              )}

              <p className="mt-0.5 text-[0.75rem] text-[var(--color-ink-subtle)]">
                {activity.user?.name ? `${activity.user.name} · ` : ''}
                {formatRelative(activity.created_at, i18n.language)}
              </p>
            </div>
          </li>
        )
      })}
    </ol>
  )
}
