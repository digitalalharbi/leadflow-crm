import { apiDelete, apiGet, apiPost, http } from '@/lib/api'

/**
 * The workspace's own telephony account.
 *
 * Write-only by design, like the WhatsApp card: the token goes in and never
 * comes back, so the screen can show that one is stored without ever holding it.
 */
export interface CallSettings {
  provider: 'twilio'
  /** Credentials present — not the same as working; see `status`. */
  configured: boolean
  status: 'connected' | 'not_connected' | 'configuration_required' | 'error'
  from_number: string | null
  account_sid_hint: string | null
  has_token: boolean
  last_error: string | null
  /** The URL to give the provider so call states come back to us. */
  status_callback: string | null
}

export function fetchCallSettings() {
  return apiGet<{ calls: CallSettings }>('/integrations/calls').then((r) => r.calls)
}

export async function saveCallSettings(payload: {
  account_sid?: string
  auth_token?: string
  from_number?: string
}) {
  const { data } = await http.put<{ data: { calls: CallSettings } }>('/integrations/calls', payload)

  return data.data.calls
}

export function testCallSettings() {
  return apiPost<{ calls: CallSettings; account: string | null }>('/integrations/calls/test')
}

export function disconnectCalls() {
  return apiDelete('/integrations/calls')
}
