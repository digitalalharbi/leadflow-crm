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

/** A 48×48 PNG: a real picture, so the upload's check by content passes. */
export const LOGO_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAADAAAAAwCAYAAABXAvmHAAAAZElEQVR4nO3PoQ0AIADAMD7gUyTvwxGIhmRivhtzr/NzQwMa0IAGNKABDWhAAxrQgAY0oAENaEADGtCABjSgAQ1oQAMa0IAGNKABDWhAAxrQgAY0oAENaEADGtCABjSgAQ147QJgQoMAvbdU6wAAAABJRU5ErkJggg==',
  'base64',
)

export async function uploadLogo(session: ApiSession): Promise<string> {
  const response = await session.context.post('/api/v1/tracking/forms/assets', { multipart: { file: { name: 'logo.png', mimeType: 'image/png', buffer: LOGO_PNG } } })
  if (response.status() !== 201) throw new Error(`logo: ${response.status()} ${await response.text()}`)

  return (await response.json()).data.url as string
}

const col = (id: string, fields: string[]) => ({ id, fields })
const row = (id: string, ...columns: { id: string; fields: string[] }[]) => ({ id, columns })

/**
 * An advanced form made through the API the way the builder does: two steps; a row of two columns; a conditional field; a matrix; a repeater;
 * a header with a logo; and the lead's phone as a field with a country.
 */
export const ADVANCED_FIELDS = [
  { key: 'name', type: 'text', label: 'الاسم الكامل', required: true, mapping: 'lead.full_name' },
  { key: 'mobile', type: 'phone', label: 'رقم الجوال', required: true, mapping: 'lead.phone' },
  { key: 'city', type: 'select', label: 'المدينة', mapping: 'lead.city', options: [{ value: 'الرياض', label: 'الرياض' }, { value: 'جدة', label: 'جدة' }, { value: 'الدمام', label: 'الدمام' }] },
  { key: 'purpose', type: 'radio', label: 'الغرض من الشراء', mapping: 'lead.purchase_purpose', options: [{ value: 'residence', label: 'سكن' }, { value: 'investment', label: 'استثمار' }] },
  { key: 'yield', type: 'text', label: 'العائد المتوقع', mapping: 'custom.target_yield', show_when: { match: 'all', conditions: [{ field: 'purpose', op: 'equals', value: 'investment' }] }, require_when: { match: 'all', conditions: [{ field: 'purpose', op: 'equals', value: 'investment' }] } },
  { key: 'priorities', type: 'matrix', label: 'ما أهمية هذه العوامل؟', mapping: 'custom.priorities', matrix: {
    rows: [{ value: 'location', label: 'الموقع' }, { value: 'price', label: 'السعر' }, { value: 'area', label: 'المساحة' }],
    columns: [{ value: 'low', label: 'غير مهم' }, { value: 'mid', label: 'مهم' }, { value: 'high', label: 'مهم جدًا' }], multiple: false, require_all_rows: false, min_answers: 1,
  } },
  { key: 'guests', type: 'repeater', label: 'أضف الأشخاص المهتمين', mapping: 'custom.guests', repeater: {
    subfields: [{ key: 'name', type: 'text', label: 'الاسم', required: true }, { key: 'mobile', type: 'phone', label: 'الجوال' }, { key: 'relation', type: 'select', label: 'العلاقة', options: [{ value: 'wife', label: 'زوجة' }, { value: 'son', label: 'ابن' }] }],
    min_rows: 0, max_rows: 3, default_rows: 1, add_label: '+ إضافة شخص',
  } },
  { key: 'source', type: 'hidden', label: 'المصدر', hidden: true, mapping: 'tracking.source', default: '@source' },
]

export const ADVANCED_LAYOUT = {
  sections: [
    { id: 's1', title: 'بياناتك', step_title: 'بياناتك', rows: [row('r1', col('c1', ['name']), col('c2', ['mobile'])), row('r2', col('c1', ['city']))] },
    { id: 's2', title: 'اهتمامك', step_title: 'اهتمامك', rows: [row('r3', col('c1', ['purpose'])), row('r4', col('c1', ['yield'])), row('r5', col('c1', ['priorities'])), row('r6', col('c1', ['guests']))] },
  ],
}

export async function makeAdvancedLanding(session: ApiSession, opts: { name?: string; logo?: boolean } = {}): Promise<Made> {
  const projects = await apiGet<{ projects: { id: string; name: string }[] }>(session, '/api/v1/projects')
  const project = projects.projects[0]
  const logo = opts.logo === false ? null : await uploadLogo(session)

  const form = await apiPost(session, '/api/v1/tracking/forms', {
    name: opts.name ?? 'نموذج متقدم', project_id: project.id, headline: 'فرصتك في الياسمين', description: 'سجّل اهتمامك وسنتواصل معك', benefits: ['قرب من المدارس', 'تمويل ميسّر'], logo_url: logo,
    fields: ADVANCED_FIELDS, layout: ADVANCED_LAYOUT, consent_required: true,
    design: { header: { layout: 'project_card', logo_size: 'md', logo_align: 'center', location: 'شمال الرياض', price_from: 950000, trust_note: 'بياناتك محمية' }, style: { primary: 'blue', radius: 'lg', input_size: 'large', theme: 'brand' } },
    settings: { mode: 'multi', default_country: 'SA', thanks: { actions: [{ type: 'whatsapp', label: 'تواصل عبر واتساب', value: '+966551112222' }], redirect_url: null, redirect_delay: 5 }, gtm: { events: true } },
  })
  if (form.status() !== 201) throw new Error(`form: ${form.status()} ${await form.text()}`)
  const formId = (await form.json()).data.form.id as string

  const published = await apiPost(session, `/api/v1/tracking/forms/${formId}/publish`)
  if (!published.ok()) throw new Error(`publish: ${published.status()} ${await published.text()}`)

  const link = await apiPost(session, '/api/v1/tracking/links', {
    name: 'إعلان ميتا — نموذج متقدم', channel: 'meta', mode: 'landing', project_id: project.id, landing_form_id: formId, utm_source: 'facebook', utm_medium: 'paid_social', utm_campaign: 'summer_launch',
  })
  if (link.status() !== 201) throw new Error(`link: ${link.status()} ${await link.text()}`)
  const made = (await link.json()).data.link as { id: string; token: string }

  return { projectId: project.id, projectName: project.name, formId, linkId: made.id, token: made.token }
}
