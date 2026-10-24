import { useTranslation } from 'react-i18next'
import { Lock } from 'lucide-react'
import { EmptyState } from '@/components/ui'
import { entitled, useAuth } from '@/stores/auth'
import type { Feature } from '@/types/api'

/**
 * A screen whose feature the plan does not include, reached by its address: not a page of 403s, a sentence saying what
 * it is and that the plan decides it. The navigation already leaves it out; the server refuses its data either way.
 */
export function RequireFeature({ any, children }: { any: Feature[]; children: React.ReactNode }) {
  const subscription = useAuth((s) => s.subscription)
  const { t } = useTranslation()

  if (any.some((feature) => entitled(subscription, feature))) return <>{children}</>

  return (
    <div data-testid="feature-locked" data-feature={any.join(',')}>
      <EmptyState
        icon={<Lock className="size-6" aria-hidden />}
        title={t('billing.locked.title', { feature: t(`billing.features.${any[0]}`) })}
        body={t('billing.locked.body')}
      />
    </div>
  )
}
