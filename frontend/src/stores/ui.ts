import { create } from 'zustand'

export interface Toast {
  id: number
  message: string
  variant: 'success' | 'error' | 'info'
}

interface UiState {
  sidebarOpen: boolean
  toasts: Toast[]
  setSidebarOpen: (open: boolean) => void
  toggleSidebar: () => void
  toast: (message: string, variant?: Toast['variant']) => void
  dismissToast: (id: number) => void
}

let nextToastId = 1

export const useUi = create<UiState>((set) => ({
  sidebarOpen: false,
  toasts: [],

  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
  toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),

  toast: (message, variant = 'info') => {
    const id = nextToastId++

    set((state) => ({ toasts: [...state.toasts, { id, message, variant }] }))

    setTimeout(() => {
      set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) }))
    }, 5000)
  },

  dismissToast: (id) => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
}))
