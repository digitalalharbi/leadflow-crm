import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Skeleton } from '@/components/ui'
import { createForm, fetchFormTemplates } from '@/features/hub'
import { fetchProjects } from '@/features/leads'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

/** Three questions — what to call it, which project, which template to start from — and the builder opens with a form that already works. */
export function NewFormDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: string) => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [name, setName] = useState('')
  const [project, setProject] = useState('')
  const [template, setTemplate] = useState('real_estate_interest')
  const { data: projects } = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects, enabled: open })
  const { data: templates } = useQuery({ queryKey: ['form-templates'], queryFn: fetchFormTemplates, enabled: open })

  const create = useMutation({
    mutationFn: () => createForm({ name, project_id: project || null, template }),
    onSuccess: ({ form }) => onCreated(form.id),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal open={open} onClose={onClose} title={t('hub.builder.new.title')}
      footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button onClick={() => create.mutate()} loading={create.isPending} disabled={name.trim() === ''} data-testid="new-form-create">{t('hub.builder.new.create')}</Button></>}>
      <div className="space-y-4">
        <Input label={t('hub.forms.name')} value={name} onChange={(e) => setName(e.target.value)} autoFocus data-testid="new-form-name" />
        <Select label={t('tracking.project')} value={project} onChange={(e) => setProject(e.target.value)} data-testid="new-form-project">
          <option value="">—</option>
          {(projects ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <div>
          <p className="mb-1.5 text-[0.9375rem] font-semibold">{t('hub.builder.new.template')}</p>
          {!templates ? <Skeleton className="h-24" /> : (
            <div role="radiogroup" aria-label={t('hub.builder.new.template')} className="grid gap-2 sm:grid-cols-2" data-testid="new-form-templates">
              {templates.map((tpl) => (
                <button key={tpl.key} type="button" role="radio" aria-checked={template === tpl.key} onClick={() => setTemplate(tpl.key)} data-testid={`template-${tpl.key}`}
                  className={cn('pressable relative rounded-xl border p-3 text-start', template === tpl.key ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}>
                  {template === tpl.key && <Check className="absolute end-2 top-2 size-4 text-[var(--brand-text)]" aria-hidden />}
                  <span className="block font-bold">{tpl.name}</span>
                  <span className="mt-0.5 block text-[0.8125rem] text-[var(--color-ink-muted)]">{tpl.description}</span>
                  <span className="mt-1 block text-[0.75rem] text-[var(--color-ink-subtle)]">{t('hub.forms.fieldCount', { n: tpl.fields })}{tpl.steps > 1 ? ` · ${t('hub.forms.stepCount', { n: tpl.steps })}` : ''}</span>
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </Modal>
  )
}
