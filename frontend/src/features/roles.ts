import { apiGet } from '@/lib/api'
import type { UserRole } from '@/types/api'

export interface RoleInfo {
  role: UserRole | string
  /** A role the workspace built: the id to hand a user, and the built-in role it behaves like. */
  id?: string
  custom?: boolean
  base_role?: UserRole
  label: string
  description: string | null
  /** Sees only the projects it was given. */
  scoped: boolean
  /** From outside the company: a read-only window. */
  external: boolean
  grants: Record<string, string[]>
}

export interface RolesData {
  roles: RoleInfo[]
  resources: string[]
  actions: string[]
}

export function fetchRoles() {
  return apiGet<RolesData>('/roles')
}
