import { useQuery } from '@tanstack/react-query'
import { ArrowRightLeft, BellRing, Mail, MessageCircle, FileText, UserCog, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui'
import { simulateRule } from '@/features/automation'
import { cn } from '@/lib/cn'

/**
 * "If this rule had run in the last 7 days" — as numbers, not paragraphs:
 * who it would have touched, what it would have sent, what it would have
 * moved, who it would have handed to a person. Nothing is carried out, and the
 * messages the guardrails would have held are shown apart.
 */
export function SimulationPanel({ ruleId }: { ruleId: string }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['automation-simulation', ruleId], queryFn: () => simulateRule(ruleId) })

  if (isLoading || !data) return <Skeleton className="h-40" />

  const sim = data.simulation
  const held = Object.entries(sim.messages.held)

  const tiles: Array<{ key: string; Icon: LucideIcon; value: number; strong?: boolean }> = [
    { key: 'leads', Icon: Users, value: sim.leads_affected, strong: true },
    { key: 'whatsapp', Icon: MessageCircle, value: sim.messages.whatsapp },
    { key: 'email', Icon: Mail, value: sim.messages.email },
    { key: 'files', Icon: FileText, value: sim.messages.files },
    { key: 'stages', Icon: ArrowRightLeft, value: sim.stage_changes.total },
    { key: 'escalations', Icon: UserCog, value: sim.escalations },
    { key: 'notifications', Icon: BellRing, value: sim.notifications },
  ]

  return (
    <section className="space-y-3" data-testid="simulation">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-[1.0625rem] font-bold">{t('simulation.title', { days: sim.days })}</h3>
        <span className="rounded-full bg-[var(--color-surface-sunken)] px-2.5 py-0.5 text-[0.8125rem] font-semibold">{t('simulation.noExecution')}</span>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {tiles.map(({ key, Icon, value, strong }) => (
          <div
            key={key}
            data-testid={`sim-${key}`}
            className={cn('flex items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2.5', strong && 'ring-1 ring-[var(--brand)]/40')}
          >
            <Icon className="size-4 shrink-0 text-[var(--brand)]" aria-hidden />
            <span className="text-[1.375rem] font-extrabold tabular-nums leading-none">{value}</span>
            <span className="min-w-0 truncate text-[0.875rem] font-medium text-[var(--color-ink-muted)]">{t(`simulation.${key}`)}</span>
          </div>
        ))}
      </div>

      {held.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" data-testid="sim-held">
          {held.map(([code, count]) => (
            <li key={code} className="rounded-full bg-[color-mix(in_srgb,var(--color-warning)_16%,transparent)] px-2.5 py-0.5 text-[0.875rem] font-semibold text-[var(--color-warning)]">
              {t(`simulation.held.${code}`, { defaultValue: code })} · {count}
            </li>
          ))}
        </ul>
      )}

      <p className="t-caption">
        {t('simulation.basis')}
        {sim.capped && ` ${t('simulation.capped')}`}
      </p>
    </section>
  )
}
