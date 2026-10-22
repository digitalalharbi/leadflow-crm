import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, Input, Select, Skeleton } from '@/components/ui'
import type { Source } from '@/features/anomalySettings'
import { fetchProjects } from '@/features/leads'
import { fetchSelfApproval, saveSelfApproval, type SelfApproveAction } from '@/features/selfApproval'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

const SOURCE_STYLE: Record<Source, string> = {
  project: 'bg-[var(--brand)] text-[var(--brand-contrast)]',
  workspace: 'bg-[color-mix(in_srgb,var(--color-info)_18%,transparent)] text-[var(--color-info)]',
  default: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

/**
 * Safe self-approve: whether a person's own pause, resume or guarded budget change runs at once
 * instead of waiting in the inbox — for the whole workspace, or for one project that wants it
 * otherwise. Off until somebody turns it on. What always waits for a person is listed, so nobody
 * expects the switch to do more than it does.
 */
export function SelfApprovalPanel() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [projectId, setProjectId] = useState('')
  const [guard, setGuard] = useState<string | null>(null)

  const projects = useQuery({ queryKey: ['projects'], queryFn: fetchProjects })
  const { data } = useQuery({ queryKey: ['self-approval', projectId], queryFn: () => fetchSelfApproval(projectId || undefined) })

  const save = useMutation({
    mutationFn: (changes: Parameters<typeof saveSelfApproval>[0]) => saveSelfApproval(changes, projectId || undefined),
    onSuccess: (result) => {
      queryClient.setQueryData(['self-approval', projectId], result)
      void queryClient.invalidateQueries({ queryKey: ['campaign-operations'] })
      setGuard(null)
      toast(t('common.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const Badge = ({ source, testId }: { source: Source; testId: string }) => (
    <span className={cn('rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold', SOURCE_STYLE[source])} data-testid={testId}>{t(`thresholds.source.${source}`)}</span>
  )

  const toggleAction = (action: SelfApproveAction, on: boolean) => {
    if (!data) return
    const next = on ? [...new Set([...data.actions, action])] : data.actions.filter((a) => a !== action)
    save.mutate({ actions: next })
  }

  const commitGuard = () => {
    if (guard === null || !data) return
    const value = guard === '' ? null : Math.max(0, Math.min(100, Number(guard)))
    if (value !== data.max_budget_change_pct) save.mutate({ max_budget_change_pct: value })
    else setGuard(null)
  }

  return (
    <Card data-testid="self-approval">
      <CardHeader title={t('selfApproval.title')} description={t('selfApproval.hint')} />
      <CardBody className="space-y-4">
        <Select label={t('anomalySettings.scope')} value={projectId} onChange={(e) => { setProjectId(e.target.value); setGuard(null) }} data-testid="self-approve-scope" className="max-w-xs">
          <option value="">{t('anomalySettings.workspace')}</option>
          {(projects.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        {projectId && <p className="t-caption">{t('selfApproval.projectHint')}</p>}

        {!data ? <Skeleton className="h-56" /> : (
          <>
            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2.5" data-testid="self-approve-mode" data-enabled={data.enabled}>
              <span className={cn('grid size-9 place-items-center rounded-full', data.enabled ? 'bg-[var(--brand-soft)] text-[var(--brand)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')} aria-hidden><ShieldCheck className="size-5" /></span>
              <label className="flex flex-1 cursor-pointer items-center gap-2 text-[0.9375rem] font-semibold">
                <input type="checkbox" role="switch" checked={data.enabled} disabled={save.isPending} data-testid="self-approve-enabled" onChange={(e) => save.mutate({ enabled: e.target.checked })} className="size-4 accent-[var(--brand)]" />
                {data.enabled ? t('selfApproval.on') : t('selfApproval.off')}
              </label>
              <Badge source={data.enabled_source} testId="self-approve-enabled-source" />
              {(data.enabled_source === 'project' || (!projectId && data.enabled_source === 'workspace')) && (
                <button type="button" aria-label={t('anomalySettings.reset')} title={t('anomalySettings.reset')} data-testid="self-approve-reset" disabled={save.isPending} onClick={() => save.mutate({ enabled: null, actions: null, max_budget_change_pct: null })} className="pressable grid size-7 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]">
                  <RotateCcw className="size-3.5" aria-hidden />
                </button>
              )}
            </div>

            <div className="space-y-2" data-testid="self-approve-actions">
              <p className="flex items-center gap-2 text-[0.9375rem] font-semibold">{t('selfApproval.actions')} <Badge source={data.actions_source} testId="self-approve-actions-source" /></p>
              <ul className="grid gap-1.5 sm:grid-cols-3">
                {data.configurable.map((action) => (
                  <li key={action}>
                    <label className={cn('flex cursor-pointer items-start gap-2 rounded-xl border px-3 py-2', data.actions.includes(action) ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-line)]', !data.enabled && 'opacity-60')}>
                      <input type="checkbox" checked={data.actions.includes(action)} disabled={save.isPending || !data.enabled} data-testid={`self-approve-action-${action}`} onChange={(e) => toggleAction(action, e.target.checked)} className="mt-1 size-4 accent-[var(--brand)]" />
                      <span>
                        <span className="block text-[0.9375rem] font-semibold">{t(`proposals.action.${action}`)}</span>
                        <span className="t-caption block">{t(`selfApproval.about.${action}`)}</span>
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
            </div>

            <div className="flex flex-wrap items-end gap-3" data-testid="self-approve-guard">
              <Input
                type="number" dir="ltr" className="w-28" min={0} max={100}
                label={t('selfApproval.guard')}
                value={guard ?? String(data.max_budget_change_pct)}
                disabled={!data.enabled || !data.actions.includes('update_budget')}
                data-testid="self-approve-guard-input"
                onChange={(e) => setGuard(e.target.value)}
                onBlur={commitGuard}
              />
              <span className="pb-2 text-[0.875rem] text-[var(--color-ink-subtle)]">%</span>
              <Badge source={data.max_budget_change_pct_source} testId="self-approve-guard-source" />
              <p className="t-caption basis-full">{data.max_budget_change_pct > 0 ? t('selfApproval.guardHint', { pct: data.max_budget_change_pct }) : t('selfApproval.guardZero')}</p>
            </div>

            <div className="rounded-xl border border-[var(--color-line)] px-3 py-2.5" data-testid="self-approve-always-manual">
              <p className="text-[0.9375rem] font-semibold">{t('selfApproval.alwaysManual')}</p>
              <p className="t-caption">{t('selfApproval.alwaysManualHint')}</p>
              <ul className="mt-1.5 flex flex-wrap gap-1.5">
                {data.always_manual.map((a) => <li key={a} className="rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-0.5 text-[0.8125rem] font-semibold">{t(`proposals.action.${a}`, { defaultValue: a })}</li>)}
                <li className="rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-0.5 text-[0.8125rem] font-semibold">{t('selfApproval.agents')}</li>
              </ul>
              <p className="t-caption mt-1.5">{t('selfApproval.syncNote')}</p>
            </div>
          </>
        )}
      </CardBody>
    </Card>
  )
}
