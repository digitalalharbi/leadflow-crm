import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input } from '@/components/ui'
import { fetchProjectThresholds, THRESHOLD_KEYS, updateProjectThresholds, type ThresholdKey } from '@/features/automation'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

const SOURCE_STYLE: Record<'project' | 'workspace' | 'default', string> = {
  project: 'bg-[var(--brand)] text-[var(--brand-contrast)]',
  workspace: 'bg-[color-mix(in_srgb,var(--color-info)_18%,transparent)] text-[var(--color-info)]',
  default: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

/**
 * The lines this project holds its leads to — what is hot, a priority, high
 * value, late, stalled, and how often automation may write. Each shows the
 * value in force and where it comes from (this project, the workspace, the
 * default); changing one overrides it for this project alone, and the arrow
 * puts the workspace's back.
 */
export function ThresholdsPanel({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [draft, setDraft] = useState<Partial<Record<ThresholdKey, string>>>({})

  const { data } = useQuery({ queryKey: ['project-thresholds', projectId], queryFn: () => fetchProjectThresholds(projectId) })

  const save = useMutation({
    mutationFn: (values: Partial<Record<ThresholdKey, number | null>>) => updateProjectThresholds(projectId, values),
    onSuccess: (result) => {
      queryClient.setQueryData(['project-thresholds', projectId], result)
      setDraft({})
      toast(t('common.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (!data) return null

  const dirty = Object.keys(draft).length > 0

  return (
    <div className="space-y-2 rounded-xl bg-[var(--color-surface-muted)] p-3" data-testid="thresholds-panel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-[0.9375rem] font-semibold">{t('thresholds.title')}</h3>
        <Button
          size="sm"
          variant="secondary"
          disabled={!dirty}
          loading={save.isPending}
          data-testid="thresholds-save"
          onClick={() => save.mutate(Object.fromEntries(Object.entries(draft).map(([key, value]) => [key, value === '' ? null : Number(value)])))}
        >
          {t('common.save')}
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
        {THRESHOLD_KEYS.map((key) => {
          const item = data.thresholds[key]

          return (
            <div key={key} data-testid={`threshold-${key}`} data-source={item.source} className="space-y-1">
              <Input
                type="number"
                dir="ltr"
                label={t(`thresholds.${key}`)}
                value={draft[key] ?? String(item.value)}
                onChange={(e) => setDraft((d) => ({ ...d, [key]: e.target.value }))}
              />
              <div className="flex items-center gap-1.5">
                <span className={cn('rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold', SOURCE_STYLE[item.source])}>{t(`thresholds.source.${item.source}`)}</span>
                {item.source === 'project' && (
                  <button
                    type="button"
                    aria-label={t('thresholds.reset')}
                    title={t('thresholds.reset', { value: item.workspace })}
                    data-testid={`threshold-reset-${key}`}
                    disabled={save.isPending}
                    onClick={() => save.mutate({ [key]: null })}
                    className="pressable grid size-6 place-items-center rounded-full hover:bg-black/10"
                  >
                    <RotateCcw className="size-3.5" aria-hidden />
                  </button>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
