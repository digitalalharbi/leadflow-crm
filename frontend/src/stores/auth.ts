import { create } from 'zustand'
import { apiGet, apiPost, tokenStorage } from '@/lib/api'
import type { Branding, OnboardingState, User } from '@/types/api'

interface LoginResponse {
  token: string
  user: User
  branding: Branding | null
  is_super_admin: boolean
}

interface AuthState {
  user: User | null
  branding: Branding | null
  onboarding: OnboardingState | null
  isSuperAdmin: boolean
  /** null until the first /me call settles, so the router can hold the redirect. */
  status: 'idle' | 'loading' | 'authenticated' | 'anonymous'
  login: (credentials: { email: string; password: string; tenant?: string }) => Promise<void>
  logout: () => Promise<void>
  restore: () => Promise<void>
  setBranding: (branding: Branding) => void
  setOnboarding: (onboarding: OnboardingState) => void
  reset: () => void
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  branding: null,
  onboarding: null,
  isSuperAdmin: false,
  status: 'idle',

  login: async (credentials) => {
    const data = await apiPost<LoginResponse>('/auth/login', credentials)

    tokenStorage.set(data.token)

    set({
      user: data.user,
      branding: data.branding,
      isSuperAdmin: data.is_super_admin,
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
    set({ user: null, branding: null, onboarding: null, isSuperAdmin: false, status: 'anonymous' })
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
        onboarding: OnboardingState | null
      }>('/auth/me')

      set({
        user: data.user,
        branding: data.branding,
        onboarding: data.onboarding,
        isSuperAdmin: data.is_super_admin,
        status: 'authenticated',
      })
    } catch {
      tokenStorage.clear()
      set({ user: null, branding: null, status: 'anonymous' })
    }
  },

  setBranding: (branding) => set({ branding }),
  setOnboarding: (onboarding) => set({ onboarding }),
  reset: () => {
    tokenStorage.clear()
    set({ user: null, branding: null, onboarding: null, isSuperAdmin: false, status: 'anonymous' })
  },
}))

/** Role helpers used across the UI to hide what a user cannot do. */
export const can = {
  manageTenant: (user: User | null) => user?.role === 'admin',
  seeAllLeads: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  assignLeads: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageProjects: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageUsers: (user: User | null) => user?.role === 'admin',
  viewReports: (user: User | null) => user?.role === 'admin' || user?.role === 'manager',
  manageIntegrations: (user: User | null) => user?.role === 'admin',
}
