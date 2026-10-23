import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FilePlus2, Link2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, ErrorState, Skeleton } from '@/components/ui'
import { fetchForms } from '@/features/hub'
import { cn } from '@/lib/cn'
import { FormEditor } from './FormEditor'

/** The landing forms of the workspace: each with the project it is for, how many fields it asks and how many links open it. */
export function FormsPanel({ canEdit }: { canEdit: boolean }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState<string | null | undefined>(undefined)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['landing-forms'], queryFn: fetchForms })

  return (
    <div className="space-y-3" data-testid="forms-panel">
      <div className="flex items-center justify-between gap-2">
        <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.forms.intro')}</p>
        {canEdit && <Button icon={<FilePlus2 className="size-4" />} onClick={() => setEditing(null)} data-testid="new-form">{t('hub.forms.new')}</Button>}
      </div>
      {isLoading ? <Skeleton className="h-40" /> : isError ? <ErrorState onRetry={() => void refetch()} /> : (data ?? []).length === 0 ? (
        <EmptyState title={t('hub.forms.emptyTitle')} body={t('hub.forms.emptyBody')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="form-list">
          {data!.map((f) => (
            <li key={f.id} className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`form-${f.id}`}>
              <button type="button" className="pressable w-full text-start" onClick={() => setEditing(f.id)} data-testid={`open-form-${f.id}`}>
                <span className="flex items-start justify-between gap-2">
                  <span className="truncate text-[1.0625rem] font-extrabold">{f.name}</span>
                  <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', f.status === 'published' ? 'bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] text-[var(--color-success)]' : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]')} data-testid={`form-status-${f.id}`}>{t(`hub.forms.status.${f.status}`)}</span>
                </span>
                <span className="mt-1 block truncate text-[0.875rem] text-[var(--color-ink-subtle)]">{[f.project?.name, t('hub.forms.fieldCount', { n: f.fields.length }), f.steps > 1 ? t('hub.forms.stepCount', { n: f.steps }) : null].filter(Boolean).join(' · ')}</span>
                <span className="mt-2 inline-flex items-center gap-1 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]"><Link2 className="size-3.5" aria-hidden />{t('hub.forms.linkCount', { n: f.links_count ?? 0 })}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      <FormEditor open={editing !== undefined} formId={editing ?? null} canEdit={canEdit} onClose={() => setEditing(undefined)} />
    </div>
  )
}
