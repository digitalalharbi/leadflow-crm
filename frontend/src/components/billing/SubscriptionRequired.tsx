import { useTranslation } from 'react-i18next'
import { CreditCard, LogOut } from 'lucide-react'
import { Button, Card, CardBody } from '@/components/ui'
import { formatDate } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import type { Subscription } from '@/types/api'

/**
 * The whole workspace, when its subscription is closed: suspended, cancelled, or a trial or paid period that lapsed.
 * Nothing is deleted — this screen says so, says why, and says who reopens it. The server refuses the data either way
 * (402); this is only the explanation in place of a page of errors.
 */
export function SubscriptionRequired({ subscription }: { subscription: Subscription }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const logout = useAuth((s) => s.logout)
  const state = subscription.state
  const isAdmin = user?.role === 'admin'

  return (
    <div className="grid min-h-[60dvh] place-items-center p-4" data-testid="subscription-required" data-state={state}>
      <Card className="w-full max-w-lg">
        <CardBody className="space-y-4 text-center">
          <div className="mx-auto grid size-12 place-items-center rounded-full bg-[var(--color-surface-sunken)] text-[var(--color-ink-subtle)]">
            <CreditCard className="size-6" aria-hidden />
          </div>
          <h1 className="text-[1.25rem] font-semibold">{t(`billing.closed.${state}.title`, { defaultValue: t('billing.closed.expired.title') })}</h1>
          <p className="text-[0.9375rem] leading-relaxed text-[var(--color-ink-muted)]">
            {t(`billing.closed.${state}.body`, { defaultValue: t('billing.closed.expired.body') })}
          </p>
          {subscription.plan && (
            <p className="text-[0.875rem] text-[var(--color-ink-muted)]">
              {t('billing.planLine', { plan: subscription.plan.name })}
              {state === 'trial_expired' && subscription.trial_ends_at && ` · ${t('billing.endedOn', { date: formatDate(subscription.trial_ends_at, i18n.language) })}`}
            </p>
          )}
          <p className="rounded-[var(--radius-md)] bg-[var(--color-surface-sunken)] p-3 text-[0.9375rem]">{t('billing.dataKept')}</p>
          <p className="text-[0.9375rem]">{isAdmin ? t('billing.adminNext') : t('billing.memberNext')}</p>
          <div className="flex justify-center">
            <Button variant="secondary" onClick={() => void logout()} data-testid="subscription-logout">
              <LogOut className="size-4" aria-hidden />
              {t('auth.signOut')}
            </Button>
          </div>
        </CardBody>
      </Card>
    </div>
  )
}
