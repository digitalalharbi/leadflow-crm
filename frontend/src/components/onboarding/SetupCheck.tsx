import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CheckCircle2, CircleAlert, PlayCircle, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  deleteTestLead, fetchTestLeadTrace, runTestLead,
  type DiagnosticStep, type TestLeadReport,
} from '@/features/onboarding'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Button, Card, CardBody, CardHeader } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * "Does any of this actually work for us?"
 *
 * The question every workspace has on its first day, and the one the settings
 * screens cannot answer — reading nine of them and concluding "probably" is
 * what people do instead, and they find out it did not when a real buyer goes
 * uncalled.
 *
 * Each stage reports what it did rather than whether a box is ticked:
 * "assigned to نورة الشمري" tells a manager their rota works; a green tick
 * tells them nothing they can check.
 */
export function SetupCheck() {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [report, setReport] = useState<TestLeadReport | null>(null)

  const run = useMutation({
    mutationFn: runTestLead,
    onSuccess: setReport,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: string) => deleteTestLead(id),
    onSuccess: () => {
      setReport(null)
      toast(t('setupCheck.removed'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  /*
   * Automation runs on a queue, so the trace arrives a second or two after the
   * lead does. Polled briefly rather than reported as missing — "nothing ran"
   * and "not yet" look identical and mean opposite things.
   */
  const trace = useQuery({
    queryKey: ['test-lead-trace', report?.lead?.id],
    queryFn: () => fetchTestLeadTrace(report!.lead!.id),
    enabled: Boolean(report?.lead?.id),
    refetchInterval: (query) => (query.state.data?.automation.length ? false : 3000),
  })

  return (
    <Card>
      <CardHeader title={t('setupCheck.title')} description={t('setupCheck.description')} />
      <CardBody className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Button
            loading={run.isPending}
            icon={<PlayCircle className="size-4" aria-hidden />}
            data-testid="run-setup-check"
            onClick={() => run.mutate()}
          >
            {t('setupCheck.run')}
          </Button>

          {report?.lead && (
            <Button
              variant="ghost"
              loading={remove.isPending}
              icon={<Trash2 className="size-4" aria-hidden />}
              data-testid="remove-test-lead"
              onClick={() => remove.mutate(report.lead!.id)}
            >
              {t('setupCheck.remove')}
            </Button>
          )}
        </div>

        {report && (
          <ol className="space-y-2" data-testid="setup-check-steps">
            {report.steps.map((step) => (
              <Step key={step.key} step={step} />
            ))}
          </ol>
        )}

        {report?.lead && (
          <div className="border-t border-[var(--color-border)] pt-3">
            <p className="t-caption">
              {trace.data?.automation.length
                ? t('setupCheck.rulesRan', { count: trace.data.automation.length })
                : t('setupCheck.rulesPending')}
            </p>
          </div>
        )}
      </CardBody>
    </Card>
  )
}

function Step({ step }: { step: DiagnosticStep }) {
  const { t } = useTranslation()

  return (
    <li className="flex items-start gap-2.5">
      {step.ok ? (
        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[var(--color-status-won)]" aria-hidden />
      ) : (
        <CircleAlert className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]" aria-hidden />
      )}
      <div className="min-w-0">
        <p className="t-body font-medium">{t(`setupCheck.step.${step.key}`)}</p>
        {/*
          The detail, always. A stage that passed still says what it did, so a
          manager can check the answer rather than trust the tick.
        */}
        <p className={cn('t-caption', !step.ok && 'text-[var(--color-warning)]')}>{step.detail}</p>
      </div>
    </li>
  )
}
