import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CircleSlash, Clock, PlayCircle, TriangleAlert } from 'lucide-react'
import { cancelScheduledAction, fetchRunsForLead, type AutomationRun } from '@/features/automation'
import { formatDateTime } from '@/lib/format'
import { Badge, Button, CardBody, Skeleton } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

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

  const queryClient = useQueryClient()
  const toast = useUi((state) => state.toast)
  const translateError = useApiError()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['automation-runs', leadId],
    queryFn: () => fetchRunsForLead(leadId),
  })

  const cancel = useMutation({
    mutationFn: (id: string) => cancelScheduledAction(id),
    onSuccess: () => {
      toast(t('automation.trace.cancelled'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['automation-runs', leadId] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
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
  const scheduled = (data?.scheduled ?? []).filter((action) => action.status === 'pending')

  if (isError || !data || (data.runs.length === 0 && scheduled.length === 0)) {
    return (
      <CardBody>
        <p className="t-caption">{t('automation.trace.empty')}</p>
      </CardBody>
    )
  }

  return (
    <CardBody>
      {/*
        What is still coming, above what already happened.
        
        A trace of the past alone would say a rule "applied" on Tuesday and
        never mention the WhatsApp it arranged for Thursday — which is the one
        thing somebody reading this on Wednesday needs to know, and the only
        place they can stop it.
      */}
      {scheduled.length > 0 && (
        <ul className="mb-4 space-y-2" data-testid="scheduled-actions">
          {scheduled.map((action) => (
            <li
              key={action.id}
              className="flex flex-wrap items-center gap-2 rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] px-3 py-2"
            >
              <Clock className="size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
              <span className="t-small min-w-0 flex-1">
                {t('automation.trace.scheduled', {
                  action: t(`automation.action_.${action.type}`, { defaultValue: action.type }),
                  rule: action.rule_name,
                })}
                {' · '}
                <span className="text-[var(--color-ink-muted)]">
                  {formatDateTime(action.run_at, i18n.language)}
                </span>
              </span>
              <Button
                size="sm"
                variant="ghost"
                loading={cancel.isPending && cancel.variables === action.id}
                onClick={() => cancel.mutate(action.id)}
                data-testid={`cancel-scheduled-${action.id}`}
              >
                {t('automation.trace.cancelScheduled')}
              </Button>
            </li>
          ))}
        </ul>
      )}

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
