import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Check, Circle, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchSetupChecklist } from '@/features/onboarding'
import { can, useAuth } from '@/stores/auth'
import { Card } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * A short checklist, not a wizard. It disappears for good once everything is
 * done — a setup card that lingers on a finished workspace becomes furniture,
 * and the next thing it says goes unread.
 *
 * Every item is now answered by looking at the data. It used to read a map of
 * stored flags that an endpoint set on request, which meant the card could say
 * "team added" about a workspace with no sales reps in it — and, because
 * nothing ever called that endpoint, in practice it said the same thing
 * forever regardless of what anybody did.
 *
 * A checklist that can be satisfied by pressing Next is one that lies on the
 * day it matters.
 */
export function OnboardingChecklist() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const [dismissed, setDismissed] = useState(false)

  // Managers see it too: somebody being asked why leads are not getting
  // assigned should not need an admin to find out.
  const maySee = can.seeAllLeads(user)

  const { data } = useQuery({
    queryKey: ['setup-checklist'],
    queryFn: fetchSetupChecklist,
    enabled: maySee,
  })

  if (!maySee || dismissed || !data || data.complete) {
    return null
  }

  return (
    <Card className="mb-5 overflow-hidden" data-testid="onboarding-checklist">
      <div className="flex items-start justify-between gap-3 px-5 pt-4">
        <div>
          <h2 className="text-[0.9375rem] font-semibold">{t('onboarding.title')}</h2>
          <p className="mt-0.5 text-[0.8125rem] text-[var(--color-ink-muted)]">
            {t('onboarding.subtitle')} · {data.done}/{data.total}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setDismissed(true)}
          aria-label={t('onboarding.dismiss')}
          className="grid size-7 place-items-center rounded-[var(--radius-control)] text-[var(--color-ink-subtle)] hover:bg-[var(--color-surface-sunken)]"
        >
          <X className="size-4" aria-hidden />
        </button>
      </div>

      <div className="flex flex-wrap gap-2 px-5 pt-3 pb-4">
        {data.steps.map((step) => (
          <Link
            key={step.key}
            to={step.where}
            data-testid={`onboarding-step-${step.key}`}
            className={cn(
              'flex items-center gap-2 rounded-[var(--radius-control)] border px-3 py-2 text-[0.8125rem] transition-colors',
              step.done
                ? 'border-[color-mix(in_srgb,var(--color-status-won)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)] text-[var(--color-status-won)]'
                : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:border-[var(--color-border-strong)] hover:text-[var(--color-ink)]',
            )}
          >
            {step.done ? (
              <Check className="size-4 shrink-0" aria-hidden />
            ) : (
              <Circle className="size-4 shrink-0" aria-hidden />
            )}
            {t(`onboarding.step.${step.key}`)}
          </Link>
        ))}
      </div>
    </Card>
  )
}
