import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, ArrowRight, CalendarX2, Clock3, Filter, Handshake, KeyRound, Megaphone, Siren, Trophy, X } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { dismissAnomaly, fetchInsights, type InsightCard, type InsightTone } from '@/features/insights'
import { cn } from '@/lib/cn'
import { useAuth } from '@/stores/auth'

const ICON: Record<InsightCard['key'], LucideIcon> = {
  holds_expiring: KeyRound, interested_waiting: Clock3, visit_gap: CalendarX2, no_shows: CalendarX2,
  funnel_leak: Filter, best_campaign: Trophy, wasted_spend: Megaphone, top_partner: Handshake,
}

const TONE: Record<InsightTone, string> = {
  bad: 'var(--color-danger)', warn: 'var(--color-warning)', good: 'var(--brand)', neutral: 'var(--color-ink-muted)',
}

/**
 * What to look at first: anything that moved outside its normal range, then a few short facts —
 * each one a number, a few words and a place to go. Silent when there is nothing to say; no strip
 * at all is better than a confident sentence built on five leads.
 */
export function InsightCards() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const Arrow = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight

  const { data } = useQuery({ queryKey: ['insights'], queryFn: fetchInsights, staleTime: 2 * 60_000, refetchInterval: 5 * 60_000 })

  const dismiss = useMutation({
    mutationFn: dismissAnomaly,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['insights'] }),
  })

  if (!data || (data.cards.length === 0 && data.anomalies.length === 0)) return null

  const canDismiss = user?.role === 'admin' || user?.role === 'manager'
  const num = (v: number) => new Intl.NumberFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US').format(v)

  return (
    <section className="space-y-2" data-testid="insights" aria-label={t('insights.title')}>
      {data.anomalies.length > 0 && (
        <ul className="grid gap-2 md:grid-cols-2" data-testid="anomalies">
          {data.anomalies.map((a) => (
            <li
              key={a.id}
              data-testid={`anomaly-${a.kind}`}
              data-severity={a.severity}
              className={cn('flex items-center gap-2.5 rounded-xl px-3 py-2', a.severity === 'critical' ? 'bg-[color-mix(in_srgb,var(--color-danger)_10%,transparent)]' : 'bg-[color-mix(in_srgb,var(--color-warning)_12%,transparent)]')}
            >
              <span className="grid size-8 shrink-0 place-items-center rounded-full text-white" style={{ background: a.severity === 'critical' ? 'var(--color-danger)' : 'var(--color-warning)' }} aria-hidden>
                {a.severity === 'critical' ? <Siren className="size-4" /> : <AlertTriangle className="size-4" />}
              </span>
              <p className="min-w-0 flex-1 text-[0.9375rem] leading-snug">
                <strong>{t(`insights.anomaly.${a.kind}`)}{a.subject ? ` — ${a.subject}` : ''}</strong>
                <span className="ms-1.5 tabular-nums text-[var(--color-ink-muted)]" dir="ltr">{num(a.observed)} / {num(a.expected)}</span>
              </p>
              {canDismiss && (
                <button type="button" aria-label={t('insights.dismiss')} title={t('insights.dismiss')} data-testid={`dismiss-${a.kind}`} onClick={() => dismiss.mutate(a.id)} className="pressable grid size-7 shrink-0 place-items-center rounded-full hover:bg-black/10">
                  <X className="size-4" aria-hidden />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {data.cards.length > 0 && (
        <ul className="grid grid-cols-2 gap-2 lg:grid-cols-3 xl:grid-cols-6" data-testid="insight-cards">
          {data.cards.map((card) => {
            const Icon = ICON[card.key]
            const body = (
              <>
                <span className="grid size-8 shrink-0 place-items-center rounded-full" style={{ background: `color-mix(in srgb, ${TONE[card.tone]} 16%, transparent)`, color: TONE[card.tone] }} aria-hidden>
                  <Icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1 text-[0.9375rem] font-semibold leading-snug">
                  {t(`insights.card.${card.key}`, { ...Object.fromEntries(Object.entries(card.values).map(([k, v]) => [k, typeof v === 'number' ? num(v) : v])), count: typeof card.values.count === 'number' ? card.values.count : undefined, from: card.values.from ? t(`revenue.step.${card.values.from === 'visit' ? 'visits' : card.values.from === 'reservation' ? 'reservations' : card.values.from}`) : '', to: card.values.to ? t(`revenue.step.${card.values.to === 'visit' ? 'visits' : card.values.to === 'reservation' ? 'reservations' : card.values.to}`) : '' })}
                </span>
                {card.href && <Arrow className="size-4 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />}
              </>
            )

            return (
              <li key={card.key} data-testid={`insight-${card.key}`} data-tone={card.tone}>
                {card.href ? (
                  <Link to={card.href} className="pressable flex h-full items-center gap-2 rounded-xl bg-[var(--color-surface)] px-2.5 py-2 shadow-[var(--shadow-card)]">{body}</Link>
                ) : (
                  <div className="flex h-full items-center gap-2 rounded-xl bg-[var(--color-surface)] px-2.5 py-2 shadow-[var(--shadow-card)]">{body}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
