import { useMutation, useQueryClient } from '@tanstack/react-query'
import { BedDouble, CalendarCheck, CreditCard, Frown, Home, KeyRound, Landmark, MapPin, Meh, MessageSquareQuote, RefreshCw, ShieldAlert, Smile, Sparkles, Target, Wallet, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { analyzeConversation, type ConversationFacts } from '@/features/leads'
import { formatMoneyCompact } from '@/lib/format'
import { cn } from '@/lib/cn'

const SENTIMENT: Record<'positive' | 'neutral' | 'negative', { icon: LucideIcon; color: string }> = {
  positive: { icon: Smile, color: 'var(--color-success)' },
  neutral: { icon: Meh, color: 'var(--color-ink-muted)' },
  negative: { icon: Frown, color: 'var(--color-danger)' },
}

const URGENCY_COLOR = { high: 'var(--color-danger)', medium: 'var(--color-warning)', low: 'var(--color-ink-muted)' } as const

/**
 * What the customer has said, as facts and not as prose: how ready they are,
 * how soon, what they want, what they can spend, whether they asked for a
 * visit, how they feel, and what is holding them back. Each is one chip.
 *
 * The badge says who read it — Claude, or plainly the rules, with why — so a
 * keyword match is never taken for a model's judgement.
 */
export function ConversationCard({ leadId, conversation }: { leadId: string; conversation: ConversationFacts | undefined }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()

  const analyse = useMutation({
    mutationFn: () => analyzeConversation(leadId),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['lead', leadId] }),
  })

  if (!conversation) return null

  const { facts, engine, note } = conversation
  const empty = engine === null
  const sentiment = facts.sentiment ? SENTIMENT[facts.sentiment] : null

  const chips: Array<{ key: string; Icon: LucideIcon; label: string; color?: string; testId: string }> = []

  if (facts.intent) chips.push({ key: 'intent', Icon: Sparkles, label: t(`conversation.intent.${facts.intent.value}`), color: facts.intent.value === 'not_interested' || facts.intent.value === 'wrong_contact' ? 'var(--color-danger)' : 'var(--brand)', testId: 'fact-intent' })
  if (facts.urgency) chips.push({ key: 'urgency', Icon: Zap, label: t(`conversation.urgency.${facts.urgency.value}`) + (facts.urgency.timeline ? ` · ${t(`conversation.timeline.${facts.urgency.timeline}`)}` : ''), color: URGENCY_COLOR[facts.urgency.value], testId: 'fact-urgency' })
  if (facts.service_interest) chips.push({ key: 'service', Icon: Home, label: t(`conversation.service.${facts.service_interest}`), testId: 'fact-service' })
  if (facts.budget) {
    chips.push({
      key: 'budget', Icon: Wallet, testId: 'fact-budget',
      label: facts.budget.signal === 'stated' && facts.budget.max ? formatMoneyCompact(facts.budget.max, facts.budget.currency ?? 'SAR', i18n.language) : t(`conversation.budget.${facts.budget.signal}`),
    })
  }
  if (facts.appointment_intent) chips.push({ key: 'appointment', Icon: CalendarCheck, label: t(`conversation.appointment.${facts.appointment_intent}`), color: facts.appointment_intent === 'requested' ? 'var(--color-status-appointment)' : undefined, testId: 'fact-appointment' })
  // What they want in real estate terms.
  if (facts.transaction) chips.push({ key: 'transaction', Icon: KeyRound, label: t(`conversation.transaction.${facts.transaction}`), testId: 'fact-transaction' })
  if (facts.district) chips.push({ key: 'district', Icon: MapPin, label: facts.district, testId: 'fact-district' })
  if (facts.bedrooms) chips.push({ key: 'bedrooms', Icon: BedDouble, label: t('conversation.bedrooms', { count: facts.bedrooms }), testId: 'fact-bedrooms' })
  if (facts.payment) chips.push({ key: 'payment', Icon: CreditCard, label: t(`conversation.payment.${facts.payment}`), testId: 'fact-payment' })
  if (facts.purpose) chips.push({ key: 'purpose', Icon: facts.purpose === 'investment' ? Landmark : Target, label: t(`conversation.purpose.${facts.purpose}`), testId: 'fact-purpose' })
  if (facts.reservation_intent) chips.push({ key: 'reservation', Icon: KeyRound, label: t(`conversation.reservation.${facts.reservation_intent}`), color: facts.reservation_intent === 'ready' ? 'var(--color-success)' : undefined, testId: 'fact-reservation' })
  if (sentiment && facts.sentiment) chips.push({ key: 'sentiment', Icon: sentiment.icon, label: t(`conversation.sentiment.${facts.sentiment}`), color: sentiment.color, testId: 'fact-sentiment' })

  return (
    <Card data-testid="conversation-card">
      <CardBody className="space-y-3">
        <div className="flex items-center justify-between gap-2">
          <h3 className="t-card-title flex items-center gap-2"><MessageSquareQuote className="size-4 text-[var(--brand)]" aria-hidden />{t('conversation.title')}</h3>
          <div className="flex items-center gap-1.5">
            {engine && (
              <span
                data-testid="conversation-engine"
                data-engine={engine}
                title={note ? t(`conversation.note.${note}`) : undefined}
                className={cn('rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', engine === 'claude' ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}
              >
                {t(`conversation.engine.${engine}`)}
              </span>
            )}
            <button
              type="button"
              aria-label={t('conversation.analyze')}
              title={t('conversation.analyze')}
              data-testid="conversation-analyze"
              disabled={analyse.isPending}
              onClick={() => analyse.mutate()}
              className="pressable grid size-7 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)] disabled:opacity-50"
            >
              <RefreshCw className={cn('size-3.5', analyse.isPending && 'animate-spin')} aria-hidden />
            </button>
          </div>
        </div>

        {engine === 'rules' && note && (
          <p className="t-caption" data-testid="conversation-note">{t(`conversation.note.${note}`)}</p>
        )}

        {empty ? (
          <p className="t-caption">{t('conversation.empty')}</p>
        ) : (
          <>
            <ul className="flex flex-wrap gap-1.5" data-testid="conversation-facts">
              {chips.map(({ key, Icon, label, color, testId }) => (
                <li key={key} data-testid={testId} className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-surface-muted)] px-2.5 py-1 text-[0.9375rem] font-semibold">
                  <Icon className="size-4" style={color ? { color } : undefined} aria-hidden />
                  {label}
                </li>
              ))}
            </ul>

            {facts.objections.length > 0 && (
              <ul className="flex flex-wrap gap-1.5" data-testid="conversation-objections">
                {facts.objections.map((objection) => (
                  <li key={objection} className="inline-flex items-center gap-1.5 rounded-full bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] px-2.5 py-1 text-[0.9375rem] font-semibold text-[var(--color-danger)]">
                    <ShieldAlert className="size-4" aria-hidden />
                    {t(`conversation.objection.${objection}`)}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </CardBody>
    </Card>
  )
}
