import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, CardHeader, Skeleton } from '@/components/ui'
import { createShare, fetchShares, shareAction } from '@/features/reportShares'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

const PRESETS = ['executive_management', 'marketing', 'sales', 'client'] as const

/** This project's live and frozen reports, made from a preset in one press. The same reports as everywhere else, limited to this project. */
export function ProjectLiveReports({ projectId, projectName }: { projectId: string; projectName: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data, isLoading } = useQuery({ queryKey: ['report-shares'], queryFn: fetchShares })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['report-shares'] })

  const create = useMutation({
    mutationFn: (preset: (typeof PRESETS)[number]) => createShare({ name: `${projectName} — ${t(`share.template.${preset}`)}`, preset, project_id: projectId }),
    onSuccess: () => { toast(t('share.projectCreated'), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const freeze = useMutation({
    mutationFn: (id: string) => shareAction(id, 'snapshot'),
    onSuccess: () => { toast(t('share.done.snapshot'), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const rows = (data?.shares ?? []).filter((share) => share.project?.id === projectId)

  return (
    <Card data-testid="project-live-reports">
      <CardHeader title={t('share.projectTitle')} description={t('share.projectHint')} />
      <CardBody className="space-y-3">
        <div className="flex flex-wrap gap-2">
          {PRESETS.map((preset) => (
            <Button key={preset} size="sm" variant="outline" loading={create.isPending && create.variables === preset} onClick={() => create.mutate(preset)} data-testid={`project-report-${preset}`}>{t(`share.template.${preset}`)}</Button>
          ))}
        </div>
        {isLoading ? <Skeleton className="h-16" /> : rows.length === 0 ? <p className="t-caption">{t('share.emptyTitle')}</p> : (
          <ul className="divide-y divide-[var(--color-line)]">
            {rows.map((share) => (
              <li key={share.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <a href={share.url} target="_blank" rel="noreferrer" className="min-w-0 truncate font-medium underline-offset-2 hover:underline">{share.name}</a>
                <span className="flex items-center gap-2">
                  {share.kind === 'snapshot' && <Badge tone="info">{t('share.snapshot')}</Badge>}
                  <Badge tone={share.status === 'active' ? 'success' : 'neutral'}>{t(`share.status.${share.status}`)}</Badge>
                  {share.kind !== 'snapshot' && <Button size="sm" variant="ghost" loading={freeze.isPending && freeze.variables === share.id} onClick={() => freeze.mutate(share.id)}>{t('share.snapshot')}</Button>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
