import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CircleSlash, PlayCircle, TriangleAlert } from 'lucide-react'
import { fetchRunsForLead, type AutomationRun } from '@/features/automation'
import { formatDateTime } from '@/lib/format'
import { Badge, CardBody, Skeleton } from '@/components/ui'

/**
 * Why the system did that.
 *
 * Automation earns trust by being explainable and loses it the first time
 * something happens that nobody can account for. "Why was this lead assigned to
 * me?", "why did it message the customer?", "why did it move to qualified?" —
 * a manager asking any of those and getting no answer stops trusting the whole
 * feature, including the parts working correctly.
 *
 * So the skipped runs are shown too, not only the ones that acted. "This rule
 * was considered and did not apply, because the city was جدة and the rule wants
 * الرياض" is usually the answer somebody actually needs: the common complaint
 * is not that a rule did something, it is that a rule did nothing.
 */
export function AutomationTrace({ leadId }: { leadId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['automation-runs', leadId],
    queryFn: () => fetchRunsForLead(leadId),
  })

  if (isLoading) {
    return (
      <CardBody>
        <Skeleton className="h-16 w-full" />
      </CardBody>
    )
  }

  // A trace that cannot load must not take the lead page down with it: this is
  // supporting detail, and the rest of the screen is the rep's actual work.
  if (isError || !data || data.runs.length === 0) {
    return (
      <CardBody>
        <p className="t-caption">{t('automation.trace.empty')}</p>
      </CardBody>
    )
  }

  return (
    <CardBody>
      <ol className="space-y-3" data-testid="automation-trace">
        {data.runs.map((run) => (
          <li key={run.id} className="flex gap-3">
            <Outcome outcome={run.outcome} />

            <div className="min-w-0 flex-1 space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="t-body font-medium">
                  {run.rule?.name ?? t('automation.trace.deletedRule')}
                </span>
                <Badge tone={TONE[run.outcome] ?? 'neutral'}>
                  {t(`automation.trace.outcome.${run.outcome}`)}
                </Badge>
              </div>

              {run.reason && <p className="t-caption">{run.reason}</p>}

              {run.applied_actions && run.applied_actions.length > 0 && (
                <ul className="t-caption space-y-0.5">
                  {run.applied_actions.map((action, index) => (
                    <li
                      key={index}
                      className={action.ok ? undefined : 'text-[var(--color-danger)]'}
                    >
                      — {action.description}
                    </li>
                  ))}
                </ul>
              )}

              <p className="t-caption">
                {t(`automation.trigger.${run.trigger}`, { defaultValue: run.trigger })}
                {run.created_at && ` · ${formatDateTime(run.created_at, i18n.language)}`}
              </p>
            </div>
          </li>
        ))}
      </ol>
    </CardBody>
  )
}

const TONE: Record<string, 'success' | 'neutral' | 'danger'> = {
  applied: 'success',
  skipped: 'neutral',
  failed: 'danger',
}

function Outcome({ outcome }: { outcome: AutomationRun['outcome'] }) {
  const className = 'size-4 mt-0.5 shrink-0'

  switch (outcome) {
    case 'applied':
      return <PlayCircle className={`${className} text-[var(--color-status-won)]`} aria-hidden />
    case 'failed':
      return <TriangleAlert className={`${className} text-[var(--color-danger)]`} aria-hidden />
    default:
      return <CircleSlash className={`${className} text-[var(--color-ink-subtle)]`} aria-hidden />
  }
}
