import { AlertTriangle, CheckCircle2, ShieldAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { Health, HealthCheck } from '@/features/hub'
import { cn } from '@/lib/cn'

const TONE: Record<Health, string> = {
  healthy: 'bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] text-[var(--color-success)]',
  warning: 'bg-[color-mix(in_srgb,var(--color-warning)_18%,transparent)] text-[var(--color-warning)]',
  critical: 'bg-[color-mix(in_srgb,var(--color-danger)_16%,transparent)] text-[var(--color-danger)]',
}

const ICON = { healthy: CheckCircle2, warning: AlertTriangle, critical: ShieldAlert }

/** One word for the whole link — healthy, warning, critical — with the icon beside it so colour is never the only signal. */
export function HealthBadge({ status, testId }: { status: Health; testId?: string }) {
  const { t } = useTranslation()
  const Icon = ICON[status]

  return (
    <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', TONE[status])} data-testid={testId} data-status={status}>
      <Icon className="size-3.5" aria-hidden />{t(`hub.health.status.${status}`)}
    </span>
  )
}

/** Check by check: what was looked at, and what is wrong with it in words a marketer can act on. */
export function HealthChecks({ checks }: { checks: HealthCheck[] }) {
  const { t } = useTranslation()

  return (
    <ul className="space-y-1.5" data-testid="health-checks">
      {checks.map((c) => {
        const status: Health = c.state === 'ok' ? 'healthy' : c.state
        const Icon = ICON[status]

        return (
          <li key={c.key} className="flex items-start gap-2 text-[0.9375rem]" data-testid={`health-check-${c.key}`} data-state={c.state}>
            <Icon className={cn('mt-0.5 size-4 shrink-0', TONE[status].split(' ').pop())} aria-hidden />
            <span>
              <span className="font-semibold">{t(`hub.health.check.${c.key}`)}</span>
              <span className="block text-[0.875rem] text-[var(--color-ink-muted)]">{t(`hub.health.${c.state === 'ok' ? 'ok' : 'why'}.${c.key}`, { ...flat(c), defaultValue: '' })}</span>
            </span>
          </li>
        )
      })}
    </ul>
  )
}

/** The numbers and lists a check carries, as values the sentence can use. */
function flat(c: HealthCheck): Record<string, string | number> {
  return Object.fromEntries(Object.entries(c).map(([k, v]) => [k, Array.isArray(v) ? v.join(', ') : typeof v === 'number' || typeof v === 'string' ? v : String(v ?? '')]))
}
