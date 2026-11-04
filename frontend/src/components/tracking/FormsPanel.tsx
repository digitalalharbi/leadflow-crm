import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, ExternalLink, FilePlus2, Link2, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, ErrorState, Skeleton } from '@/components/ui'
import { NewFormDialog } from '@/components/forms/builder/NewFormDialog'
import { duplicateForm, fetchForms } from '@/features/hub'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

/** The landing forms of the workspace: each with the project it is for, what is live, and a way to open it in the builder, copy it or start another. */
export function FormsPanel({ canEdit }: { canEdit: boolean }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [creating, setCreating] = useState(false)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['landing-forms'], queryFn: fetchForms })
  const copyLink = async (path: string) => {
    const url = `${window.location.origin}${path}`
    try { await navigator.clipboard.writeText(url); toast(t('tracking.copied'), 'success') } catch { toast(url, 'info') }
  }

  const copy = useMutation({
    mutationFn: (id: string) => duplicateForm(id),
    onSuccess: ({ form }) => { void queryClient.invalidateQueries({ queryKey: ['landing-forms'] }); navigate(`/tracking/forms/${form.id}`) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <div className="space-y-3" data-testid="forms-panel">
      <div className="flex items-center justify-between gap-2">
        <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.forms.intro')}</p>
        {canEdit && <Button icon={<FilePlus2 className="size-4" />} onClick={() => setCreating(true)} data-testid="new-form">{t('hub.forms.new')}</Button>}
      </div>
      {isLoading ? <Skeleton className="h-40" /> : isError ? <ErrorState onRetry={() => void refetch()} /> : (data ?? []).length === 0 ? (
        <EmptyState title={t('hub.forms.emptyTitle')} body={t('hub.forms.emptyBody')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="form-list">
          {data!.map((f) => (
            <li key={f.id} className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`form-${f.id}`}>
              <button type="button" className="pressable w-full text-start" onClick={() => navigate(`/tracking/forms/${f.id}`)} data-testid={`open-form-${f.id}`}>
                <span className="flex items-start justify-between gap-2">
                  <span className="truncate text-[1.0625rem] font-extrabold">{f.name}</span>
                  <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', f.status === 'published' ? 'bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] text-[var(--color-success)]' : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]')} data-testid={`form-status-${f.id}`}>
                    {f.status === 'published' && f.published_version ? t('hub.builder.liveVersion', { n: f.published_version.version }) : t(`hub.forms.status.${f.status}`)}
                  </span>
                </span>
                <span className="mt-1 block truncate text-[0.875rem] text-[var(--color-ink-subtle)]">{[f.project?.name, t('hub.forms.fieldCount', { n: f.fields.filter((x) => !x.hidden && !['heading', 'paragraph', 'divider'].includes(x.type)).length }), f.steps > 1 ? t('hub.forms.stepCount', { n: f.steps }) : null].filter(Boolean).join(' · ')}</span>
                <span className="mt-2 flex items-center gap-3 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">
                  <span className="inline-flex items-center gap-1"><Link2 className="size-3.5" aria-hidden />{t('hub.forms.linkCount', { n: f.links_count ?? 0 })}</span>
                  {f.leads_count != null && <span className="inline-flex items-center gap-1" data-testid={`form-leads-${f.id}`}><Users2 className="size-3.5" aria-hidden />{t('hub.forms.leadCount', { n: f.leads_count })}</span>}
                  {f.has_unpublished_changes && <span className="text-[var(--color-warning)]" data-testid={`form-unpublished-${f.id}`}>{t('hub.builder.unpublished')}</span>}
                </span>
              </button>
              {f.status === 'published' && f.public_path && (
                <span className="mt-1 flex flex-wrap gap-1">
                  <Button size="sm" variant="ghost" icon={<Link2 className="size-4" />} onClick={() => void copyLink(f.public_path as string)} data-testid={`copy-form-link-${f.id}`}>{t('hub.forms.copyLink')}</Button>
                  <a href={f.public_path} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-[0.875rem] font-semibold text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-muted)]" data-testid={`view-form-${f.id}`}><ExternalLink className="size-4" aria-hidden />{t('hub.forms.viewLive')}</a>
                </span>
              )}
              {canEdit && <Button size="sm" variant="ghost" className="mt-1" icon={<Copy className="size-4" />} loading={copy.isPending && copy.variables === f.id} onClick={() => copy.mutate(f.id)} data-testid={`duplicate-form-${f.id}`}>{t('hub.builder.duplicateForm')}</Button>}
            </li>
          ))}
        </ul>
      )}
      <NewFormDialog open={creating} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); void queryClient.invalidateQueries({ queryKey: ['landing-forms'] }); navigate(`/tracking/forms/${id}`) }} />
    </div>
  )
}
