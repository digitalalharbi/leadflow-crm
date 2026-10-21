import { apiDelete, apiGet, apiPatch, apiPost, apiPut } from '@/lib/api'
import type { UserRole } from '@/types/api'

export type ElementState = 'show' | 'hide'

export interface AccessRoleRow {
  key: string
  /** The id to hand a user (`access_role_id`); null for a built-in role. */
  id: string | null
  name: string
  description: string | null
  system: boolean
  base_role: UserRole
  scoped: boolean
  external: boolean
  /** Governed end to end by the table (false: a long-standing role, which can be held back but not widened). */
  enforced: boolean
  locked: boolean
  base_grants: Record<string, string[]>
  grants: Record<string, string[]>
  elements: { key: string; state: ElementState }[]
  users_count: number
}

export interface AccessElement {
  key: string
  kind: string
  needs: string[]
}

export interface AccessRule {
  permission: string
  effect: 'allow' | 'deny'
}

export interface AccessState {
  roles: AccessRoleRow[]
  resources: string[]
  actions: string[]
  view_only: string[]
  kinds: string[]
  elements: AccessElement[]
  workspace: AccessRule[]
  projects: { id: string; name: string; rules: (AccessRule & { role_key: string | null })[] }[]
  users: { id: string; name: string; rules: AccessRule[] }[]
}

export interface RoleInput {
  name?: string
  description?: string | null
  base_role?: UserRole
  project_scoped?: boolean
  external?: boolean
  grants?: Record<string, string[]>
  elements?: { key: string; state: ElementState }[]
}

export const fetchAccess = () => apiGet<AccessState>('/access')
export const createRole = (input: RoleInput) => apiPost<{ role: { id: string; key: string } }>('/access/roles', input)
export const updateRole = (key: string, input: RoleInput) => apiPatch<{ key: string }>(`/access/roles/${key}`, input)
export const deleteRole = (id: string) => apiDelete(`/access/roles/${id}`)

export interface RulesInput {
  layer: 'workspace' | 'project' | 'user'
  project_id?: string
  user_id?: string
  role_key?: string | null
  rules: AccessRule[]
}

export const putRules = (input: RulesInput) => apiPut<{ count: number }>('/access/rules', input)

export const fetchEffective = (userId: string, projectId?: string) =>
  apiGet<{ role: string; permissions: string[]; hidden_elements: string[] }>(`/access/users/${userId}${projectId ? `?project_id=${projectId}` : ''}`)
