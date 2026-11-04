import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiDelete, apiGet } from '@/lib/api'
import { formatRelative } from '@/lib/format'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Copy, Rocket, Redo2, Save, Undo2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, ErrorState, Skeleton, Tabs } from '@/components/ui'
import { Canvas, type Selection } from '@/components/forms/builder/Canvas'
import { DesignPanel } from '@/components/forms/builder/DesignPanel'
import { FieldSettings } from '@/components/forms/builder/FieldSettings'
import { FormAnalyticsPanel } from '@/components/forms/builder/FormAnalyticsPanel'
import { BasicsPanel, type Basics } from '@/components/forms/builder/BasicsPanel'
import { Library, type DragPayload } from '@/components/forms/builder/Library'
import { LogicEditor } from '@/components/forms/builder/LogicEditor'
import { PreviewPane, type Device } from '@/components/forms/builder/PreviewPane'
import { SignalsPanel } from '@/components/forms/builder/SignalsPanel'
import { ThanksPanel } from '@/components/forms/builder/ThanksPanel'
import { TrackingPanel } from '@/components/forms/builder/TrackingPanel'
import { LinkWizard } from '@/components/tracking/LinkWizard'
import { QrDialog } from '@/components/tracking/QrDialog'
import type { RenderForm } from '@/components/forms/FormRenderer'
import { duplicateForm, fetchForm, fetchFormPresets, publishForm, updateForm, type FormDesign, type FormField, type FormSettings, type LandingForm, type LandingPageConfig } from '@/features/hub'
import { PagePanel } from '@/components/forms/builder/PagePanel'
import { fetchProjects } from '@/features/leads'
import { fetchLinks, type TrackingLink } from '@/features/tracking'
import { ApiError } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import {
  addField, addRow, addSection, blankField, commit, deleteRow, deleteSection, duplicateField, duplicateRow, ensureLayout, freeKey, moveField, moveRow, moveSection, nudgeField, redo,
  removeField, setColumns, startHistory, undo, updateField, updateRow, updateSection, type Draft, type History, type Target,
} from '@/lib/formBuilder'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

interface Doc extends Draft {
  basics: Basics
  design: FormDesign
  settings: FormSettings
  logo_url: string | null
  image_url: string | null
  thank_title: string
  thank_message: string
  page: LandingPageConfig | null
  slug: string
}

const toDoc = (f: LandingForm): Doc => ({
  fields: f.fields,
  layout: ensureLayout(f.fields, f.layout),
  basics: { name: f.name, headline: f.headline, description: f.description, benefits: f.benefits, cta_label: f.cta_label, consent_required: f.consent_required, consent_text: f.consent_text, privacy_url: f.privacy_url, project_id: f.project?.id ?? '' },
  design: f.design,
  settings: f.settings,
  logo_url: f.logo_url,
  image_url: f.image_url,
  thank_title: f.thank_you_title ?? '',
  thank_message: f.thank_you_message ?? '',
  page: f.page ?? null,
  slug: f.slug ?? '',
})

const PANES = ['build', 'preview', 'analytics', 'submissions'] as const
type Pane = (typeof PANES)[number]
const FORM_TABS = ['basics', 'design', 'thanks', 'tracking', 'intent'] as const
type FormTab = (typeof FORM_TABS)[number] | 'page'

/**
 * `/tracking/forms/:id` — build the form: what to ask and how it is laid out, how it looks, what happens after, how it is measured.
 *
 * Three columns where there is room (what can be added · the form · the settings of what is selected) and one at a time where there is
 * not. The form on the canvas is a DRAFT: nothing a manager does here reaches a customer until Publish, and every Publish is a version.
 */
export function FormBuilderPage() {
  const { id = '' } = useParams()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const user = useAuth((s) => s.user)
  const canEdit = can.has(user, 'tracking.create')

  const { data: form, isLoading, isError, refetch } = useQuery({ queryKey: ['landing-form', id], queryFn: () => fetchForm(id) })
  const { data: presets } = useQuery({ queryKey: ['landing-form-presets'], queryFn: fetchFormPresets })
  const { data: projects } = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects })
  const { data: links } = useQuery({ queryKey: ['tracking-links', 30], queryFn: () => fetchLinks(30), select: (d) => d.links.filter((l) => l.landing_form?.id === id) })

  const [history, setHistory] = useState<History<Doc> | null>(null)
  const [savedKey, setSavedKey] = useState('')
  const [pane, setPane] = useState<Pane>('build')
  const [selection, setSelection] = useState<Selection>(null)
  const [formTab, setFormTab] = useState<FormTab>('basics')
  const [device, setDevice] = useState<Device>('mobile')
  const [problems, setProblems] = useState<{ key: string; code: string }[]>([])
  const [wizard, setWizard] = useState(false)
  const [qr, setQr] = useState<TrackingLink | null>(null)
  const [published, setPublished] = useState<number | null>(null)

  // The server's copy is the first state; after that this page owns it.
  const loaded = useRef(false)
  useEffect(() => {
    if (form && !loaded.current) {
      loaded.current = true
      const doc = toDoc(form)
      setHistory(startHistory(doc))
      setSavedKey(JSON.stringify(doc))
    }
  }, [form])

  const doc = history?.present
  const dirty = doc !== undefined && JSON.stringify(doc) !== savedKey
  const change = useCallback((fn: (d: Doc) => Doc) => setHistory((h) => (h ? commit(h, fn(h.present)) : h)), [])
  const draftOp = (fn: (d: Draft) => Draft) => change((d) => ({ ...d, ...fn({ fields: d.fields, layout: d.layout }) }))

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => { if (dirty) { e.preventDefault() } }
    window.addEventListener('beforeunload', warn)

    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  useEffect(() => {
    const keys = (e: KeyboardEvent) => {
      if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'z') return
      const target = e.target as HTMLElement
      if (/^(input|textarea|select)$/i.test(target.tagName)) return
      e.preventDefault()
      setHistory((h) => (h ? (e.shiftKey ? redo(h) : undo(h)) : h))
    }
    window.addEventListener('keydown', keys)

    return () => window.removeEventListener('keydown', keys)
  }, [])

  const payload = (d: Doc) => ({
    name: d.basics.name, project_id: d.basics.project_id || null, headline: d.basics.headline, description: d.basics.description,
    benefits: (d.basics.benefits ?? []).filter((b) => b.trim() !== ''), cta_label: d.basics.cta_label, consent_required: d.basics.consent_required, consent_text: d.basics.consent_text,
    privacy_url: d.basics.privacy_url || null, logo_url: d.logo_url, image_url: d.image_url, thank_you_title: d.thank_title || null, thank_you_message: d.thank_message || null,
    fields: d.fields.map((f, i) => ({ ...f, order: i + 1 })), layout: d.layout, design: d.design, settings: d.settings,
    ...(d.page ? { page: d.page, slug: d.slug || null } : {}),
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['landing-forms'] })
    void queryClient.invalidateQueries({ queryKey: ['landing-form', id] })
    void queryClient.invalidateQueries({ queryKey: ['form-analytics', id] })
  }

  const readProblems = (caught: unknown) => {
    if (caught instanceof ApiError && caught.errors) {
      const found = Object.values(caught.errors).flat().map((m) => {
        const [key, code] = String(m).split(': ')

        return code ? { key, code } : { key: 'form', code: String(m) }
      })
      setProblems(found)
      toast(t('hub.builder.fixProblems'), 'error')
    } else toast(translateError(caught), 'error')
  }

  const save = useMutation({
    mutationFn: async () => (await updateForm(id, payload(doc!))).form,
    onSuccess: () => { setProblems([]); setSavedKey(JSON.stringify(doc)); refresh(); toast(t('common.saved'), 'success') },
    onError: readProblems,
  })

  const setStatus = useMutation({
    mutationFn: (status: LandingForm['status']) => updateForm(id, { status } as Parameters<typeof updateForm>[1]),
    onSuccess: () => refresh(),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const duplicate = useMutation({
    mutationFn: () => duplicateForm(id),
    onSuccess: (r) => navigate(`/tracking/forms/${r.form.id}`),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const publish = useMutation({
    mutationFn: async () => {
      if (dirty) await updateForm(id, payload(doc!))
      return (await publishForm(id)).form
    },
    onSuccess: (f) => { setProblems([]); setSavedKey(JSON.stringify(doc)); refresh(); void queryClient.invalidateQueries({ queryKey: ['tracking-links'] }); setPublished(f.published_version?.version ?? 1) },
    onError: readProblems,
  })

  const copy = async (url: string) => {
    try { await navigator.clipboard.writeText(url); toast(t('tracking.copied'), 'success') } catch { toast(url, 'info') }
  }

  const renderForm: RenderForm | null = useMemo(() => doc ? {
    headline: doc.basics.headline, description: doc.basics.description, benefits: doc.basics.benefits ?? [], logo_url: doc.logo_url, image_url: doc.image_url,
    project: doc.basics.project_id ? { name: projects?.find((p) => p.id === doc.basics.project_id)?.name ?? '' } : null, cta_label: doc.basics.cta_label,
    fields: doc.fields, layout: doc.layout, design: doc.design, settings: doc.settings, consent: { required: doc.basics.consent_required, text: doc.basics.consent_text, privacy_url: doc.basics.privacy_url },
  } : null, [doc, projects])

  if (isLoading || !doc || !history) return <Skeleton className="h-96" />
  if (isError || !form) return <ErrorState onRetry={() => void refetch()} />

  const selectedField = selection?.kind === 'field' ? doc.fields.find((f) => f.key === selection.id) : undefined
  const selectedRow = selection?.kind === 'row' ? doc.layout.sections.flatMap((s) => s.rows).find((r) => r.id === selection.id) : undefined
  const selectedSection = selection?.kind === 'section' ? doc.layout.sections.find((s) => s.id === selection.id) : undefined
  const multi = doc.settings.mode === 'multi'

  const addTyped = (type: FormField['type'], target?: Target) => {
    const key = freeKey(doc.fields, type)
    const field = blankField(type, key, t(`hub.forms.type.${type}`))
    draftOp((d) => addField(d, field, target))
    setSelection({ kind: 'field', id: key })
  }

  const addPreset = (presetKey: string, target?: Target) => {
    const preset = presets?.presets.find((p) => p.key === presetKey)
    if (!preset || doc.fields.some((f) => f.key === presetKey)) return
    draftOp((d) => addField(d, { ...preset, mapping: d.fields.some((f) => f.mapping === preset.mapping) ? 'none' : preset.mapping }, target))
    setSelection({ kind: 'field', id: presetKey })
  }

  const drop = (p: DragPayload, target: Target) => {
    if (!canEdit) return
    if (p.kind === 'type') addTyped(p.type, target)
    else if (p.kind === 'preset') addPreset(p.key, target)
    else draftOp((d) => moveField(d, p.key, target))
  }

  const changeField = (key: string, patch: Partial<FormField>) => {
    draftOp((d) => {
      const current = d.fields.find((f) => f.key === key)
      if (!current) return d
      let next = { ...patch }

      if (patch.type && patch.type !== current.type) {
        const fresh = blankField(patch.type, key, current.label)
        next = { ...next, options: fresh.options ?? current.options, matrix: fresh.matrix ?? null, repeater: fresh.repeater ?? null, hidden: patch.type === 'hidden' ? true : patch.hidden ?? current.hidden }
        if (['heading', 'paragraph', 'divider'].includes(patch.type)) next = { ...next, mapping: 'none', required: false }
      }

      return updateField(d, key, next)
    })
  }

  const toggleHidden = (h: { key: string; mapping: string; token: string }, on: boolean) => {
    if (on) draftOp((d) => (d.fields.some((f) => f.mapping === h.mapping) ? d : addField(d, { key: freeKey(d.fields, h.key), type: 'hidden', label: h.key, required: false, hidden: true, mapping: h.mapping, default: h.token })))
    else draftOp((d) => d.fields.filter((f) => f.mapping === h.mapping && f.hidden).reduce((acc, f) => removeField(acc, f.key), d))
  }

  const settingsPane = (
    <div className="space-y-4" data-testid="settings-pane">
      {problems.length > 0 && (
        <ul className="space-y-1 rounded-xl border border-[var(--color-status-lost)] bg-[color-mix(in_srgb,var(--color-danger)_8%,transparent)] p-3" role="alert" data-testid="builder-problems">
          {problems.map((p, i) => (
            <li key={i} className="text-[0.875rem]">
              {doc.fields.some((f) => f.key === p.key)
                ? <button type="button" className="text-start underline" onClick={() => { setPane('build'); setSelection({ kind: 'field', id: p.key }) }}><strong>{doc.fields.find((f) => f.key === p.key)?.label || p.key}</strong> — {t(`hub.forms.error.${p.code}`, { defaultValue: p.code })}</button>
                : <span>{p.key !== 'form' && p.key !== '_form' && p.key !== '_layout' ? `${p.key} — ` : ''}{t(`hub.forms.error.${p.code}`, { defaultValue: p.code })}</span>}
            </li>
          ))}
        </ul>
      )}

      {selectedField ? (
        <>
          <div className="flex items-center justify-between"><h2 className="font-extrabold">{t('hub.builder.settingsOf', { name: selectedField.label || selectedField.key })}</h2><Button size="sm" variant="ghost" onClick={() => setSelection(null)} data-testid="close-settings">{t('hub.builder.back')}</Button></div>
          <FieldSettings field={selectedField} fields={doc.fields} presets={presets} onChange={(patch) => changeField(selectedField.key, patch)} />
        </>
      ) : selectedRow ? (
        <>
          <div className="flex items-center justify-between"><h2 className="font-extrabold">{t('hub.builder.rowSettings')}</h2><Button size="sm" variant="ghost" onClick={() => setSelection(null)}>{t('hub.builder.back')}</Button></div>
          <LogicEditor title={t('hub.builder.logic.showWhen')} group={selectedRow.show_when} fields={doc.fields} onChange={(g) => draftOp((d) => updateRow(d, selectedRow.id, { show_when: g }))} testId="row-logic-show" />
          <LogicEditor title={t('hub.builder.logic.hideWhen')} group={selectedRow.hide_when} fields={doc.fields} onChange={(g) => draftOp((d) => updateRow(d, selectedRow.id, { hide_when: g }))} testId="row-logic-hide" />
        </>
      ) : selectedSection ? (
        <>
          <div className="flex items-center justify-between"><h2 className="font-extrabold">{t('hub.builder.sectionSettings')}</h2><Button size="sm" variant="ghost" onClick={() => setSelection(null)}>{t('hub.builder.back')}</Button></div>
          <label className="block text-[0.9375rem] font-semibold">{t('hub.builder.sectionTitle')}
            <input className="field mt-1.5" value={selectedSection.title ?? ''} onChange={(e) => draftOp((d) => updateSection(d, selectedSection.id, { title: e.target.value || null }))} data-testid="section-title" /></label>
          {multi && <label className="block text-[0.9375rem] font-semibold">{t('hub.builder.stepTitle')}
            <input className="field mt-1.5" value={selectedSection.step_title ?? ''} onChange={(e) => draftOp((d) => updateSection(d, selectedSection.id, { step_title: e.target.value || null }))} data-testid="section-step-title" /></label>}
          <label className="block text-[0.9375rem] font-semibold">{t('hub.forms.description')}
            <input className="field mt-1.5" value={selectedSection.description ?? ''} onChange={(e) => draftOp((d) => updateSection(d, selectedSection.id, { description: e.target.value || null }))} /></label>
          <LogicEditor title={t('hub.builder.logic.showWhen')} group={selectedSection.show_when} fields={doc.fields} onChange={(g) => draftOp((d) => updateSection(d, selectedSection.id, { show_when: g }))} testId="section-logic-show" />
          <LogicEditor title={t('hub.builder.logic.hideWhen')} group={selectedSection.hide_when} fields={doc.fields} onChange={(g) => draftOp((d) => updateSection(d, selectedSection.id, { hide_when: g }))} testId="section-logic-hide" />
        </>
      ) : (
        <>
          <Tabs testId="form-settings-tabs" value={formTab} onChange={setFormTab} items={(doc.page ? (['page', ...FORM_TABS] as FormTab[]) : [...FORM_TABS]).map((k) => ({ key: k, label: t(`hub.builder.tabs.${k}`) }))} />
          {formTab === 'page' && doc.page && <PagePanel page={doc.page} slug={doc.slug} formId={id} published={form?.status === 'published'} onPage={(page) => change((d) => ({ ...d, page }))} onSlug={(slug) => change((d) => ({ ...d, slug }))} onQr={(l) => setQr(l as unknown as TrackingLink)} />}
          {formTab === 'basics' && <BasicsPanel basics={doc.basics} projects={projects ?? []} onChange={(p) => change((d) => ({ ...d, basics: { ...d.basics, ...p } }))} />}
          {formTab === 'design' && <DesignPanel design={doc.design} logoUrl={doc.logo_url} imageUrl={doc.image_url} onDesign={(design) => change((d) => ({ ...d, design }))} onLogo={(logo_url) => change((d) => ({ ...d, logo_url }))} onImage={(image_url) => change((d) => ({ ...d, image_url }))} />}
          {formTab === 'thanks' && <ThanksPanel title={doc.thank_title} message={doc.thank_message} settings={doc.settings} onTitle={(v) => change((d) => ({ ...d, thank_title: v }))} onMessage={(v) => change((d) => ({ ...d, thank_message: v }))} onSettings={(settings) => change((d) => ({ ...d, settings }))} />}
          {formTab === 'tracking' && <TrackingPanel fields={doc.fields} settings={doc.settings} links={links ?? []} onSettings={(settings) => change((d) => ({ ...d, settings }))} onToggleHidden={toggleHidden} onNewLink={() => setWizard(true)} onCopy={(u) => void copy(u)} />}
          {formTab === 'intent' && <SignalsPanel fields={doc.fields} settings={doc.settings} onSettings={(settings) => change((d) => ({ ...d, settings }))} />}
        </>
      )}
    </div>
  )

  return (
    <div data-testid="form-builder">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Link to="/tracking" aria-label={t('hub.builder.back')} className="pressable grid size-9 place-items-center rounded-full bg-[var(--color-surface-muted)]" data-testid="builder-back"><ArrowRight className="size-4 rtl:rotate-0 ltr:rotate-180" aria-hidden /></Link>
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-[1.25rem] font-extrabold" data-testid="page-title">{doc.basics.name || t('hub.forms.new')}</h1>
          <p className="t-caption text-[var(--color-ink-subtle)]" data-testid="builder-status">
            {form.published_version ? t('hub.builder.liveVersion', { n: form.published_version.version }) : t('hub.forms.status.draft')}
            {dirty ? ` · ${t('hub.builder.unsaved')}` : form.has_unpublished_changes ? ` · ${t('hub.builder.unpublished')}` : ''}
          </p>
        </div>
        <Button size="sm" variant="ghost" aria-label={t('hub.builder.undo')} disabled={history.past.length === 0} icon={<Undo2 className="size-4" />} onClick={() => setHistory((h) => (h ? undo(h) : h))} data-testid="undo" />
        <Button size="sm" variant="ghost" aria-label={t('hub.builder.redo')} disabled={history.future.length === 0} icon={<Redo2 className="size-4" />} onClick={() => setHistory((h) => (h ? redo(h) : h))} data-testid="redo" />
        {canEdit && <Button size="sm" variant="secondary" icon={<Save className="size-4" />} loading={save.isPending} disabled={!dirty} onClick={() => save.mutate()} data-testid="save-form">{t('common.save')}</Button>}
        {canEdit && doc.page && form.status === 'published' && <Button size="sm" variant="outline" loading={setStatus.isPending} onClick={() => setStatus.mutate('paused')} data-testid="page-pause">{t('hub.page.builder.pause')}</Button>}
        {canEdit && doc.page && form.status === 'paused' && <Button size="sm" variant="outline" loading={setStatus.isPending} onClick={() => setStatus.mutate('published')} data-testid="page-resume">{t('hub.page.builder.resume')}</Button>}
        {canEdit && doc.page && form.status !== 'archived' && form.status !== 'draft' && <Button size="sm" variant="ghost" loading={setStatus.isPending} onClick={() => { if (window.confirm(t('hub.page.builder.archiveConfirm'))) setStatus.mutate('archived') }} data-testid="page-archive">{t('hub.page.builder.archive')}</Button>}
        {canEdit && doc.page && form.status === 'archived' && <Button size="sm" variant="outline" loading={setStatus.isPending} onClick={() => setStatus.mutate('paused')} data-testid="page-restore">{t('hub.page.builder.restore')}</Button>}
        {canEdit && doc.page && form.status !== 'published' && <Button size="sm" variant="ghost" onClick={() => { if (window.confirm(t('hub.page.builder.deleteConfirm'))) void apiDelete(`/tracking/forms/${id}`).then(() => navigate('/tracking')) }} data-testid="page-delete">{t('common.delete')}</Button>}
        {canEdit && doc.page && <Button size="sm" variant="ghost" loading={duplicate.isPending} onClick={() => duplicate.mutate()} data-testid="page-duplicate">{t('hub.page.builder.duplicate')}</Button>}
        {doc.page && form.public_path && form.status === 'published' && <a href={form.public_path} target="_blank" rel="noopener noreferrer" className="t-small underline" dir="ltr" data-testid="page-public-link">{form.public_path}</a>}
        {canEdit && <Button size="sm" icon={<Rocket className="size-4" />} loading={publish.isPending} disabled={!dirty && !form.has_unpublished_changes && form.published_version !== null} onClick={() => publish.mutate()} data-testid="publish-form">{t('hub.builder.publish')}</Button>}
      </div>

      <Tabs testId="builder-panes" value={pane} onChange={setPane} items={PANES.map((k) => ({ key: k, label: t(`hub.builder.panes.${k}`) }))} className="mb-3" />

      {pane === 'build' && (
        <div className="grid gap-3 lg:grid-cols-[13rem_minmax(0,1fr)] xl:grid-cols-[14rem_minmax(0,1fr)_22rem]">
          <aside className="order-2 lg:order-none lg:max-h-[calc(100dvh-12rem)] lg:overflow-y-auto" aria-label={t('hub.builder.library.title')}>
            {canEdit && <Library presets={presets?.presets ?? []} used={doc.fields.map((f) => f.key)} onAddType={(ty) => addTyped(ty)} onAddPreset={(k) => addPreset(k)} />}
          </aside>
          <section className="order-1 min-w-0 lg:order-none" aria-label={t('hub.builder.canvas.title')}>
            <Canvas draft={doc} selection={selection} onSelect={setSelection} multi={multi} onDrop={drop}
              onFieldOp={(op, key) => draftOp((d) => (op === 'delete' ? removeField(d, key) : op === 'duplicate' ? duplicateField(d, key) : nudgeField(d, key, op === 'up' ? -1 : 1)))}
              onRowOp={(op, rowId, sectionId) => draftOp((d) => {
                if (op === 'delete') return deleteRow(d, rowId)
                if (op === 'duplicate') return duplicateRow(d, rowId)
                if (op === 'up' || op === 'down') return moveRow(d, rowId, op === 'up' ? -1 : 1)
                if (typeof op === 'string' && op.startsWith('cols-')) return setColumns(d, rowId, Number(op.slice(5)))
                return addRow(d, sectionId, d.layout.sections.find((s) => s.id === sectionId)!.rows.findIndex((r) => r.id === rowId) + 1)
              })}
              onSectionOp={(op, sectionId) => draftOp((d) => (op === 'delete' ? deleteSection(d, sectionId) : op === 'add-row' ? addRow(d, sectionId, 9999) : moveSection(d, sectionId, op === 'up' ? -1 : 1)))} />
            {canEdit && <Button className="mt-3" variant="secondary" onClick={() => draftOp(addSection)} data-testid="add-section">{multi ? t('hub.builder.canvas.addStep') : t('hub.builder.canvas.addSection')}</Button>}
          </section>
          <aside className="order-3 min-w-0 rounded-2xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] lg:col-span-2 xl:col-span-1 xl:max-h-[calc(100dvh-12rem)] xl:overflow-y-auto" aria-label={t('hub.builder.settings')}>{settingsPane}</aside>
        </div>
      )}

      {pane === 'preview' && renderForm && <PreviewPane page={doc.page ? { page: doc.page, design: doc.design, headline: doc.basics.headline, description: doc.basics.description, benefits: doc.basics.benefits ?? [], logo: doc.logo_url, image: doc.image_url, project: form?.project?.name ?? null, cta_label: doc.basics.cta_label } : undefined} form={renderForm} thanks={{ title: doc.thank_title || null, message: doc.thank_message || null }} device={device} onDevice={setDevice} />}
      {pane === 'analytics' && <FormAnalyticsPanel formId={id} />}
      {pane === 'submissions' && <SubmissionsPane formId={id} />}

      <LinkWizard open={wizard} preset={{ project_id: doc.basics.project_id || undefined, landing_form_id: id }} onClose={() => setWizard(false)} onAnalytics={(l) => { setWizard(false); navigate(`/tracking?link=${l.id}`) }} onQr={setQr} />
      <QrDialog link={qr} onClose={() => setQr(null)} />

      {published !== null && (
        <div role="dialog" aria-modal="true" aria-label={t('hub.builder.published.title')} className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" data-testid="published-dialog">
          <div className="w-full max-w-md space-y-3 rounded-2xl bg-[var(--color-surface)] p-5 shadow-xl">
            <h2 className="text-[1.25rem] font-extrabold">{t('hub.builder.published.title', { n: published })}</h2>
            <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.builder.published.body')}</p>
            {(links ?? []).length === 0 ? <p className="text-[0.9375rem]">{t('hub.builder.published.noLink')}</p> : (
              <ul className="space-y-1.5">
                {(links ?? []).map((l) => (
                  <li key={l.id} className="flex items-center justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5">
                    <span className="min-w-0 truncate text-[0.9375rem] font-semibold">{l.name}</span>
                    <Button size="sm" icon={<Copy className="size-4" />} onClick={() => void copy(l.url)} data-testid={`published-copy-${l.token}`}>{t('tracking.copy')}</Button>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-between gap-2 pt-1">
              <Button variant="secondary" onClick={() => { setPublished(null); setWizard(true) }} data-testid="published-new-link">{t('hub.builder.tracking.newLink')}</Button>
              <Button onClick={() => setPublished(null)} data-testid="published-close">{t('hub.wizard.done')}</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/** The people this page brought, as far as this person may see them. */
function SubmissionsPane({ formId }: { formId: string }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({
    queryKey: ['form-submissions', formId],
    queryFn: () => apiGet<{ submissions: Array<{ id: string; name: string; status: string; source: string | null; campaign: string | null; created_at: string }> }>(`/tracking/forms/${formId}/submissions`),
  })

  if (isLoading) return <p className="t-caption">{t('common.loading')}</p>

  const rows = data?.submissions ?? []

  if (rows.length === 0) return <p className="t-caption py-8 text-center" data-testid="submissions-empty">{t('hub.page.builder.noSubmissions')}</p>

  return (
    <ul className="divide-y divide-[var(--color-line)] rounded-2xl bg-[var(--color-surface)] shadow-[var(--shadow-card)]" data-testid="submissions">
      {rows.map((row) => (
        <li key={row.id}>
          <Link to={`/leads/${row.id}`} className="flex min-h-14 flex-wrap items-center justify-between gap-2 px-4 py-2">
            <span className="min-w-0 truncate font-medium">{row.name}</span>
            <span className="t-caption">{[row.source, row.campaign, formatRelative(row.created_at, i18n.language)].filter(Boolean).join(' · ')}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}
