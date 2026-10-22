import { useState } from 'react'
import { Bot, ChevronDown, Clock, MessageCircle, Star, User, UserCog } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { InterestBadge } from '@/components/visual/InterestBadge'
import type { HandoffBrief } from '@/features/leads'
import { formatMoneyCompact, formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

const SLA_TONE: Record<string, string> = { ok: 'var(--color-ink-muted)', due_soon: 'var(--color-warning)', breached: 'var(--color-danger)', done: 'var(--color-success)' }

/**
 * Handed to you: the answer to "why is this mine, and what do I do first?" in two lines, and the
 * rest one press away.
 *
 * Collapsed it is a single compact band — why now, how far qualified, how interested and how long
 * they may wait, who spoke last and when, what is holding them back — and the one next step. Opened
 * it adds what is known, what is still missing, and the last words of the conversation. It never
 * takes more of a laptop screen than it has to: the thing a rep needs below it is the thread.
 */
export function HandoffBriefCard({ brief, compact = false, onAct }: { brief: HandoffBrief; compact?: boolean; onAct?: (action: string) => void }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(false)
  const { summary, qualification } = brief
  const percent = qualification.total > 0 ? Math.round((qualification.done / qualification.total) * 100) : 0

  const facts = [
    summary.project, summary.city, summary.campaign,
    summary.budget?.max ? formatMoneyCompact(summary.budget.max, 'SAR', i18n.language) : null,
    summary.timeline ? t(`conversation.timeline.${summary.timeline}`, { defaultValue: summary.timeline }) : null,
    summary.property ? t(`conversation.service.${summary.property}`, { defaultValue: summary.property }) : null,
  ].filter(Boolean) as string[]

  const whyNow = brief.why_now.said ?? (brief.why_now.signals[0] ? t(`work.signal.${brief.why_now.signals[0]}`, { defaultValue: brief.why_now.signals[0] }) : null)
  const last = brief.last_activity
  const sla = brief.interest?.sla
  const hasDetails = facts.length > 0 || qualification.missing.length > 0 || (!compact && brief.last_conversation.length > 0)

  return (
    <Card data-testid="handoff-brief" className="border border-[color-mix(in_srgb,var(--brand)_35%,transparent)]">
      <CardBody className="space-y-2 !py-3">
        {/* Who handed it, why, and when — with the toggle that opens the rest. */}
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="flex items-center gap-1.5 text-[1rem] font-extrabold">
            <UserCog className="size-4.5 text-[var(--brand)]" aria-hidden />
            {t('handoff.title')}
          </h3>
          <div className="flex items-center gap-2">
            <span data-testid="handoff-reason" className="rounded-full bg-[var(--brand-soft)] px-2.5 py-0.5 text-[0.875rem] font-bold text-[var(--brand)]">{brief.reason.words}</span>
            {brief.since && <span className="text-[0.8125rem] text-[var(--color-ink-subtle)]">{formatRelative(brief.since, i18n.language)}</span>}
            {hasDetails && (
              <button
                type="button"
                aria-expanded={open}
                data-testid="handoff-toggle"
                onClick={() => setOpen((value) => !value)}
                className="pressable inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)]"
              >
                {open ? t('handoff.hideDetails') : t('handoff.details')}
                <ChevronDown className={cn('size-3.5 transition-transform', open && 'rotate-180')} aria-hidden />
              </button>
            )}
          </div>
        </div>

        {/* The band: why now · qualification · interest · last activity, on one row where there is room. */}
        <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-4" data-testid="handoff-band">
          <div className="min-w-0" data-testid="handoff-why">
            <p className="t-caption">{t('handoff.whyNow')}</p>
            <p className="line-clamp-2 text-[0.9375rem] font-semibold" data-testid="handoff-said">{whyNow ?? '—'}</p>
          </div>

          <div className="flex items-center gap-2" data-testid="handoff-qualification">
            <RingProgress value={percent} size={40} thickness={5} tone={percent >= 100 ? 'good' : 'brand'} label={t('handoff.qualification')}>
              <span className="text-[0.75rem] font-bold tabular-nums" dir="ltr">{qualification.done}/{qualification.total}</span>
            </RingProgress>
            <div className="min-w-0">
              <p className="t-caption">{t('handoff.qualification')}</p>
              <p className="flex items-center gap-1 text-[0.9375rem] font-semibold">
                <span className="tabular-nums">{summary.score}</span>
                <span className="t-caption font-normal">{t('handoff.score')}</span>
                {summary.priority && <Star className="size-4 text-[var(--color-warning)]" aria-label={t('temperature.priority')} />}
              </p>
            </div>
          </div>

          <div className="min-w-0" data-testid="handoff-interest">
            <p className="t-caption">{t('handoff.interest')}</p>
            <p className="flex flex-wrap items-center gap-1.5">
              <InterestBadge level={brief.interest?.level ?? 'cold'} testId="handoff-interest-badge" />
              {sla && (
                <span className="inline-flex items-center gap-1 text-[0.8125rem] font-semibold" style={{ color: SLA_TONE[sla.state] }} data-testid="handoff-sla" data-state={sla.state}>
                  <Clock className="size-3.5" aria-hidden />
                  {t(`handoff.sla.${sla.state}`)}
                </span>
              )}
            </p>
          </div>

          <div className="min-w-0" data-testid="handoff-last">
            <p className="t-caption">{t('handoff.lastActivity')}</p>
            <p className="truncate text-[0.9375rem] font-semibold">
              {last?.at ? `${t(`handoff.who.${last.who}`)} · ${formatRelative(last.at, i18n.language)}` : t('handoff.nothingYet')}
            </p>
          </div>
        </div>

        {/* What is holding them back, and the one thing to do first. */}
        {(brief.objections.length > 0 || brief.next_action) && (
          <div className="flex flex-wrap items-center justify-between gap-2">
            {brief.objections.length > 0 ? (
              <ul className="flex flex-wrap gap-1.5" data-testid="handoff-objections">
                {brief.objections.map((objection) => (
                  <li key={objection} className="rounded-full bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] px-2.5 py-0.5 text-[0.875rem] font-semibold text-[var(--color-danger)]">
                    {t(`conversation.objection.${objection}`)}
                  </li>
                ))}
              </ul>
            ) : <span />}

            {brief.next_action && (
              <button
                type="button"
                data-testid="handoff-next"
                disabled={!onAct}
                onClick={() => onAct?.(brief.next_action!.action)}
                title={brief.next_action.reason}
                className="pressable inline-flex items-center gap-2 rounded-full bg-[var(--brand)] px-4 py-1 text-[0.9375rem] font-bold text-[var(--brand-contrast)] disabled:cursor-default"
              >
                {t(`work.action.${brief.next_action.action}`, { defaultValue: brief.next_action.action })}
              </button>
            )}
          </div>
        )}

        {open && (
          <div className="space-y-2 border-t border-[var(--color-line)] pt-2" data-testid="handoff-details">
            {facts.length > 0 && (
              <ul className="flex flex-wrap gap-1.5" data-testid="handoff-facts">
                {facts.map((fact) => (
                  <li key={fact} className="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-[0.875rem] font-semibold">{fact}</li>
                ))}
              </ul>
            )}

            {qualification.missing.length > 0 && (
              <ul className="flex flex-wrap gap-1.5" data-testid="handoff-missing">
                {qualification.missing.map((question) => (
                  <li key={question} className="rounded-full border border-dashed border-[var(--color-border-strong)] px-2.5 py-0.5 text-[0.875rem] text-[var(--color-ink-muted)]">{question}</li>
                ))}
              </ul>
            )}

            {!compact && brief.last_conversation.length > 0 && (
              <ol className="space-y-1" data-testid="handoff-conversation">
                {brief.last_conversation.slice(-3).map((line, index) => {
                  const Icon = line.who === 'customer' ? User : line.who === 'rep' ? MessageCircle : Bot

                  return (
                    <li key={index} data-who={line.who} className={cn('flex items-start gap-2 rounded-xl px-3 py-1.5 text-[0.875rem]', line.who === 'customer' ? 'bg-[var(--brand-soft)]' : 'bg-[var(--color-surface-muted)]')}>
                      <Icon className="mt-0.5 size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                      <span className="min-w-0 flex-1 break-words">{line.text}</span>
                    </li>
                  )
                })}
              </ol>
            )}
          </div>
        )}
      </CardBody>
    </Card>
  )
}
