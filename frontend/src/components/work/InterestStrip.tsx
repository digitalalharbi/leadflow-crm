import { Clock3, Hourglass, MapPin, Target, Wallet } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { InterestBadge } from '@/components/visual/InterestBadge'
import { RingProgress } from '@/components/visual'
import type { Tone } from '@/components/visual/tones'
import { formatCurrency, formatLastSeen } from '@/lib/format'
import type { AssignmentWhy, NextInQueue, SlaState } from '@/features/work'
import { cn } from '@/lib/cn'

const TONE: Record<SlaState, Tone> = { ok: 'good', due_soon: 'warn', breached: 'bad', done: 'neutral' }

/**
 * The fact row a rep needs in the first second of the call: how far along the lead is, how long
 * they may still wait (a ring, because a number on a clock is read slower than a shape), and
 * what they want — project, budget, intent, why now, how long they have waited, what happened last.
 *
 * Facts the lead has not given are left out rather than shown as dashes: a row of empty cells
 * reads as a form to fill in, and the rep is about to call, not to fill in a form.
 */
export function InterestStrip({ data }: { data: NextInQueue }) {
  const { t, i18n } = useTranslation()
  const { interest, sla, profile } = data

  if (!interest) return null

  const money = (value: number) => formatCurrency(value, 'SAR', i18n.language)
  const budget = profile?.budget_max != null && profile?.budget_min != null && profile.budget_min !== profile.budget_max
    ? `${money(profile.budget_min)} – ${money(profile.budget_max)}`
    : profile?.budget_max != null
      ? t('work.profile.upTo', { value: money(profile.budget_max) })
      : profile?.budget_min != null
        ? t('work.profile.from', { value: money(profile.budget_min) })
        : null

  const value = (group: string, raw?: string) => (raw ? t(`${group}.${raw}`, { defaultValue: raw }) : null)

  const intent = [
    value('conversation.purpose', profile?.purpose),
    value('conversation.timeline', profile?.timeline),
    profile?.district,
  ].filter(Boolean).join(' · ')

  const waiting = profile?.waiting_since ? formatLastSeen(profile.waiting_since, i18n.language) : null

  /*
   * Four chips, not six: why this lead is first is the card's reason line just above, and what
   * happened last is the history beside it. Repeating them here would only make the card taller
   * than a laptop screen, which is the one place a rep reads it.
   */
  const facts: Array<{ key: string; icon: LucideIcon; label: string; text: string | null }> = [
    { key: 'project', icon: MapPin, label: t('work.profile.project'), text: [profile?.project, profile?.property].filter(Boolean).join(' · ') || null },
    { key: 'budget', icon: Wallet, label: t('work.profile.budget'), text: budget },
    { key: 'intent', icon: Target, label: t('work.profile.intent'), text: intent || null },
    { key: 'waiting', icon: Hourglass, label: t('work.profile.waiting'), text: waiting },
  ]

  const left = sla ? Math.abs(sla.minutes_left) : 0
  const percent = sla ? Math.max(0, Math.min(100, Math.round((Math.max(0, sla.minutes_left) / Math.max(1, sla.target_minutes)) * 100))) : 0

  return (
    <div className="space-y-1" data-testid="work-interest">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-[var(--color-surface)]/70 p-1.5">
        <div className="flex items-center gap-2">
          {sla ? (
            <RingProgress
              value={sla.state === 'breached' ? 100 : percent}
              size={44}
              thickness={5}
              tone={TONE[sla.state]}
              label={t(`work.sla.${sla.state}`)}
              testId="work-sla-ring"
            >
              <span className="text-[0.8125rem] font-extrabold tabular-nums" dir="ltr">{sla.state === 'done' ? '✓' : left}</span>
            </RingProgress>
          ) : (
            <span className="grid size-[44px] place-items-center rounded-full bg-[var(--color-surface-muted)]">
              <Clock3 className="size-4 text-[var(--color-ink-subtle)]" aria-hidden />
            </span>
          )}
          <div className="space-y-0.5 leading-tight">
            <InterestBadge level={interest.level} testId="work-interest-level" />
            {sla && (
              <p
                className={cn('t-caption font-semibold', sla.state === 'breached' && 'text-[var(--color-danger)]', sla.state === 'due_soon' && 'text-[var(--color-warning)]')}
                data-testid="work-sla-text"
                data-state={sla.state}
              >
                {sla.state === 'done'
                  ? t('work.sla.done')
                  : sla.state === 'breached'
                    ? t('work.sla.late', { count: left })
                    : t('work.sla.left', { count: left })}
                <span className="ms-1 font-normal text-[var(--color-ink-muted)]">· {t('work.sla.window', { count: sla.target_minutes })}</span>
              </p>
            )}
          </div>
        </div>

        <dl className="flex min-w-0 flex-1 flex-wrap gap-1" data-testid="work-facts">
          {facts.filter((fact) => fact.text).map(({ key, icon: Icon, label, text }) => (
            <div key={key} title={label} className="flex max-w-full items-center gap-1 rounded-lg bg-[var(--color-surface-muted)] px-2 py-1" data-testid={`work-fact-${key}`}>
              <Icon className="size-3.5 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
              <dt className="sr-only">{label}</dt>
              <dd className="truncate text-[0.875rem] font-semibold">{text}</dd>
            </div>
          ))}
        </dl>
      </div>

      {data.assignment && <AssignmentWhyLine why={data.assignment} />}
    </div>
  )
}

/** "Why you got it" — the rules that applied, then how the last one was chosen. One line of chips. */
function AssignmentWhyLine({ why }: { why: AssignmentWhy }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-wrap items-center gap-1 px-1" data-testid="work-assignment-why">
      <span className="t-caption">{t('work.assignment.title')}:</span>
      {why.rules.map((rule) => (
        <span key={rule} className="rounded-full bg-[var(--color-surface-muted)] px-2 py-0.5 text-[0.8125rem] font-medium">{t(`work.assignment.rule.${rule}`)}</span>
      ))}
      {why.strategy && (
        <span className="rounded-full bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] px-2 py-0.5 text-[0.8125rem] font-medium">{t(`work.assignment.strategy.${why.strategy}`)}</span>
      )}
      {why.availability && <span className="t-caption">· {t('work.assignment.away')}</span>}
    </div>
  )
}
