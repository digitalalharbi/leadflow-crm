import type { User } from '@/types/api'

/**
 * What a person may do — one list, the same one the API enforces.
 *
 * The server sends `permissions` with the session (`/auth/me`), so a role added there is
 * understood here without a release. The fallback below is only for a session object that has not
 * got the list yet (a unit test, a half-restored page): it is what the three long-standing roles
 * have always been allowed, and nothing for any other role.
 */
export const ACTIONS = ['view', 'create', 'edit', 'execute', 'approve', 'export', 'share', 'disable'] as const
export type PermissionAction = (typeof ACTIONS)[number]

export const RESOURCES = [
  'dashboard', 'leads', 'visits', 'reservations', 'properties', 'projects', 'companies', 'campaigns', 'tracking',
  'analytics', 'reports_share', 'automation', 'integrations', 'users', 'settings', 'contacts', 'spend', 'revenue',
] as const
export type PermissionResource = (typeof RESOURCES)[number]

const ALL = RESOURCES.flatMap((r) => ACTIONS.map((a) => `${r}.${a}`))

const MANAGER = [
  'dashboard.view', 'leads.view', 'leads.create', 'leads.edit', 'leads.execute', 'leads.export', 'leads.disable',
  'visits.view', 'visits.create', 'visits.edit', 'visits.approve', 'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.approve',
  'properties.view', 'properties.create', 'properties.edit', 'properties.disable', 'projects.view', 'projects.create', 'projects.edit', 'projects.disable',
  'companies.view', 'companies.create', 'companies.edit', 'companies.disable',
  'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.execute', 'campaigns.approve', 'campaigns.export',
  'tracking.view', 'tracking.create', 'tracking.edit', 'tracking.disable', 'analytics.view', 'analytics.export',
  'reports_share.view', 'reports_share.create', 'reports_share.edit', 'reports_share.share', 'reports_share.disable',
  'automation.view', 'automation.create', 'automation.edit', 'automation.disable', 'users.view', 'settings.view',
  'contacts.view', 'spend.view', 'revenue.view',
]

const SALES = [
  'dashboard.view', 'leads.view', 'leads.create', 'leads.edit', 'leads.execute', 'visits.view', 'visits.create', 'visits.edit',
  'reservations.view', 'reservations.create', 'reservations.edit', 'properties.view', 'projects.view', 'settings.view', 'contacts.view', 'revenue.view',
]

const LEGACY: Record<string, string[]> = { admin: ALL, manager: MANAGER, sales: SALES }

export function permissionsOf(user: User | null | undefined): string[] {
  if (!user) return []

  return user.permissions ?? LEGACY[user.role] ?? []
}

export function hasPermission(user: User | null | undefined, permission: string): boolean {
  return permissionsOf(user).includes(permission)
}

export function hasAny(user: User | null | undefined, permissions: string[]): boolean {
  const held = permissionsOf(user)

  return permissions.some((p) => held.includes(p))
}
