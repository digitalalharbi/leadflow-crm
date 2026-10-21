import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, Input, Select, Skeleton } from '@/components/ui'
import { fetchAnomalySettings, saveAnomalySettings, type AlertKind, type AlertSetting, type Source } from '@/features/anomalySettings'
import { fetchProjects } from '@/features/leads'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

const SOURCE_STYLE: Record<Source, string> = {
  project: 'bg-[var(--brand)] text-[var(--brand-contrast)]',
  workspace: 'bg-[color-mix(in_srgb,var(--color-info)_18%,transparent)] text-[var(--color-info)]',
  default: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

/**
 * How sensitive the alerts are: each one on or off, and the line it fires at — for the whole
 * workspace, or for one project that needs something different. What is in force shows where it
 * comes from (this project, the workspace, the built-in default); the arrow gives it back.
 */
export function AnomalySettingsPanel() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [projectId, setProjectId] = useState('')
  const [draft, setDraft] = useState<Partial<Record<AlertKind, string>>>({})

  const projects = useQuery({ queryKey: ['projects'], queryFn: fetchProjects })
  const { data } = useQuery({ queryKey: ['anomaly-settings', projectId], queryFn: () => fetchAnomalySettings(projectId || undefined) })

  const save = useMutation({
    mutationFn: (alerts: Parameters<typeof saveAnomalySettings>[0]) => saveAnomalySettings(alerts, projectId || undefined),
    onSuccess: (result) => {
      queryClient.setQueryData(['anomaly-settings', projectId], result)
      setDraft({})
      toast(t('common.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const commit = (alert: AlertSetting) => {
    const raw = draft[alert.kind]

    if (raw === undefined || !alert.threshold) return
    const value = raw === '' ? null : Number(raw)

    if (value !== null && value !== alert.threshold.value) save.mutate({ [alert.kind]: { value } })
    else setDraft((d) => { const next = { ...d }; delete next[alert.kind]; return next })
  }

  return (
    <Card data-testid="anomaly-settings">
      <CardHeader title={t('anomalySettings.title')} description={t('anomalySettings.hint')} />
      <CardBody className="space-y-4">
        <Select label={t('anomalySettings.scope')} value={projectId} onChange={(e) => { setProjectId(e.target.value); setDraft({}) }} data-testid="anomaly-scope" className="max-w-xs">
          <option value="">{t('anomalySettings.workspace')}</option>
          {(projects.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        {projectId && <p className="t-caption">{t('anomalySettings.projectHint')}</p>}

        {!data ? <Skeleton className="h-72" /> : (
          <ul className="divide-y divide-[var(--color-line)]" data-testid="anomaly-list">
            {data.alerts.map((alert) => (
              <li key={alert.kind} className="grid items-center gap-3 py-3 sm:grid-cols-[1fr_auto]" data-testid={`alert-${alert.kind}`} data-enabled={alert.enabled}>
                <div className="min-w-0">
                  <p className="text-[0.9375rem] font-semibold">{t(`anomalySettings.kind.${alert.kind}`)}</p>
                  <p className="t-caption">{t(`anomalySettings.about.${alert.kind}`)}</p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                  {alert.threshold && (
                    <div className="flex items-end gap-1.5">
                      <Input
                        type="number"
                        dir="ltr"
                        className="w-28"
                        label={t(`anomalySettings.line.${alert.kind}`)}
                        min={alert.threshold.min}
                        max={alert.threshold.max}
                        value={draft[alert.kind] ?? String(alert.threshold.value)}
                        disabled={!alert.enabled}
                        data-testid={`alert-value-${alert.kind}`}
                        onChange={(e) => setDraft((d) => ({ ...d, [alert.kind]: e.target.value }))}
                        onBlur={() => commit(alert)}
                      />
                      <span className="pb-2 text-[0.875rem] text-[var(--color-ink-subtle)]">{alert.threshold.unit === 'percent' ? '%' : t('anomalySettings.currency')}</span>
                      <span className={cn('mb-1.5 rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold', SOURCE_STYLE[alert.threshold.source])} data-testid={`alert-source-${alert.kind}`}>{t(`thresholds.source.${alert.threshold.source}`)}</span>
                      {alert.threshold.source === 'project' || (!projectId && alert.threshold.source === 'workspace') ? (
                        <button type="button" aria-label={t('anomalySettings.reset')} title={t('anomalySettings.reset')} data-testid={`alert-reset-${alert.kind}`} disabled={save.isPending} onClick={() => save.mutate({ [alert.kind]: { value: null } })} className="pressable mb-1 grid size-7 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]">
                          <RotateCcw className="size-3.5" aria-hidden />
                        </button>
                      ) : null}
                    </div>
                  )}
                  <label className="flex cursor-pointer items-center gap-2 text-[0.875rem]">
                    <input
                      type="checkbox"
                      role="switch"
                      checked={alert.enabled}
                      disabled={save.isPending}
                      data-testid={`alert-enabled-${alert.kind}`}
                      onChange={(e) => save.mutate({ [alert.kind]: { enabled: e.target.checked } })}
                      className="size-4 accent-[var(--brand)]"
                    />
                    {alert.enabled ? t('anomalySettings.on') : t('anomalySettings.off')}
                  </label>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
