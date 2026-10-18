import { apiDelete, apiGet, apiPatch, apiPost, http } from '@/lib/api'
import type { ApiEnvelope } from '@/types/api'

/**
 * The material a project sells with, and handing one piece of it to one lead.
 *
 * The whole of this existed on the server and none of it was reachable: six
 * endpoints, a chooser that reads a lead's own answers, a per-lead link whose
 * opens feed the engagement score — and no screen called any of it. A workspace
 * could not upload a brochure, so the chooser had nothing to choose and the
 * automation that sends one had nothing to send.
 */

export const FILE_KINDS = [
  'brochure', 'profile', 'price_list', 'investment_deck', 'financing_guide', 'offer', 'contract', 'other',
] as const

/** Who a file speaks to. Null is anybody. */
export const AUDIENCES = ['investor', 'end_user', 'financing'] as const
export type Audience = (typeof AUDIENCES)[number]

/** The stages a file can be limited to. */
export const FILE_STAGES = ['new', 'contacted', 'replied', 'qualified', 'appointment', 'negotiation', 'won'] as const

export type FileKind = (typeof FILE_KINDS)[number]

export interface ProjectFile {
  id: string
  name: string
  kind: FileKind
  language: string | null
  /** The campaign this was made for; null is every campaign. */
  campaign: string | null
  /** The stages it suits; empty is every stage. */
  stages: string[]
  /** Who it speaks to; null is anybody. */
  audience: Audience | null
  mime: string
  size_bytes: number
  is_active: boolean
  sort: number
}

export interface FileShare {
  id: string
  channel: string
  opens: number
  first_opened_at: string | null
  last_opened_at: string | null
  file: ProjectFile | null
}

export function fetchProjectFiles(projectId: string) {
  return apiGet<{ files: ProjectFile[]; kinds: FileKind[] }>(`/projects/${projectId}/files`)
}

/**
 * Multipart, so the browser sets its own boundary.
 *
 * Deliberately not routed through `apiPost`'s JSON body: axios only recognises
 * a FormData instance, and a plain object here uploads an empty file with a
 * perfectly successful-looking response.
 */
export function uploadProjectFile(
  projectId: string,
  input: {
    file: File
    kind: FileKind
    name?: string
    language?: string | null
    campaign?: string | null
    stages?: string[]
    audience?: Audience | null
  },
) {
  const body = new FormData()

  body.append('file', input.file)
  body.append('kind', input.kind)

  if (input.name) body.append('name', input.name)
  // Null is a real answer — a price list of numbers suits everybody — and an
  // empty string is not the way to say it.
  if (input.language) body.append('language', input.language)
  if (input.campaign) body.append('campaign', input.campaign)
  if (input.audience) body.append('audience', input.audience)
  for (const stage of input.stages ?? []) body.append('stages[]', stage)

  /*
   * The content type is named, and that is the fix for a bug that made every
   * upload fail.
   *
   * The client's default header is `application/json`. With that set, axios
   * does not send a FormData as multipart — it flattens it into JSON, and a File
   * becomes `{}`. The server answered "the file field is required" to a request
   * that had a file in it, and nothing in the product could upload one: the
   * chooser, the per-lead links and the rules that send a brochure all had
   * nothing to work with. Saying multipart here lets the browser add the
   * boundary itself.
   */
  return http
    .post<ApiEnvelope<{ file: ProjectFile }>>(`/projects/${projectId}/files`, body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    .then((response) => response.data.data)
}

export function updateProjectFile(
  projectId: string,
  fileId: string,
  patch: Partial<Pick<ProjectFile, 'name' | 'kind' | 'language' | 'is_active' | 'sort' | 'campaign' | 'stages' | 'audience'>>,
) {
  return apiPatch<{ file: ProjectFile }>(`/projects/${projectId}/files/${fileId}`, patch)
}

export function deleteProjectFile(projectId: string, fileId: string) {
  return apiDelete(`/projects/${projectId}/files/${fileId}`)
}

/** What the product would send this lead, and why that one. */
export function fetchFilesForLead(leadId: string, kind?: FileKind) {
  return apiGet<{ suggested: ProjectFile | null; reason: string | null; reasons: string[]; available: ProjectFile[] }>(
    `/leads/${leadId}/files`,
    kind ? { kind } : undefined,
  )
}

export function shareFileWithLead(leadId: string, fileId: string, channel: 'whatsapp' | 'email' | 'manual' = 'manual') {
  return apiPost<{ share: { id: string; url: string; opens: number; file: ProjectFile } }>(
    `/leads/${leadId}/files/share`,
    { file: fileId, channel },
  )
}

/** What this lead has been sent, and what they opened. */
export function fetchLeadShares(leadId: string) {
  return apiGet<{ shares: FileShare[] }>(`/leads/${leadId}/shares`)
}

/**
 * Chooses the file for this lead and sends it, by the channel they will read.
 * The same path an automation rule takes.
 */
export function sendFileToLead(leadId: string, channel: 'whatsapp' | 'email', fileId?: string) {
  return apiPost<{ file: ProjectFile; share: { id: string; url: string }; reasons: string[]; status: string | null }>(
    `/leads/${leadId}/files/send`,
    { channel, file: fileId },
  )
}
