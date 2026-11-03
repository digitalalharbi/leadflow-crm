import { create } from 'zustand'
import { apiGet, apiPost, tokenStorage } from '@/lib/api'
import { disconnectEcho } from '@/lib/echo'
import { hasAny, hasPermission, sees } from '@/lib/permissions'
import type { Branding, Feature, Subscription, User } from '@/types/api'

interface Access {
  permissions: string[]
  hidden_data: NonNullable<User['hidden_data']>
  hidden_elements: string[]
  access_role: string | null
  project_scope: string[] | null
}

/** The session's access travels beside the user in the response; the rest of the app reads it off the user. */
function withAccess(user: User, access: Partial<Access> & { home?: string }): User {
  return { ...user, home: access.home ?? user.home, permissions: access.permissions ?? user.permissions, hidden_data: access.hidden_data ?? user.hidden_data, hidden_elements: access.hidden_elements ?? user.hidden_elements, access_role: access.access_role === undefined ? user.access_role : access.access_role, project_scope: access.project_scope === undefined ? user.project_scope : access.project_scope }
}

interface LoginResponse extends Partial<Access> {
  home?: string
  token: string
  user: User
  branding: Branding | null
  is_super_admin: boolean
  workspace_language: string | null
  subscription?: Subscription | null
}

interface AuthState {
  user: User | null
  branding: Branding | null
  isSuperAdmin: boolean
  /** True outside production: enables the sales-demo helpers. */
  demoMode: boolean
  /**
   * The language this workspace itself works in, read off its default country
   * code. The interface uses it to stay quiet: a Riyadh team does not need
   * every lead labelled "Arabic", and the one labelled "Urdu" is worth seeing.
   */
  workspaceLanguage: string | null
  /** The plan, its features and the lifecycle; null for the platform owner. */
  subscription: Subscription | null
  /** Set while the platform owner works inside this workspace as support. */
  impersonation: { reason: string; expires_at: string } | null
  /** null until the first /me call settles, so the router can hold the redirect. */
  status: 'idle' | 'loading' | 'authenticated' | 'anonymous'
  /** Resolves `mfa` when the account has a second factor and no (valid) code was sent yet. */
  login: (credentials: { email: string; password: string; tenant?: string; code?: string; remember?: boolean }) => Promise<'ok' | 'mfa' | 'platform'>
  /** Back from Google with the server's one-time ticket. */
  loginGoogle: (credentials: { ticket: string; code?: string; remember?: boolean }) => Promise<'ok' | 'mfa'>
  /** The platform owner's own door. Resolves `mfa` when a code is still needed. */
  loginPlatform: (credentials: { email: string; password: string; code?: string }) => Promise<'ok' | 'mfa'>
  logout: () => Promise<void>
  restore: () => Promise<void>
  setBranding: (branding: Branding) => void
  /** The server answered 402: the workspace is closed, whatever the session said when it was loaded. */
  closeSubscription: (state: Subscription['state']) => void
  /** For the few things a person changes about themselves without reloading. */
  setUser: (user: User) => void
  reset: () => void
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  branding: null,
  isSuperAdmin: false,
  demoMode: false,
  workspaceLanguage: null,
  subscription: null,
  impersonation: null,
  status: 'idle',

  login: async (credentials) => {
    const data = await apiPost<LoginResponse | { mfa_required: true } | { platform: true }>('/auth/login', credentials)

    if ('platform' in data) return 'platform'
    if ('mfa_required' in data) return 'mfa'

    tokenStorage.set(data.token)

    set({
      user: withAccess(data.user, data),
      branding: data.branding,
      isSuperAdmin: data.is_super_admin,
      workspaceLanguage: data.workspace_language,
      subscription: data.subscription ?? null,
      status: 'authenticated',
    })

    return 'ok'
  },
  loginGoogle: async (credentials) => {
    const data = await apiPost<LoginResponse | { mfa_required: true }>('/auth/google/complete', credentials)

    if ('mfa_required' in data) return 'mfa'

    tokenStorage.set(data.token)

    set({
      user: withAccess(data.user, data),
      branding: data.branding,
      isSuperAdmin: data.is_super_admin,
      workspaceLanguage: data.workspace_language,
      subscription: data.subscription ?? null,
      status: 'authenticated',
    })

    return 'ok'
  },

  loginPlatform: async (credentials) => {
    const data = await apiPost<{ token?: string; mfa_required?: boolean; user?: { id: string; name: string; email: string } }>('/platform/auth/login', credentials)

    if (data.mfa_required || !data.token) return 'mfa'

    tokenStorage.set(data.token)
    // The console's session: no workspace, no plan. /auth/me fills in the rest on the next restore.
    set({
      user: { id: data.user?.id ?? '', name: data.user?.name ?? '', email: data.user?.email ?? '', role: 'super_admin', phone: null, avatar_url: null, is_active: true } as User,
      branding: null,
      isSuperAdmin: true,
      subscription: null,
      impersonation: null,
      status: 'authenticated',
    })

    return 'ok'
  },

  logout: async () => {
    try {
      await apiPost('/auth/logout')
    } catch {
      // Even if the call fails the local session must end.
    }

    // The socket belongs to the session that opened it: a live feed left
    // running past a sign-out keeps a private channel subscribed on a device
    // whose user has gone.
    disconnectEcho()

    tokenStorage.clear()
    set({
      user: null, branding: null,
      isSuperAdmin: false, demoMode: false, workspaceLanguage: null, subscription: null, impersonation: null, status: 'anonymous',
    })
  },

  /** Re-establishes the session on a page refresh. */
  restore: async () => {
    if (!tokenStorage.get()) {
      set({ status: 'anonymous' })
      return
    }

    set({ status: 'loading' })

    try {
      const data = await apiGet<Partial<Access> & {
        user: User
        branding: Branding | null
        is_super_admin: boolean
        workspace_language: string | null
        demo_mode: boolean
        subscription?: Subscription | null
        impersonation?: { reason: string; expires_at: string } | null
      }>('/auth/me')

      set({
        user: withAccess(data.user, data),
        branding: data.branding,
        isSuperAdmin: data.is_super_admin,
        workspaceLanguage: data.workspace_language,
        demoMode: data.demo_mode,
        subscription: data.subscription ?? null,
        impersonation: data.impersonation ?? null,
        status: 'authenticated',
      })
    } catch {
      tokenStorage.clear()
      set({ user: null, branding: null, status: 'anonymous' })
    }
  },

  setBranding: (branding) => set({ branding }),
  closeSubscription: (state) => set((current) => (current.subscription ? { subscription: { ...current.subscription, state, usable: false } } : {})),
  setUser: (user) => set({ user }),
  reset: () => {
    // The socket belongs to the session that opened it: a live feed left
    // running past a sign-out keeps a private channel subscribed on a device
    // whose user has gone.
    disconnectEcho()

    tokenStorage.clear()
    set({
      user: null, branding: null,
      isSuperAdmin: false, demoMode: false, workspaceLanguage: null, subscription: null, impersonation: null, status: 'anonymous',
    })
  },
}))

/** Role helpers used across the UI to hide what a user cannot do. */
export const can = {
  /** Any permission by name — `can.has(user, 'campaigns.edit')`. The helpers below are the common ones. */
  has: hasPermission,
  any: hasAny,
  /** A piece of the interface the workspace shows this person — `can.see(user, 'dashboard.kpi.revenue')`. */
  see: sees,
  manageTenant: (user: User | null) => hasPermission(user, 'settings.edit'),
  /** Reads leads beyond their own (everyone but a sales rep; project scope narrows the rest on the server). */
  seeAllLeads: (user: User | null) => (user?.permissions ? user.role !== 'sales' && hasPermission(user, 'leads.view') : user?.role === 'admin' || user?.role === 'manager'),
  assignLeads: (user: User | null) => hasPermission(user, 'leads.execute') && user?.role !== 'sales',
  manageProjects: (user: User | null) => hasPermission(user, 'projects.edit'),
  manageUsers: (user: User | null) => hasPermission(user, 'users.edit'),
  /*
   * May say how somebody's share of the leads is worked out — their cities,
   * their languages, their weight. A narrower authority than editing the
   * account, and a different one: running the team is a manager's job, while
   * changing an account is an admin's.
   *
   * A manager may set their team's and never their own; nobody awards
   * themselves a bigger share. Mirrors UserPolicy::routeWork.
   */
  routeWork: (user: User | null, target: User) =>
    user?.role === 'admin' || (user?.role === 'manager' && user.id !== target.id),
  viewReports: (user: User | null) => hasPermission(user, 'analytics.view'),
  manageIntegrations: (user: User | null) => hasPermission(user, 'integrations.edit'),
}

/**
 * Whether this workspace's plan includes a feature. The platform owner has no plan and no workspace features; a session
 * loaded before plans existed (no subscription block) is treated as having none — the server decides either way.
 */
export function entitled(subscription: Subscription | null, feature: Feature): boolean {
  return subscription?.features.includes(feature) ?? false
}

export function useEntitled(feature: Feature): boolean {
  return useAuth((s) => entitled(s.subscription, feature))
}
