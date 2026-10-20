import { Bot, MessageCircle, Star, User, UserCog } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import type { HandoffBrief } from '@/features/leads'
import { formatMoneyCompact, formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

/**
 * Handed to you: who they are, why now, what is known, what is missing, what
 * is holding them back, what was last said, and what to do — on one card, in
 * chips and rings, so nothing has to be reconstructed before the first call.
 */
export function HandoffBriefCard({ brief, compact = false, onAct }: { brief: HandoffBrief; compact?: boolean; onAct?: (action: string) => void }) {
  const { t, i18n } = useTranslation()
  const { summary, qualification } = brief
  const percent = qualification.total > 0 ? Math.round((qualification.done / qualification.total) * 100) : 0

  const facts = [
    summary.project, summary.city, summary.campaign,
    summary.budget?.max ? formatMoneyCompact(summary.budget.max, 'SAR', i18n.language) : null,
    summary.timeline ? t(`conversation.timeline.${summary.timeline}`, { defaultValue: summary.timeline }) : null,
    summary.property ? t(`conversation.service.${summary.property}`, { defaultValue: summary.property }) : null,
  ].filter(Boolean) as string[]

  return (
    <Card data-testid="handoff-brief" className="border border-[color-mix(in_srgb,var(--brand)_35%,transparent)]">
      <CardBody className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="flex items-center gap-2 text-[1.0625rem] font-extrabold">
            <UserCog className="size-5 text-[var(--brand)]" aria-hidden />
            {t('handoff.title')}
          </h3>
          <div className="flex items-center gap-2">
            <span data-testid="handoff-reason" className="rounded-full bg-[var(--brand-soft)] px-2.5 py-0.5 text-[0.9375rem] font-bold text-[var(--brand)]">{brief.reason.words}</span>
            {brief.since && <span className="text-[0.875rem] text-[var(--color-ink-subtle)]">{formatRelative(brief.since, i18n.language)}</span>}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <RingProgress value={summary.score} size={56} thickness={6} tone={summary.score >= 70 ? 'good' : summary.score >= 40 ? 'warn' : 'neutral'} label={t('handoff.score')}>
            <span className="text-[1rem] font-bold tabular-nums">{summary.score}</span>
          </RingProgress>
          <div className="text-center" data-testid="handoff-qualification">
            <RingProgress value={percent} size={56} thickness={6} tone={percent >= 100 ? 'good' : 'brand'} label={t('handoff.qualification')}>
              <span className="text-[0.9375rem] font-bold tabular-nums" dir="ltr">{qualification.done}/{qualification.total}</span>
            </RingProgress>
          </div>
          {summary.priority && <Star className="size-5 text-[var(--color-warning)]" aria-label={t('temperature.priority')} />}
          <ul className="flex min-w-0 flex-1 flex-wrap gap-1.5" data-testid="handoff-facts">
            {facts.map((fact) => (
              <li key={fact} className="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-1 text-[0.9375rem] font-semibold">{fact}</li>
            ))}
          </ul>
        </div>

        {(brief.objections.length > 0 || qualification.missing.length > 0 || brief.why_now.said) && (
          <div className="space-y-1.5">
            {brief.why_now.said && <p className="text-[1rem] font-semibold" data-testid="handoff-said">{brief.why_now.said}</p>}
            {brief.objections.length > 0 && (
              <ul className="flex flex-wrap gap-1.5" data-testid="handoff-objections">
                {brief.objections.map((objection) => (
                  <li key={objection} className="rounded-full bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] px-2.5 py-1 text-[0.9375rem] font-semibold text-[var(--color-danger)]">
                    {t(`conversation.objection.${objection}`)}
                  </li>
                ))}
              </ul>
            )}
            {qualification.missing.length > 0 && (
              <ul className="flex flex-wrap gap-1.5" data-testid="handoff-missing">
                {qualification.missing.map((question) => (
                  <li key={question} className="rounded-full border border-dashed border-[var(--color-border-strong)] px-2.5 py-1 text-[0.9375rem] text-[var(--color-ink-muted)]">{question}</li>
                ))}
              </ul>
            )}
          </div>
        )}

        {!compact && brief.last_conversation.length > 0 && (
          <ol className="space-y-1.5" data-testid="handoff-conversation">
            {brief.last_conversation.slice(-3).map((line, index) => {
              const Icon = line.who === 'customer' ? User : line.who === 'rep' ? MessageCircle : Bot

              return (
                <li key={index} data-who={line.who} className={cn('flex items-start gap-2 rounded-xl px-3 py-2 text-[0.9375rem]', line.who === 'customer' ? 'bg-[var(--brand-soft)]' : 'bg-[var(--color-surface-muted)]')}>
                  <Icon className="mt-0.5 size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                  <span className="min-w-0 flex-1 break-words">{line.text}</span>
                </li>
              )
            })}
          </ol>
        )}

        {brief.next_action && (
          <button
            type="button"
            data-testid="handoff-next"
            disabled={!onAct}
            onClick={() => onAct?.(brief.next_action!.action)}
            title={brief.next_action.reason}
            className="pressable inline-flex items-center gap-2 rounded-full bg-[var(--brand)] px-4 py-1.5 text-[1rem] font-bold text-[var(--brand-contrast)] disabled:cursor-default"
          >
            {t(`work.action.${brief.next_action.action}`, { defaultValue: brief.next_action.action })}
          </button>
        )}
      </CardBody>
    </Card>
  )
}
