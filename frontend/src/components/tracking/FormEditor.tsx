import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, ChevronDown, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, Input, Select, Skeleton, Textarea } from '@/components/ui'
import { FieldInput } from '@/pages/public/LeadPage'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useApiError } from '@/hooks/useApiError'
import { fetchProjects } from '@/features/leads'
import { createForm, deleteForm, fetchFormPresets, fetchForms, FIELD_TYPES, isVisible, updateForm, type FormField, type FormInput } from '@/features/hub'
import { useUi } from '@/stores/ui'

const BLANK: FormInput & { project_id: string } = {
  name: '', project_id: '', status: 'draft', headline: '', description: '', benefits: [], cta_label: '', thank_you_title: '', thank_you_message: '',
  consent_required: true, consent_text: '', privacy_url: '', logo_url: '', image_url: '', fields: [],
}

/** `value:label` per line, as people write a list; the first colon splits, so a label may have one. */
export const optionsToText = (options: FormField['options']): string => (options ?? []).map((o) => (o.label === o.value ? o.value : `${o.value}:${o.label}`)).join('\n')
export const textToOptions = (text: string): { value: string; label: string }[] =>
  text.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => { const i = l.indexOf(':'); return i < 0 ? { value: l, label: l } : { value: l.slice(0, i).trim(), label: l.slice(i + 1).trim() || l.slice(0, i).trim() } })

const HAS_OPTIONS = ['select', 'multi_select', 'radio']

/** A key a person never has to invent: the next free `field_n`. */
const freeKey = (fields: FormField[]) => { let n = fields.length + 1; while (fields.some((f) => f.key === `field_${n}`)) n++; return `field_${n}` }

/**
 * The form a landing page asks, designed on the screen: what to say, which questions, in what order and on which step, which are
 * required, which appear only after another was answered, and where each answer lands on the lead. A form that could not work is
 * refused by the server with the field it is about — it is shown here beside that field, never saved half-made.
 */
export function FormEditor({ open, formId, onClose, canEdit }: { open: boolean; formId: string | null; onClose: () => void; canEdit: boolean }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [form, setForm] = useState(BLANK)
  const [openField, setOpenField] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [preview, setPreview] = useState(false)
  const [answers, setAnswers] = useState<Record<string, string | string[] | boolean>>({})

  const { data: forms, isLoading } = useQuery({ queryKey: ['landing-forms'], queryFn: fetchForms, enabled: open })
  const { data: presets } = useQuery({ queryKey: ['landing-form-presets'], queryFn: fetchFormPresets, enabled: open })
  const { data: projects } = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects, enabled: open })

  useEffect(() => {
    if (!open) return
    setErrors({}); setOpenField(null); setPreview(false); setAnswers({})
    const existing = formId ? forms?.find((f) => f.id === formId) : undefined
    if (existing) setForm({ ...BLANK, ...existing, project_id: existing.project?.id ?? '', benefits: existing.benefits ?? [] })
    else if (formId === null) setForm(BLANK)
  }, [open, formId, forms])

  const set = <K extends keyof typeof BLANK>(key: K, value: (typeof BLANK)[K]) => setForm((f) => ({ ...f, [key]: value }))
  const fields = form.fields ?? []
  const setField = (key: string, patch: Partial<FormField>) => set('fields', fields.map((f) => (f.key === key ? { ...f, ...patch } : f)))
  const move = (i: number, by: -1 | 1) => { const next = [...fields]; [next[i], next[i + by]] = [next[i + by], next[i]]; set('fields', next.map((f, n) => ({ ...f, order: n + 1 }))) }
  const add = (field: FormField) => { set('fields', [...fields, { ...field, order: fields.length + 1 }]); setOpenField(field.key) }

  const payload = (): FormInput => ({
    ...form,
    project_id: form.project_id || null,
    logo_url: form.logo_url || null, image_url: form.image_url || null, privacy_url: form.privacy_url || null,
    benefits: (form.benefits ?? []).filter((b) => b.trim() !== ''),
    fields: fields.map((f, i) => ({ ...f, order: i + 1, options: HAS_OPTIONS.includes(f.type) ? f.options : [], visible_if: f.visible_if?.field ? f.visible_if : null })),
  })

  const save = useMutation({
    mutationFn: () => (formId ? updateForm(formId, payload()) : createForm(payload())),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['landing-forms'] })
      void queryClient.invalidateQueries({ queryKey: ['project-acquisition'] })
      toast(t('common.saved'), 'success')
      onClose()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) {
        // "field_1: invalid_key" → beside field_1; the form as a whole has its own line.
        const found: Record<string, string> = {}
        for (const message of Object.values(caught.errors).flat()) {
          const [key, code] = message.split(': ')
          found[code ? key : 'form_level'] = code ?? message
        }
        setErrors(found)
        const first = Object.keys(found).find((k) => fields.some((f) => f.key === k))
        if (first) setOpenField(first)
      } else toast(translateError(caught), 'error')
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteForm(formId!),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['landing-forms'] }); onClose() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const errorText = (code?: string) => (code ? t(`hub.forms.error.${code}`, { defaultValue: t('errors.validation') }) : undefined)
  const unused = (presets?.presets ?? []).filter((p) => !fields.some((f) => f.key === p.key))

  return (
    <Drawer open={open} onClose={onClose} title={formId ? t('hub.forms.edit') : t('hub.forms.new')} testId="form-editor" size="xl"
      footer={canEdit ? (
        <>
          {formId && <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-form">{t('common.delete')}</Button>}
          <Button variant="secondary" onClick={() => setPreview((p) => !p)} data-testid="toggle-preview">{preview ? t('hub.forms.design') : t('hub.forms.preview')}</Button>
          <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!form.name || fields.length === 0} data-testid="save-form">{t('common.save')}</Button>
        </>
      ) : undefined}>
      {formId && isLoading ? <Skeleton className="h-48" /> : preview ? (
        <section className="mx-auto max-w-md space-y-4 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-card)]" data-testid="form-preview">
          <h2 className="text-[1.375rem] font-extrabold">{form.headline || form.name}</h2>
          {form.description && <p className="text-[var(--color-ink-muted)]">{form.description}</p>}
          {fields.filter((f) => isVisible(f, answers)).map((f) => (
            <FieldInput key={f.key} field={f} value={answers[f.key]} onChange={(v) => setAnswers((a) => ({ ...a, [f.key]: v }))} />
          ))}
          <Button className="w-full" type="button">{form.cta_label || t('hub.public.submit')}</Button>
        </section>
      ) : (
        <div className="space-y-6">
          <section className="grid gap-4 sm:grid-cols-2" data-testid="form-basics">
            <Input label={t('hub.forms.name')} value={form.name ?? ''} onChange={(e) => set('name', e.target.value)} disabled={!canEdit} data-testid="form-name" />
            <Select label={t('tracking.project')} value={form.project_id} onChange={(e) => set('project_id', e.target.value)} disabled={!canEdit} data-testid="form-project">
              <option value="">—</option>
              {(projects ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
            <Input label={t('hub.forms.headline')} value={form.headline ?? ''} onChange={(e) => set('headline', e.target.value)} disabled={!canEdit} data-testid="form-headline" />
            <Input label={t('hub.forms.cta')} value={form.cta_label ?? ''} onChange={(e) => set('cta_label', e.target.value)} disabled={!canEdit} data-testid="form-cta" />
            <Textarea containerClassName="sm:col-span-2" label={t('hub.forms.description')} rows={2} value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} disabled={!canEdit} />
            <Select label={t('hub.forms.statusLabel')} value={form.status} onChange={(e) => set('status', e.target.value as 'draft')} disabled={!canEdit} data-testid="form-status">
              {(['draft', 'published', 'archived'] as const).map((s) => <option key={s} value={s}>{t(`hub.forms.status.${s}`)}</option>)}
            </Select>
            <Input label={t('hub.forms.privacyUrl')} dir="ltr" placeholder="https://" value={form.privacy_url ?? ''} onChange={(e) => set('privacy_url', e.target.value)} disabled={!canEdit} />
            <Textarea containerClassName="sm:col-span-2" label={t('hub.forms.benefits')} hint={t('hub.forms.benefitsHint')} rows={3} value={(form.benefits ?? []).join('\n')} onChange={(e) => set('benefits', e.target.value.split('\n').slice(0, 6))} disabled={!canEdit} />
            <Input label={t('hub.forms.thanksTitle')} value={form.thank_you_title ?? ''} onChange={(e) => set('thank_you_title', e.target.value)} disabled={!canEdit} />
            <Input label={t('hub.forms.thanksMessage')} value={form.thank_you_message ?? ''} onChange={(e) => set('thank_you_message', e.target.value)} disabled={!canEdit} />
            <label className="flex items-center gap-2 text-[0.9375rem] sm:col-span-2"><input type="checkbox" className="size-5" checked={form.consent_required !== false} onChange={(e) => set('consent_required', e.target.checked)} disabled={!canEdit} data-testid="form-consent" />{t('hub.forms.consentRequired')}</label>
            <Input containerClassName="sm:col-span-2" label={t('hub.forms.consentText')} value={form.consent_text ?? ''} onChange={(e) => set('consent_text', e.target.value)} disabled={!canEdit} />
          </section>

          <section data-testid="form-fields">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-extrabold">{t('hub.forms.fields')}</h3>
              {canEdit && (
                <div className="flex gap-2">
                  {unused.length > 0 && (
                    <Select aria-label={t('hub.forms.addPreset')} value="" onChange={(e) => { const p = unused.find((u) => u.key === e.target.value); if (p) add(p) }} className="min-w-40" data-testid="add-preset">
                      <option value="">{t('hub.forms.addPreset')}</option>
                      {unused.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
                    </Select>
                  )}
                  <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => add({ key: freeKey(fields), type: 'text', label: '', required: false, hidden: false, mapping: 'none' })} data-testid="add-field">{t('hub.forms.addField')}</Button>
                </div>
              )}
            </div>
            {errors.form_level && <p role="alert" className="mb-2 text-[0.9375rem] text-[var(--color-status-lost)]" data-testid="form-error">{errorText(errors.form_level)}</p>}
            {fields.length === 0 && <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.forms.noFields')}</p>}
            <ul className="space-y-2">
              {fields.map((f, i) => {
                const expanded = openField === f.key
                return (
                  <li key={f.key} className={cn('rounded-xl border', errors[f.key] ? 'border-[var(--color-status-lost)]' : 'border-[var(--color-border)]')} data-testid={`field-row-${f.key}`}>
                    <div className="flex items-center gap-1 px-2 py-1.5">
                      <button type="button" className="pressable flex min-w-0 flex-1 items-center gap-2 text-start" onClick={() => setOpenField(expanded ? null : f.key)} aria-expanded={expanded}>
                        <ChevronDown className={cn('size-4 shrink-0 transition-transform', expanded && 'rotate-180')} aria-hidden />
                        <span className="truncate font-semibold">{f.label || f.key}</span>
                        <span className="t-caption shrink-0 text-[var(--color-ink-subtle)]">{t(`hub.forms.type.${f.type}`)}{f.required ? ' *' : ''}{(f.step ?? 1) > 1 ? ` · ${t('hub.forms.stepN', { n: f.step })}` : ''}</span>
                      </button>
                      {canEdit && (
                        <>
                          <Button size="sm" variant="ghost" aria-label={t('hub.forms.moveUp')} disabled={i === 0} icon={<ArrowUp className="size-4" />} onClick={() => move(i, -1)} data-testid={`up-${f.key}`} />
                          <Button size="sm" variant="ghost" aria-label={t('hub.forms.moveDown')} disabled={i === fields.length - 1} icon={<ArrowDown className="size-4" />} onClick={() => move(i, 1)} data-testid={`down-${f.key}`} />
                          <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => set('fields', fields.filter((x) => x.key !== f.key))} data-testid={`remove-${f.key}`} />
                        </>
                      )}
                    </div>
                    {errors[f.key] && <p role="alert" className="px-3 pb-2 text-[0.875rem] text-[var(--color-status-lost)]" data-testid={`error-${f.key}`}>{errorText(errors[f.key])}</p>}
                    {expanded && (
                      <div className="grid gap-3 border-t border-[var(--color-border)] p-3 sm:grid-cols-2">
                        <Input label={t('hub.forms.fieldLabel')} value={f.label} onChange={(e) => setField(f.key, { label: e.target.value })} disabled={!canEdit} data-testid={`label-${f.key}`} />
                        <Select label={t('hub.forms.fieldType')} value={f.type} onChange={(e) => setField(f.key, { type: e.target.value as FormField['type'], hidden: e.target.value === 'hidden' ? true : f.hidden })} disabled={!canEdit} data-testid={`type-${f.key}`}>
                          {FIELD_TYPES.map((ty) => <option key={ty} value={ty}>{t(`hub.forms.type.${ty}`)}</option>)}
                        </Select>
                        <Select label={t('hub.forms.mapping')} value={f.mapping} onChange={(e) => setField(f.key, { mapping: e.target.value })} disabled={!canEdit} data-testid={`mapping-${f.key}`}>
                          <option value="none">{t('hub.forms.mapNone')}</option>
                          <optgroup label={t('hub.forms.mapLead')}>{(presets?.targets.lead ?? []).map((k) => <option key={k} value={`lead.${k}`}>{k}</option>)}</optgroup>
                          <optgroup label={t('hub.forms.mapTracking')}>{(presets?.targets.tracking ?? []).map((k) => <option key={k} value={`tracking.${k}`}>{k}</option>)}</optgroup>
                          {f.mapping.startsWith('custom.') && <option value={f.mapping}>{f.mapping}</option>}
                          <option value={`custom.${f.key}`}>{t('hub.forms.mapCustom', { key: f.key })}</option>
                        </Select>
                        <Input label={t('hub.forms.stepLabel')} type="number" min={1} max={5} value={String(f.step ?? 1)} onChange={(e) => setField(f.key, { step: Math.max(1, Number(e.target.value) || 1) })} disabled={!canEdit} />
                        <Input label={t('hub.forms.placeholder')} value={f.placeholder ?? ''} onChange={(e) => setField(f.key, { placeholder: e.target.value })} disabled={!canEdit} />
                        <Input label={t('hub.forms.default')} hint={t('hub.forms.defaultHint')} value={f.default ?? ''} onChange={(e) => setField(f.key, { default: e.target.value || null })} disabled={!canEdit} />
                        {HAS_OPTIONS.includes(f.type) && <Textarea containerClassName="sm:col-span-2" label={t('hub.forms.options')} hint={t('hub.forms.optionsHint')} rows={4} value={optionsToText(f.options)} onChange={(e) => setField(f.key, { options: textToOptions(e.target.value) })} disabled={!canEdit} data-testid={`options-${f.key}`} />}
                        {f.type === 'number' && (
                          <>
                            <Input label={t('hub.forms.min')} type="number" value={String(f.validation?.min ?? '')} onChange={(e) => setField(f.key, { validation: { ...f.validation, ...(e.target.value === '' ? {} : { min: Number(e.target.value) }) } })} disabled={!canEdit} />
                            <Input label={t('hub.forms.max')} type="number" value={String(f.validation?.max ?? '')} onChange={(e) => setField(f.key, { validation: { ...f.validation, ...(e.target.value === '' ? {} : { max: Number(e.target.value) }) } })} disabled={!canEdit} />
                          </>
                        )}
                        <div className="grid gap-2 sm:col-span-2 sm:grid-cols-[1fr_1fr]">
                          <Select label={t('hub.forms.showWhen')} value={f.visible_if?.field ?? ''} onChange={(e) => setField(f.key, { visible_if: e.target.value ? { field: e.target.value, equals: f.visible_if?.equals ?? '' } : null })} disabled={!canEdit} data-testid={`visible-${f.key}`}>
                            <option value="">{t('hub.forms.always')}</option>
                            {fields.filter((o) => o.key !== f.key).map((o) => <option key={o.key} value={o.key}>{o.label || o.key}</option>)}
                          </Select>
                          {f.visible_if?.field && <Input label={t('hub.forms.equals')} hint={t('hub.forms.equalsHint')} value={f.visible_if.equals ?? ''} onChange={(e) => setField(f.key, { visible_if: { field: f.visible_if!.field, equals: e.target.value } })} disabled={!canEdit} data-testid={`equals-${f.key}`} />}
                        </div>
                        <div className="flex flex-wrap gap-4 sm:col-span-2">
                          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={f.required} onChange={(e) => setField(f.key, { required: e.target.checked })} disabled={!canEdit || f.hidden} data-testid={`required-${f.key}`} />{t('hub.forms.required')}</label>
                          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={f.hidden} onChange={(e) => setField(f.key, { hidden: e.target.checked, required: e.target.checked ? false : f.required })} disabled={!canEdit} data-testid={`hidden-${f.key}`} />{t('hub.forms.hidden')}</label>
                        </div>
                      </div>
                    )}
                  </li>
                )
              })}
            </ul>
          </section>
        </div>
      )}
    </Drawer>
  )
}
