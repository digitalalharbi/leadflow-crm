import { create } from 'zustand'

export interface Toast {
  id: number
  message: string
  variant: 'success' | 'error' | 'info'
}

/** 'system' follows the device; the other two are a deliberate choice. */
export type Appearance = 'system' | 'light' | 'dark'

interface UiState {
  sidebarOpen: boolean
  appearance: Appearance
  toasts: Toast[]
  setSidebarOpen: (open: boolean) => void
  toggleSidebar: () => void
  setAppearance: (appearance: Appearance) => void
  toast: (message: string, variant?: Toast['variant']) => void
  dismissToast: (id: number) => void
}

let nextToastId = 1

const APPEARANCE_KEY = 'leadflow.appearance'

/*
 * Light, not system.
 *
 * The product has a look now, and it is a light one — white cards on a shaded
 * canvas, which is what the whole theme is built around. Following the device
 * meant half the people who opened it for the first time saw the dark variant,
 * which is a fallback rather than the design. Anybody who prefers dark still
 * chooses it, and the choice sticks.
 */
const DEFAULT_APPEARANCE: Appearance = 'light'

function storedAppearance(): Appearance {
  try {
    const value = localStorage.getItem(APPEARANCE_KEY)

    return value === 'light' || value === 'dark' || value === 'system' ? value : DEFAULT_APPEARANCE
  } catch {
    // Private browsing and blocked storage both throw here; following the
    // device is a perfectly good answer when we cannot remember a choice.
    return DEFAULT_APPEARANCE
  }
}

/**
 * Applies the appearance to the document.
 *
 * Kept outside React so the very first paint is already correct — a flash of
 * white before the dark theme loads is the one thing people notice about a
 * dark mode.
 */
export function applyAppearance(appearance: Appearance): void {
  const prefersDark = globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
  const dark = appearance === 'dark' || (appearance === 'system' && prefersDark)

  document.documentElement.classList.toggle('dark', dark)
  // The brand kit's own dark switch (mohtam-colors.css), kept in step with the class Tailwind reads.
  if (dark) document.documentElement.setAttribute('data-theme', 'dark')
  else document.documentElement.removeAttribute('data-theme')
}

export const useUi = create<UiState>((set) => ({
  sidebarOpen: false,
  appearance: storedAppearance(),
  toasts: [],

  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
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
