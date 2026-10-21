import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bot, CalendarCheck, Coins, Flame, KeyRound, Sparkles, TimerOff, UserCheck, Users, Workflow, Megaphone, Radar, Inbox } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchOps, type Light, type OpsPulseData } from '@/features/ops'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Skeleton } from '@/components/ui'

const LIGHT: Record<Light, string> = { ok: 'var(--color-success)', watch: 'var(--color-warning)', bad: 'var(--color-danger)' }

type Answer = keyof OpsPulseData['answers']

/** The nine questions, each with the list that proves the number. */
const TILES: { key: Answer; icon: LucideIcon; to: string; alarming?: boolean }[] = [
  { key: 'leads_today', icon: Users, to: '/leads?date_from=today' },
  { key: 'interested', icon: Sparkles, to: '/leads?view=interested' },
  { key: 'hot', icon: Flame, to: '/leads?temperature=hot' },
  // Who automation handed over and nobody has picked up; then who is past their reply window.
  { key: 'needs_human', icon: Bot, to: '/leads?view=needs_human', alarming: true },
  { key: 'visits_today', icon: CalendarCheck, to: '/visits' },
  { key: 'reservations_pending', icon: KeyRound, to: '/visits' },
  { key: 'won_today', icon: UserCheck, to: '/leads?status=won' },
  { key: 'revenue_today', icon: Coins, to: '/reports' },
  { key: 'sla_late', icon: TimerOff, to: '/work', alarming: true },
]

const HEALTH: { key: 'automation' | 'tracking' | 'campaigns' | 'backlog'; icon: LucideIcon; to: string }[] = [
  { key: 'automation', icon: Workflow, to: '/automation' },
  { key: 'tracking', icon: Radar, to: '/tracking' },
  { key: 'campaigns', icon: Megaphone, to: '/campaigns' },
  { key: 'backlog', icon: Inbox, to: '/work' },
]

/**
 * The operations home: how today is going in nine numbers, and four lights for the machinery —
 * automation, tracking, campaigns and the sales backlog. Every tile opens the list it counts.
 */
export function OpsPulse() {
  const { t, i18n } = useTranslation()
  const { data } = useQuery({ queryKey: ['ops-pulse'], queryFn: fetchOps, refetchInterval: 60_000, staleTime: 30_000 })

  // The space is held while the numbers load: a chart below must not jump under a resting cursor.
  if (!data) {
    return (
      <section className="space-y-2" data-testid="ops-pulse-loading" aria-busy="true">
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-9">
          {TILES.map((tile) => <Skeleton key={tile.key} className="h-[82px] rounded-xl" />)}
        </div>
        <div className="flex flex-wrap gap-2">
          {HEALTH.map((h) => <Skeleton key={h.key} className="h-8 w-28 rounded-full" />)}
        </div>
      </section>
    )
  }

  const num = (v: number) => formatNumber(v, i18n.language)

  return (
    <section className="space-y-2" data-testid="ops-pulse" aria-label={t('ops.title')}>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-9" data-testid="ops-answers">
        {TILES.filter((tile) => data.answers[tile.key] !== undefined).map(({ key, icon: Icon, to, alarming }) => {
          const value = data.answers[key] as number
          const hot = alarming && value > 0

          return (
            <Link
              key={key}
              to={to}
              data-testid={`ops-${key}`}
              className={cn('pressable flex flex-col gap-1 rounded-xl px-2.5 py-2 shadow-[var(--shadow-card)]', hot ? 'bg-[color-mix(in_srgb,var(--color-danger)_9%,var(--color-surface))]' : 'bg-[var(--color-surface)]')}
            >
              <Icon className={cn('size-4', hot ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-muted)]')} aria-hidden />
              <span className={cn('text-[1.375rem] font-extrabold leading-none tabular-nums', hot && 'text-[var(--color-danger)]')} dir="ltr" data-testid={`ops-${key}-value`}>
                {key === 'revenue_today' ? formatMoneyCompact(value, 'SAR', i18n.language) : num(value)}
              </span>
              <span className="t-caption truncate">{t(`ops.answer.${key}`)}</span>
            </Link>
          )
        })}
      </div>

      {data.health && (
        <ul className="flex flex-wrap gap-2" data-testid="ops-health">
          {HEALTH.map(({ key, icon: Icon, to }) => {
            const h = data.health![key]

            return (
              <li key={key}>
                <Link to={to} data-testid={`health-${key}`} data-state={h.state} className="pressable inline-flex items-center gap-2 rounded-full bg-[var(--color-surface)] py-1 pe-3 ps-1.5 shadow-[var(--shadow-card)]">
                  <span className="grid size-6 place-items-center rounded-full text-white" style={{ background: LIGHT[h.state] }} aria-hidden><Icon className="size-3.5" /></span>
                  <span className="text-[0.9375rem] font-semibold">{t(`ops.health.${key}`)}</span>
                  <span className="t-caption">{h.state === 'ok' ? t('ops.ok') : <span className="font-bold tabular-nums" dir="ltr">{num(h.count)}</span>}</span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
