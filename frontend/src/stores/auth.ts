import { create } from 'zustand'
import { apiGet, apiPost, tokenStorage } from '@/lib/api'
import type { Branding, User } from '@/types/api'

interface LoginResponse {
  token: string
  user: User
  branding: Branding | null
  is_super_admin: boolean
  workspace_language: string | null
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
  /** null until the first /me call settles, so the router can hold the redirect. */
  status: 'idle' | 'loading' | 'authenticated' | 'anonymous'
  login: (credentials: { email: string; password: string; tenant?: string }) => Promise<void>
  logout: () => Promise<void>
  restore: () => Promise<void>
  setBranding: (branding: Branding) => void
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
  status: 'idle',

  login: async (credentials) => {
    const data = await apiPost<LoginResponse>('/auth/login', credentials)

    tokenStorage.set(data.token)

    set({
      user: data.user,
      branding: data.branding,
      isSuperAdmin: data.is_super_admin,
      workspaceLanguage: data.workspace_language,
      status: 'authenticated',
    })
  },

  logout: async () => {
    try {
      await apiPost('/auth/logout')
    } catch {
      // Even if the call fails the local session must end.
    }

    tokenStorage.clear()
    set({
      user: null, branding: null,
      isSuperAdmin: false, demoMode: false, workspaceLanguage: null, status: 'anonymous',
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
      const data = await apiGet<{
        user: User
        branding: Branding | null
        is_super_admin: boolean
        workspace_language: string | null
        demo_mode: boolean
      }>('/auth/me')

      set({
        user: data.user,
        branding: data.branding,
        isSuperAdmin: data.is_super_admin,
        workspaceLanguage: data.workspace_language,
        demoMode: data.demo_mode,
        status: 'authenticated',
      })
    } catch {
      tokenStorage.clear()
      set({ user: null, branding: null, status: 'anonymous' })
    }
  },

  setBranding: (branding) => set({ branding }),
  setUser: (user) => set({ user }),
  reset: () => {
    tokenStorage.clear()
    set({
      user: null, branding: null,
      isSuperAdmin: false, demoMode: false, workspaceLanguage: null, status: 'anonymous',
    })
  },
}))

/** Role helpers used across the UI to hide what a user cannot do. */
export const can = {
  manageTenant: (user: User | null) => user?.role === 'admin',
  seeAllLeads: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  assignLeads: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageProjects: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageUsers: (user: User | null) => user?.role === 'admin',
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
  viewReports: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageIntegrations: (user: User | null) => user?.role === 'admin',
}
