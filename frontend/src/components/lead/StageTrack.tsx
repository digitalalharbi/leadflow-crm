import { Check } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { LeadStatus } from '@/types/api'
import { cn } from '@/lib/cn'

/** Every stage a person can set by hand, in the order a lead passes through them. */
const TRACK: LeadStatus[] = ['new', 'contacted', 'replied', 'qualified', 'appointment', 'negotiation', 'won', 'lost']

/**
 * Where this lead is, as a path rather than a dropdown.
 *
 * Passed stages are filled and joined, the current one is ringed, what is still
 * ahead is hollow — so "how far along" is read from the shape before any label
 * is. Each stage is also the control that moves the lead there: one tap, no
 * menu. Won and Lost are the two ends, coloured as outcomes, because closing a
 * lead is a different act from advancing it.
 */
export function StageTrack({
  status,
  disabled,
  onPick,
}: {
  status: LeadStatus
  disabled?: boolean
  onPick: (status: LeadStatus) => void
}) {
  const { t } = useTranslation()
  const current = TRACK.indexOf(status)
  // Invalid / no-response are exits, not stages: nothing on the track is "passed".
  const reached = current === -1 ? -1 : status === 'lost' ? -1 : current

  return (
    <ol className="flex items-start" data-testid="stage-switcher">
      {TRACK.map((stage, index) => {
        const isCurrent = stage === status
        const passed = index < reached
        const outcome = stage === 'won' ? 'won' : stage === 'lost' ? 'lost' : null

        return (
          <li key={stage} className="relative flex min-w-0 flex-1 flex-col items-center">
            {index > 0 && (
              <span
                aria-hidden
                className={cn(
                  'absolute top-[0.9375rem] h-0.5 [inset-inline-end:50%] w-full',
                  index <= reached ? 'bg-[var(--brand)]' : 'bg-[var(--color-chrome-fill)]',
                )}
              />
            )}
            <button
              type="button"
              disabled={disabled}
              aria-current={isCurrent ? 'step' : undefined}
              onClick={() => onPick(stage)}
              className="group relative z-10 flex flex-col items-center gap-1 rounded-[var(--radius-control)] px-0.5 outline-offset-2"
            >
              <span
                aria-hidden
                className={cn(
                  'grid size-[1.875rem] place-items-center rounded-full border-2 text-[0.8125rem] font-semibold transition-colors',
                  isCurrent && outcome === 'won' && 'border-[var(--color-status-won)] bg-[var(--color-status-won)] text-white',
                  isCurrent && outcome === 'lost' && 'border-[var(--color-status-lost)] bg-[var(--color-status-lost)] text-white',
                  isCurrent && !outcome && 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-contrast)] shadow-[0_0_0_4px_var(--brand-soft)]',
                  !isCurrent && passed && 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand)]',
                  !isCurrent && !passed && 'border-[var(--color-chrome-fill)] bg-[var(--color-surface)] text-[var(--color-ink-subtle)] group-hover:border-[var(--brand)]',
                )}
              >
                {passed || (isCurrent && outcome === 'won') ? <Check className="size-3.5" aria-hidden /> : index + 1}
              </span>
              <span
                className={cn(
                  'max-w-full truncate text-[0.8125rem] leading-4',
                  isCurrent ? 'font-semibold text-[var(--color-ink)]' : 'text-[var(--color-ink-muted)]',
                )}
              >
                {t(`status.${stage}`)}
              </span>
            </button>
          </li>
        )
      })}
    </ol>
  )
}
