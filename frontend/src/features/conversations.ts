import { apiDelete, apiGet, apiGetPaginated, apiPatch, apiPost } from '@/lib/api'
import type {
  CampaignQualityRow, Lead, LeadMessage, MessageTemplate, PaginationMeta,
  QualificationInput, QualificationOptions, SalesPerformance, ScoreBreakdown,
  WhatsappSettings,
} from '@/types/api'

/* ---------------------------------------------------------------- messages */

export function fetchLeadMessages(leadId: string, page = 1) {
  return apiGetPaginated<LeadMessage[]>(`/leads/${leadId}/messages`, { page, per_page: 30 }) as Promise<{
    data: LeadMessage[]
    meta: PaginationMeta | undefined
  }>
}

export function sendLeadMessage(leadId: string, payload: { body?: string; template_uuid?: string }) {
  return apiPost<{ message: LeadMessage }>(`/leads/${leadId}/messages`, payload)
}

export function previewTemplate(leadId: string, templateId: string) {
  return apiPost<{ body: string }>(`/leads/${leadId}/messages/preview`, { template_uuid: templateId })
}

export function setWhatsappOptOut(leadId: string, optedOut: boolean) {
  return apiPost<{ whatsapp_opt_out_at: string | null }>(`/leads/${leadId}/whatsapp-opt-out`, {
    opted_out: optedOut,
  })
}

/* --------------------------------------------------------------- templates */

export function fetchMessageTemplates(all = false) {
  return apiGet<{ templates: MessageTemplate[]; variables: string[] }>('/message-templates', { all })
}

export function createMessageTemplate(payload: { name: string; body: string }) {
  return apiPost<{ template: MessageTemplate }>('/message-templates', payload)
}

export function updateMessageTemplate(id: string, payload: Partial<MessageTemplate>) {
  return apiPatch<{ template: MessageTemplate }>(`/message-templates/${id}`, payload)
}

export function deleteMessageTemplate(id: string) {
  return apiDelete(`/message-templates/${id}`)
}

/* ----------------------------------------------------------- qualification */

export function fetchQualificationOptions() {
  return apiGet<QualificationOptions>('/qualification/options')
}

export function fetchScoreBreakdown(leadId: string) {
  return apiGet<ScoreBreakdown>(`/leads/${leadId}/qualification`)
}

export function saveQualification(leadId: string, payload: QualificationInput) {
  return apiPatch<{
    lead: Lead
    scoring: ScoreBreakdown
    changed: { score: boolean; temperature: boolean; qualification_status: boolean }
  }>(`/leads/${leadId}/qualification`, payload)
}

/* ------------------------------------------------------- whatsapp settings */

/** Just enough for the lead screen; readable by anyone who works leads. */
export function fetchWhatsappCapability() {
  return apiGet<{ can_send: boolean; mode: 'live' | 'mock' }>('/whatsapp/capability')
}

export function fetchWhatsappSettings() {
  return apiGet<WhatsappSettings>('/integrations/whatsapp')
}

export function saveWhatsappSettings(payload: Record<string, unknown>) {
  return apiPatch<WhatsappSettings>('/integrations/whatsapp', payload)
}

export function testWhatsappConnection() {
  return apiPost<WhatsappSettings & { ok: boolean; detail: string }>('/integrations/whatsapp/test', {})
}

export function rotateVerifyToken() {
  return apiPost<{ verify_token: string; notice: string }>('/integrations/whatsapp/rotate-verify-token', {})
}

export function disconnectWhatsapp() {
  return apiDelete<WhatsappSettings>('/integrations/whatsapp')
}

/* ------------------------------------------------------------------ reports */

export function fetchCampaignQuality(filters: Record<string, unknown> = {}) {
  return apiGet<{ rows: CampaignQualityRow[]; totals: CampaignQualityRow }>(
    '/reports/campaign-quality',
    filters,
  )
}

export function fetchSalesPerformance(filters: Record<string, unknown> = {}) {
  return apiGet<SalesPerformance>('/reports/performance', filters)
}
