import { Link } from 'react-router-dom'
import { Check, Circle, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { Card } from '@/components/ui'
import { cn } from '@/lib/cn'
import { useState } from 'react'

const STEPS = [
  { key: 'branding', labelKey: 'onboarding.branding', to: '/settings' },
  { key: 'team', labelKey: 'onboarding.team', to: '/users' },
  { key: 'project', labelKey: 'onboarding.project', to: '/projects' },
  { key: 'lead_source', labelKey: 'onboarding.leadSource', to: '/integrations' },
  { key: 'first_lead', labelKey: 'onboarding.firstLead', to: '/leads' },
] as const

/** A short checklist, not a wizard. It disappears for good once everything is done. */
export function OnboardingChecklist() {
  const { t } = useTranslation()
  const onboarding = useAuth((s) => s.onboarding)
  const user = useAuth((s) => s.user)
  const [dismissed, setDismissed] = useState(false)

  if (!onboarding || dismissed || user?.role !== 'admin') return null

  const done = STEPS.filter((step) => onboarding[step.key]).length

  if (done === STEPS.length) return null

  return (
    <Card className="mb-5 overflow-hidden">
      <div className="flex items-start justify-between gap-3 px-5 pt-4">
        <div>
          <h2 className="text-[0.9375rem] font-semibold">{t('onboarding.title')}</h2>
          <p className="mt-0.5 text-[0.8125rem] text-[var(--color-ink-muted)]">
            {t('onboarding.subtitle')} · {done}/{STEPS.length}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setDismissed(true)}
          aria-label={t('onboarding.dismiss')}
          className="grid size-7 place-items-center rounded-lg text-[var(--color-ink-subtle)] hover:bg-[var(--color-surface-sunken)]"
        >
          <X className="size-4" aria-hidden />
        </button>
      </div>

      <div className="flex flex-wrap gap-2 px-5 pb-4 pt-3">
        {STEPS.map((step) => {
          const complete = onboarding[step.key]

          return (
            <Link
              key={step.key}
              to={step.to}
              className={cn(
                'flex items-center gap-2 rounded-lg border px-3 py-2 text-[0.8125rem] transition-colors',
                complete
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                  : 'border-[var(--color-border)] hover:border-[var(--color-border-strong)] hover:bg-[var(--color-surface-muted)]',
              )}
            >
              {complete ? (
                <Check className="size-4 shrink-0" aria-hidden />
              ) : (
                <Circle className="size-4 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />
              )}
              {t(step.labelKey)}
            </Link>
          )
        })}
      </div>
    </Card>
  )
}
