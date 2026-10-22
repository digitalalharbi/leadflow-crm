import { useMutation, useQueryClient } from '@tanstack/react-query'
import { HeartHandshake, HeartOff, Undo2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody } from '@/components/ui'
import { INTEREST, INTEREST_LEVELS, InterestBadge, RingProgress, type InterestLevel } from '@/components/visual'
import { setInterest } from '@/features/leads'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import type { Lead } from '@/types/api'
import { cn } from '@/lib/cn'

/**
 * How interested they are — from what they do, not how well they fit.
 *
 * A ring for the number, a ladder of six levels with theirs lit, and the reasons in the order they
 * weigh: what pushed it up, what pulled it down. A person may overrule the arithmetic in one press
 * ("interested" is a floor, "not interested" forces cold) and take it back just as fast.
 */
export function InterestCard({ lead }: { lead: Lead }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const level = (lead.interest_level ?? 'cold') as InterestLevel
  const score = lead.interest_score ?? 0
  const { color } = INTEREST[level]
  const signals = (lead.interest_signals ?? []).slice(0, 6)

  const set = useMutation({
    mutationFn: (override: 'interested' | 'not_interested' | null) => setInterest(lead.id, override),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead-segments'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Card data-testid="interest-card" data-level={level}>
      <CardBody className="space-y-3">
        <div className="flex items-center justify-between gap-2">
          <h3 className="t-card-title">{t('interest.title')}</h3>
          <InterestBadge level={level} testId="interest-level" />
        </div>

        <div className="flex items-center gap-3">
          <RingProgress value={score} size={64} thickness={8} label={t('interest.title')} testId="interest-ring" tone={level === 'cold' ? 'neutral' : level === 'warm' ? 'warn' : 'good'}>
            <span className="text-[1.0625rem] font-extrabold tabular-nums" data-testid="interest-score" style={{ color }}>{score}</span>
          </RingProgress>
          <ol className="flex min-w-0 flex-1 items-end gap-1" aria-label={t('interest.ladder')} data-testid="interest-ladder">
            {INTEREST_LEVELS.map((step, i) => {
              const reached = INTEREST_LEVELS.indexOf(level) >= i

              return (
                <li key={step} className="flex flex-1 flex-col items-center gap-1" data-step={step} data-reached={reached} title={t(`interest.level.${step}`)}>
                  <span className="w-full rounded-full" style={{ height: 6 + i * 3, background: reached ? INTEREST[step].color : 'var(--color-surface-sunken)' }} />
                </li>
              )
            })}
          </ol>
        </div>

        {signals.length > 0 && (
          <ul className="space-y-1" data-testid="interest-signals">
            {signals.map((signal) => (
              <li key={signal.key} className="flex items-center justify-between gap-2 text-[0.9375rem]" data-signal={signal.key}>
                <span className="text-[var(--color-ink-muted)]">{t(`interest.signal.${signal.key}`, { defaultValue: signal.key })}{signal.count && signal.count > 1 ? ` ×${signal.count}` : ''}</span>
                <span className={cn('font-bold tabular-nums', signal.points > 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]')} dir="ltr">{signal.points > 0 ? `+${signal.points}` : signal.points}</span>
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap gap-2">
          {lead.interest_override ? (
            <Button size="sm" variant="outline" icon={<Undo2 className="size-4" />} loading={set.isPending} onClick={() => set.mutate(null)} data-testid="interest-clear">
              {t('interest.clear', { word: t(`interest.override.${lead.interest_override}`) })}
            </Button>
          ) : (
            <>
              <Button size="sm" variant="outline" icon={<HeartHandshake className="size-4" />} loading={set.isPending} onClick={() => set.mutate('interested')} data-testid="interest-yes">{t('interest.override.interested')}</Button>
              <Button size="sm" variant="outline" icon={<HeartOff className="size-4" />} loading={set.isPending} onClick={() => set.mutate('not_interested')} data-testid="interest-no">{t('interest.override.not_interested')}</Button>
            </>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
