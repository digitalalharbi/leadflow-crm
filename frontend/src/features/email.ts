import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import type { EmailSettings, LeadMessage } from '@/types/api'

/** Anyone who works a lead may ask whether email goes out, and by which door. */
export function fetchEmailCapability() {
  return apiGet<{ can_send: boolean; mode: EmailSettings['mode']; replies_tracked?: boolean }>('/email/capability')
}

export function sendLeadEmail(
  leadId: string,
  payload: { subject?: string; body?: string; template_uuid?: string },
) {
  return apiPost<{ message: LeadMessage }>(`/leads/${leadId}/emails`, payload)
}

export function setEmailOptOut(leadId: string, optedOut: boolean) {
  return apiPost<{ email_opt_out_at: string | null }>(`/leads/${leadId}/email-opt-out`, {
    opted_out: optedOut,
  })
}

export function fetchEmailSettings() {
  return apiGet<EmailSettings>('/integrations/email')
}

export function saveEmailSettings(payload: Record<string, unknown>) {
  return apiPatch<EmailSettings>('/integrations/email', payload)
}

export function testEmailSettings() {
  return apiPost<{ sent_to: string }>('/integrations/email/test')
}

export function disconnectEmail() {
  return apiDelete('/integrations/email')
}
