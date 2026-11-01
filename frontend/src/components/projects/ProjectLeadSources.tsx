import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileSpreadsheet, Globe, Megaphone, MessageCircle, Phone, Plus, Upload, Webhook, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, CardHeader, EmptyState, ErrorState, Input, Modal, Select, Skeleton } from '@/components/ui'
import { createForm, uploadFormAsset, type PageTemplate } from '@/features/hub'
import { createLeadFormMapping, fetchLeadSources, fetchPageComparison, LEAD_FORM_PROVIDERS, MAPPING_TARGETS, setProjectLogo, type LeadSource, type SourceType } from '@/features/leadSources'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const ICON: Record<SourceType, LucideIcon> = { landing_page: Globe, platform_form: Megaphone, google_sheet: FileSpreadsheet, whatsapp: MessageCircle, call: Phone, api: Webhook, import: Upload }
const TONE = { connected: 'success', awaiting_setup: 'neutral', error: 'danger' } as const
const TEMPLATES: PageTemplate[] = ['real_estate_lead', 'project_showcase', 'minimal_lead']
const ADD: Array<{ key: string; icon: LucideIcon }> = [
  { key: 'landing_page', icon: Globe }, { key: 'platform_form', icon: Megaphone }, { key: 'google_sheet', icon: FileSpreadsheet },
  { key: 'whatsapp', icon: MessageCircle }, { key: 'call', icon: Phone }, { key: 'api', icon: Webhook }, { key: 'import', icon: Upload },
]

/**
 * Project → Lead Sources: every way a lead reaches this project, on one list, whatever it is. A landing page, a platform's form and a
 * spreadsheet look alike here because they end alike: in the project's one pipeline.
 */
export function ProjectLeadSources({ projectId, projectName }: { projectId: string; projectName: string }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const canEdit = can.has(user, 'tracking.edit')
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [kind, setKind] = useState<'page' | 'platform' | null>(null)
  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['lead-sources', projectId], queryFn: () => fetchLeadSources(projectId) })
  const { data: compare } = useQuery({ queryKey: ['lead-sources-compare', projectId], queryFn: () => fetchPageComparison(projectId) })

  const logo = useMutation({
    mutationFn: async (file: File) => setProjectLogo(projectId, (await uploadFormAsset(file)).url),
    onSuccess: () => { toast(t('hub.sources.logoSaved'), 'success'); void queryClient.invalidateQueries({ queryKey: ['project', projectId] }) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-64" />
  if (isError || !data) return <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>

  const open = (source: LeadSource) => {
    if (source.type === 'landing_page') return `/tracking/forms/${source.id}`
    if (source.type === 'google_sheet' || source.type === 'whatsapp' || source.type === 'call' || source.type === 'api' || source.type === 'platform_form') return '/integrations'

    return '/leads'
  }

  return (
    <div className="space-y-4" data-testid="lead-sources">
      <Card>
        <CardHeader
          title={t('hub.sources.title')}
          description={t('hub.sources.hint')}
          action={canEdit ? <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)} data-testid="add-source">{t('hub.sources.add')}</Button> : undefined}
        />
        <CardBody className="space-y-3">
          {canEdit && (
            <label className="block text-[0.875rem]">{t('hub.sources.logo')}
              <input type="file" accept="image/png,image/webp,image/svg+xml" className="mt-1 block text-[0.875rem]" data-testid="project-logo" onChange={(e) => { const f = e.target.files?.[0]; if (f) logo.mutate(f) }} />
            </label>
          )}
          {data.sources.length === 0
            ? <EmptyState icon={<Globe className="size-6" />} title={t('hub.sources.emptyTitle')} body={t('hub.sources.emptyBody')} />
            : (
              <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="source-cards">
                {data.sources.map((source) => {
                  const Icon = ICON[source.type]

                  return (
                    <li key={`${source.type}-${source.id}`} className="min-w-0 rounded-xl border border-[var(--color-line)] p-3" data-testid="source-card" data-type={source.type} data-status={source.status}>
                      <div className="flex items-start gap-2">
                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Icon className="size-4" /></span>
                        <div className="min-w-0 flex-1">
                          <Link to={open(source)} className="block truncate font-semibold hover:underline" data-testid="source-name">{source.type === 'landing_page' || source.type === 'platform_form' || source.type === 'google_sheet' ? source.name : t(`hub.sources.types.${source.type}`)}</Link>
                          <p className="t-caption">{t(`hub.sources.types.${source.type}`)}{source.provider ? ` · ${t(`hub.sources.providers.${source.provider}`, { defaultValue: source.provider })}` : ''}{source.path ? ` · ${source.path}` : ''}</p>
                        </div>
                        <Badge tone={TONE[source.status]}>{t(`hub.sources.status.${source.status}`)}</Badge>
                      </div>
                      <dl className="mt-3 grid grid-cols-4 gap-1 text-center">
                        {(['leads', 'qualified', 'appointments', 'sales'] as const).map((k) => (
                          <div key={k}><dd className="tabular text-[1.0625rem] font-bold">{source[k]}</dd><dt className="t-caption">{t(`hub.sources.metrics.${k}`)}</dt></div>
                        ))}
                      </dl>
                      <p className="t-caption mt-2">
                        {source.conversion_rate !== null ? `${t('hub.sources.conversion', { rate: source.conversion_rate })} · ` : ''}
                        {source.last_lead_at ? t('hub.sources.last', { when: formatLastSeen(source.last_lead_at, i18n.language) }) : t('hub.sources.noneYet')}
                        {source.clicks ? ` · ${t('hub.sources.clicks', { n: source.clicks })}` : ''}
                        {source.tracking_health !== 'not_applicable' ? ` · ${t(`hub.sources.health.${source.tracking_health}`)}` : ''}
                      </p>
                    </li>
                  )
                })}
              </ul>
            )}
        </CardBody>
      </Card>

      {(compare?.pages.length ?? 0) > 1 && (
        <Card data-testid="page-comparison">
          <CardHeader title={t('hub.sources.compare')} description={t('hub.sources.compareHint', { days: compare!.days })} />
          <CardBody>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[34rem] text-[0.9375rem]">
                <thead><tr className="text-start text-[0.8125rem] text-[var(--color-ink-muted)]">{['page', 'views', 'leads', 'rate', 'qualified', 'appointments', 'sales'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`hub.sources.columns.${k}`)}</th>)}</tr></thead>
                <tbody>
                  {compare!.pages.map((p) => (
                    <tr key={p.id} className="border-t border-[var(--color-line)]" data-testid="comparison-row">
                      <td className="p-2 font-medium"><Link to={`/tracking/forms/${p.id}`} className="hover:underline">{p.name}</Link></td>
                      <td className="tabular p-2">{p.views}</td><td className="tabular p-2">{p.leads}</td><td className="tabular p-2">{p.conversion_rate === null ? '—' : `${p.conversion_rate}%`}</td>
                      <td className="tabular p-2">{p.qualified}</td><td className="tabular p-2">{p.appointments}</td><td className="tabular p-2">{p.sales}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardBody>
        </Card>
      )}

      <Modal open={adding} onClose={() => setAdding(false)} title={t('hub.sources.add')} description={t('hub.sources.addHint')}>
        <ul className="grid gap-2 sm:grid-cols-2" data-testid="add-source-options">
          {ADD.map(({ key, icon: Icon }) => {
            const body = <><Icon className="size-5 shrink-0 text-[var(--brand)]" aria-hidden /><span className="font-medium">{t(`hub.sources.types.${key}`)}</span></>
            const className = 'pressable flex min-h-12 w-full items-center gap-2 rounded-xl border border-[var(--color-line)] p-3 text-start hover:bg-[var(--color-surface-muted)]'

            if (key === 'landing_page' || key === 'platform_form') {
              return <li key={key}><button type="button" className={className} data-testid={`add-${key}`} onClick={() => { setAdding(false); setKind(key === 'landing_page' ? 'page' : 'platform') }}>{body}</button></li>
            }

            return <li key={key}><Link to={key === 'import' ? '/leads' : '/integrations'} className={className} data-testid={`add-${key}`}>{body}</Link></li>
          })}
        </ul>
      </Modal>

      {kind === 'page' && <NewPageDialog projectId={projectId} projectName={projectName} onClose={() => setKind(null)} />}
      {kind === 'platform' && <NewPlatformFormDialog projectId={projectId} onClose={() => setKind(null)} />}
    </div>
  )
}

function NewPageDialog({ projectId, projectName, onClose }: { projectId: string; projectName: string; onClose: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [name, setName] = useState(projectName)
  const [template, setTemplate] = useState<PageTemplate>('real_estate_lead')

  const create = useMutation({
    mutationFn: () => createForm({ name, project_id: projectId, template: 'real_estate_interest', headline: projectName, page: { template } } as Parameters<typeof createForm>[0]),
    onSuccess: (r) => navigate(`/tracking/forms/${r.form.id}`),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal open onClose={onClose} title={t('hub.sources.newPage')} footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button loading={create.isPending} disabled={!name.trim()} onClick={() => create.mutate()} data-testid="new-page-create">{t('hub.sources.createPage')}</Button></>}>
      <div className="space-y-3">
        <Input label={t('hub.forms.name')} value={name} onChange={(e) => setName(e.target.value)} data-testid="new-page-name" />
        <Select label={t('hub.page.builder.template')} value={template} onChange={(e) => setTemplate(e.target.value as PageTemplate)} data-testid="new-page-template">
          {TEMPLATES.map((k) => <option key={k} value={k}>{t(`hub.page.templates.${k}`)}</option>)}
        </Select>
        <p className="t-caption">{t(`hub.page.templateHint.${template}`)}</p>
      </div>
    </Modal>
  )
}

function NewPlatformFormDialog({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<string>('meta')
  const [formRef, setFormRef] = useState('')
  const [formName, setFormName] = useState('')
  const [rows, setRows] = useState<Array<{ source: string; target: string }>>([{ source: '', target: 'full_name' }])

  const create = useMutation({
    mutationFn: () => createLeadFormMapping(projectId, { provider, form_ref: formRef.trim(), form_name: formName.trim() || null, field_mapping: Object.fromEntries(rows.filter((r) => r.source.trim()).map((r) => [r.source.trim(), r.target])) }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['lead-sources', projectId] }); toast(t('hub.sources.platformSaved'), 'success'); onClose() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal open onClose={onClose} title={t('hub.sources.types.platform_form')} description={t('hub.sources.platformHint')} footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button loading={create.isPending} disabled={!formRef.trim()} onClick={() => create.mutate()} data-testid="platform-activate">{t('hub.sources.activate')}</Button></>}>
      <div className="space-y-3">
        <Select label={t('hub.sources.platform')} value={provider} onChange={(e) => setProvider(e.target.value)} data-testid="platform-provider">
          {LEAD_FORM_PROVIDERS.map((p) => <option key={p} value={p}>{t(`hub.sources.providers.${p}`)}</option>)}
        </Select>
        <Input label={t('hub.sources.formId')} dir="ltr" value={formRef} onChange={(e) => setFormRef(e.target.value)} data-testid="platform-form-id" />
        <Input label={t('hub.forms.name')} value={formName} onChange={(e) => setFormName(e.target.value)} data-testid="platform-form-name" />
        <fieldset className="space-y-2">
          <legend className="t-label">{t('hub.sources.mapFields')}</legend>
          {rows.map((row, i) => (
            <div key={i} className="grid grid-cols-2 gap-2">
              <Input aria-label={t('hub.sources.platformField')} placeholder={t('hub.sources.platformField')} dir="ltr" value={row.source} onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, source: e.target.value } : r)))} data-testid={`map-source-${i}`} />
              <Select aria-label={t('hub.sources.mohtamField')} value={row.target} onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, target: e.target.value } : r)))} data-testid={`map-target-${i}`}>
                {MAPPING_TARGETS.map((k) => <option key={k} value={k}>{t(`hub.sources.targets.${k}`)}</option>)}
              </Select>
            </div>
          ))}
          <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => setRows([...rows, { source: '', target: 'city' }])}>{t('hub.sources.addField')}</Button>
        </fieldset>
      </div>
    </Modal>
  )
}
