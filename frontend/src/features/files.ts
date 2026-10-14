import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

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
  'brochure', 'profile', 'price_list', 'investment_deck', 'financing_guide', 'other',
] as const

export type FileKind = (typeof FILE_KINDS)[number]

export interface ProjectFile {
  id: string
  name: string
  kind: FileKind
  language: string | null
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
  input: { file: File; kind: FileKind; name?: string; language?: string | null },
) {
  const body = new FormData()

  body.append('file', input.file)
  body.append('kind', input.kind)

  if (input.name) body.append('name', input.name)
  // Null is a real answer — a price list of numbers suits everybody — and an
  // empty string is not the way to say it.
  if (input.language) body.append('language', input.language)

  return apiPost<{ file: ProjectFile }>(`/projects/${projectId}/files`, body)
}

export function updateProjectFile(
  projectId: string,
  fileId: string,
  patch: Partial<Pick<ProjectFile, 'name' | 'kind' | 'language' | 'is_active' | 'sort'>>,
) {
  return apiPatch<{ file: ProjectFile }>(`/projects/${projectId}/files/${fileId}`, patch)
}

export function deleteProjectFile(projectId: string, fileId: string) {
  return apiDelete(`/projects/${projectId}/files/${fileId}`)
}

/** What the product would send this lead, and why that one. */
export function fetchFilesForLead(leadId: string, kind?: FileKind) {
  return apiGet<{ suggested: ProjectFile | null; reason: string | null; available: ProjectFile[] }>(
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
