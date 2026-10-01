import {
  CalendarCheck, CalendarPlus, Check, Flame, MessageCircle, Phone, PhoneOff,
  RefreshCw, Sparkles, StickyNote, UserPlus, X, Mail, Pencil,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatRelative } from '@/lib/format'
import { EmptyState } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { LeadActivity } from '@/types/api'

/** Icon + tone per activity type, so the timeline is scannable without reading. */
const STYLES: Record<string, { Icon: typeof Check; tone: string }> = {
  created: { Icon: Sparkles, tone: 'bg-blue-50 text-blue-600' },
  assigned: { Icon: UserPlus, tone: 'bg-violet-50 text-violet-600' },
  status_changed: { Icon: RefreshCw, tone: 'bg-slate-100 text-slate-600' },
  temperature_changed: { Icon: Flame, tone: 'bg-amber-50 text-amber-600' },
  note_added: { Icon: StickyNote, tone: 'bg-slate-100 text-slate-600' },
  called: { Icon: Phone, tone: 'bg-cyan-50 text-cyan-600' },
  no_answer: { Icon: PhoneOff, tone: 'bg-slate-100 text-slate-500' },
  whatsapp_clicked: { Icon: MessageCircle, tone: 'bg-emerald-50 text-emerald-600' },
  email_sent: { Icon: Mail, tone: 'bg-slate-100 text-slate-600' },
  follow_up_created: { Icon: CalendarPlus, tone: 'bg-indigo-50 text-indigo-600' },
  follow_up_completed: { Icon: CalendarCheck, tone: 'bg-emerald-50 text-emerald-600' },
  appointment_created: { Icon: CalendarCheck, tone: 'bg-amber-50 text-amber-600' },
  won: { Icon: Check, tone: 'bg-emerald-50 text-emerald-600' },
  lost: { Icon: X, tone: 'bg-red-50 text-red-600' },
  re_engaged: { Icon: RefreshCw, tone: 'bg-blue-50 text-blue-600' },
  updated: { Icon: Pencil, tone: 'bg-slate-100 text-slate-500' },
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
