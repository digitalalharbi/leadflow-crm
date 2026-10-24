import { useTranslation } from 'react-i18next'
import { AlertTriangle, Clock } from 'lucide-react'
import { useAuth } from '@/stores/auth'

const DAY = 86_400_000

/**
 * One line above the page while the subscription needs attention: a trial in its last week, or a payment that is late
 * and in its grace period. Silent the rest of the time.
 */
export function LifecycleBanner() {
  const { t } = useTranslation()
  const subscription = useAuth((s) => s.subscription)

  if (!subscription || !subscription.usable) return null

  if (subscription.state === 'past_due') {
    return (
      <div role="status" data-testid="lifecycle-banner" data-state="past_due" className="flex items-center gap-2 rounded-[var(--radius-md)] border border-[color-mix(in_srgb,var(--color-warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--color-warning)_12%,transparent)] px-4 py-2.5 text-[0.9375rem]">
        <AlertTriangle className="size-4 shrink-0" aria-hidden />
        {t('billing.banner.pastDue')}
      </div>
    )
  }

  if (subscription.state === 'trialing' && subscription.trial_ends_at) {
    const days = Math.max(0, Math.ceil((new Date(subscription.trial_ends_at).getTime() - Date.now()) / DAY))

    if (days > 7) return null

    return (
      <div role="status" data-testid="lifecycle-banner" data-state="trialing" className="flex items-center gap-2 rounded-[var(--radius-md)] border border-[var(--color-line)] bg-[var(--color-surface-sunken)] px-4 py-2.5 text-[0.9375rem]">
        <Clock className="size-4 shrink-0" aria-hidden />
        {t('billing.banner.trialEnds', { count: days })}
      </div>
    )
  }

  return null
}
