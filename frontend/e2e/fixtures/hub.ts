import { type ApiSession, apiGet, apiPost } from './api'

/** A form that can reach the customer: a name, a mobile, an optional investment question that appears only when asked for. */
export const FORM_FIELDS = [
  { key: 'name', type: 'text', label: 'الاسم', required: true, hidden: false, mapping: 'lead.full_name' },
  { key: 'mobile', type: 'phone', label: 'رقم الجوال', required: true, hidden: false, mapping: 'lead.phone' },
  { key: 'purpose', type: 'radio', label: 'الغرض من الشراء', required: false, hidden: false, mapping: 'lead.purchase_purpose', options: [{ value: 'residence', label: 'سكن' }, { value: 'investment', label: 'استثمار' }] },
  { key: 'note', type: 'text', label: 'ما العائد المتوقع؟', required: false, hidden: false, mapping: 'none', visible_if: { field: 'purpose', equals: 'investment' } },
  { key: 'source', type: 'hidden', label: 'المصدر', required: false, hidden: true, mapping: 'tracking.source', default: '@source' },
]

export interface Made { projectId: string; projectName: string; formId: string; linkId: string; token: string }

/** A published form and a landing link to it, made through the API the way the screens do. */
export async function makeLanding(session: ApiSession, opts: { projectIndex?: number; channel?: string; name?: string; formName?: string } = {}): Promise<Made> {
  const projects = await apiGet<{ projects: { id: string; name: string }[] }>(session, '/api/v1/projects')
  const project = projects.projects[opts.projectIndex ?? 0]

  const form = await apiPost(session, '/api/v1/tracking/forms', {
    name: opts.formName ?? 'نموذج الاهتمام', project_id: project.id, headline: 'فرصتك في الياسمين', description: 'سجّل اهتمامك وسنتواصل معك',
    benefits: ['قرب من المدارس', 'تمويل ميسّر'], fields: FORM_FIELDS, consent_required: true,
  })
  if (form.status() !== 201) throw new Error(`form: ${form.status()} ${await form.text()}`)
  const formId = (await form.json()).data.form.id as string

  const published = await session.context.patch(`/api/v1/tracking/forms/${formId}`, { data: { status: 'published' } })
  if (!published.ok()) throw new Error(`publish: ${published.status()} ${await published.text()}`)

  const link = await apiPost(session, '/api/v1/tracking/links', {
    name: opts.name ?? 'إعلان ميتا — الياسمين', channel: opts.channel ?? 'meta', mode: 'landing', project_id: project.id, landing_form_id: formId,
    utm_source: 'facebook', utm_medium: 'paid_social', utm_campaign: 'summer_launch',
  })
  if (link.status() !== 201) throw new Error(`link: ${link.status()} ${await link.text()}`)
  const made = (await link.json()).data.link as { id: string; token: string }

  return { projectId: project.id, projectName: project.name, formId, linkId: made.id, token: made.token }
}
