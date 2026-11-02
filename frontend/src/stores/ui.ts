import { create } from 'zustand'
import { APPEARANCE_KEY, applyAppearance, storedAppearance, type Appearance } from '@/lib/appearance'

export { applyAppearance, type Appearance }

export interface Toast {
  id: number
  message: string
  variant: 'success' | 'error' | 'info'
}

interface UiState {
  sidebarOpen: boolean
  /** A screen that is a task of its own (the lead, the work queue on a phone): no app bar, no tab bar, its own action bar. */
  focusMode: boolean
  appearance: Appearance
  toasts: Toast[]
  setSidebarOpen: (open: boolean) => void
  setFocusMode: (on: boolean) => void
  toggleSidebar: () => void
  setAppearance: (appearance: Appearance) => void
  toast: (message: string, variant?: Toast['variant']) => void
  dismissToast: (id: number) => void
}

let nextToastId = 1

export const useUi = create<UiState>((set) => ({
  sidebarOpen: false,
  focusMode: false,
  appearance: storedAppearance(),
  toasts: [],

  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
  setFocusMode: (focusMode) => set({ focusMode }),
  toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),

  setAppearance: (appearance) => {
    applyAppearance(appearance)

    try {
      localStorage.setItem(APPEARANCE_KEY, appearance)
    } catch {
      // The choice still applies to this session; it just will not be
      // remembered, which is better than refusing to switch.
    }

    set({ appearance })
  },

  toast: (message, variant = 'info') => {
    const id = nextToastId++

    set((state) => ({ toasts: [...state.toasts, { id, message, variant }] }))

    setTimeout(() => {
      set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) }))
    }, 5000)
  },

  dismissToast: (id) => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
}))
